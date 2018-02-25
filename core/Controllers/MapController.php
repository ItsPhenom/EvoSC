<?php

namespace esc\controllers;


use esc\classes\Config;
use esc\classes\Database;
use esc\classes\File;
use esc\classes\Hook;
use esc\classes\Log;
use esc\classes\MapQueueItem;
use esc\classes\RestClient;
use esc\classes\Server;
use esc\classes\Template;
use esc\classes\Vote;
use esc\models\Group;
use esc\models\Map;
use esc\models\Player;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maniaplanet\DedicatedServer\Xmlrpc\AlreadyInListException;
use Maniaplanet\DedicatedServer\Xmlrpc\FaultException;
use Maniaplanet\DedicatedServer\Xmlrpc\FileException;

class MapController
{
    private static $currentMap;
    private static $queue;
    private static $addedTime = 0;

    public static function init()
    {
        self::createTables();

        self::loadMaps();

        self::$queue = new Collection();

        Template::add('map', File::get('core/Templates/map.latte.xml'));

        Hook::add('PlayerConnect', '\esc\Controllers\MapController::displayMapWidget');
        Hook::add('BeginMap', '\esc\Controllers\MapController::beginMap');
        Hook::add('EndMatch', '\esc\Controllers\MapController::endMatch');

        ChatController::addCommand('skip', '\esc\Controllers\MapController::skip', 'Skips map instantly', '//', [Group::ADMIN, Group::SUPER]);
        ChatController::addCommand('add', '\esc\Controllers\MapController::addMap', 'Add a map from mx. Usage: //add \<mxid\>', '//', [Group::ADMIN, Group::SUPER]);
    }

    private static function createTables()
    {
        Database::create('maps', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('UId')->nullable();
            $table->integer('MxId')->nullable();
            $table->string('Name')->nullable();
            $table->string('Author')->nullable();
            $table->string('FileName')->unique();
            $table->integer('Plays')->default(0);
            $table->string('Mood')->nullable();
            $table->boolean('LapRace')->nullable();
            $table->dateTime('LastPlayed')->nullable();
        });
    }

    public static function addTime(int $minutes = 5)
    {
        self::$addedTime = self::$addedTime + $minutes;
        $totalNewTime = (config('server.roundTime', 7) + self::$addedTime) * 60;
        self::updateRoundtime($totalNewTime);
    }

    private static function updateRoundtime(int $timeInSeconds)
    {
        $settings = \esc\Classes\Server::getRpc()->getModeScriptSettings();
        $settings['S_TimeLimit'] = $timeInSeconds;
        \esc\Classes\Server::getRpc()->setModeScriptSettings($settings);
    }

    /**
     * Hook: EndMatch
     * @param $rankings
     * @param $winnerteam
     */
    public static function endMatch($rankings = null, $winnerteam = null)
    {
        $request = self::$queue->shift();

        if ($request) {
            Log::info("Try set next map: " . $request->map->Name);
            Server::getRpc()->chooseNextMap($request->map->FileName);
            ChatController::messageAllNew('Next map: ', $request->map, ' as requested by ', $request->issuer);
        } else {
            ChatController::messageAllNew('Next map: ', $request->map);
        }

        foreach (Player::whereOnline(true) as $player) {
            $player->setScore(0);
        }
    }

    /*
     * Hook: BeginMap
     */
    public static function beginMap(Map $map)
    {
        $map->update(Server::getCurrentMapInfo()->toArray());

        $map->increment('Plays');
        $map->update(['LastPlayed' => Carbon::now()]);

        self::$currentMap = $map;
        self::displayMapWidget();
    }

    /**
     * Gets current map
     * @return Map|null
     */
    public static function getCurrentMap(): ?Map
    {
        return self::$currentMap;
    }

    /**
     * Get all queued maps
     * @return Collection
     */
    public static function getQueue(): Collection
    {
        return self::$queue->sortBy('timeRequested');
    }

    /**
     * Delete a map
     * @param Map $map
     */
    public static function deleteMap(Map $map)
    {
//        ServerController::getRpc()->removeMap($map->FileName);
//        File::delete(Config::get('server.maps') . '/' . $map->FileName);
//        ChatController::messageAllNew('Admin removed map ', $map);
//        $map->delete();
    }

    /**
     * Ends the match and goes to the next round
     */
    public static function goToNextMap()
    {
        Server::getRpc()->nextMap();
    }

    /**
     * Gets the next played map
     * @return Map
     */
    public static function getNext(): Map
    {
        $map = self::getQueue()->first()->map;

        if (!$map) {
            $mapId = Server::getRpc()->getNextMapInfo()->uId;
            $map = Map::where('UId', $mapId)->first();
        }

        return $map;
    }

    /**
     * Admins skip method
     * @param Player $player
     */
    public static function skip(Player $player)
    {
        ChatController::messageAllNew($player->group, ' ', $player, ' skips map');
        MapController::goToNextMap();
        Vote::stopVote();
    }

    /**
     * Force replay a round at end of match
     * @param Player $player
     */
    public static function forceReplay(Player $player)
    {
        $currentMap = self::getCurrentMap();

        if (self::getQueue()->contains('map.UId', $currentMap->UId)) {
            ChatController::messageNew($player, 'Map is already being replayed');
            return;
        }

        self::$queue->push(new MapQueueItem($player, $currentMap, 0));
        ChatController::messageAllNew($player, ' queued map ', $currentMap, ' for replay');
        self::displayMapWidget();
    }

    /**
     * Add a map to the queue
     * @param Player $player
     * @param Map $map
     */
    public static function queueMap(Player $player, Map $map)
    {
        if (self::getQueue()->where('player', $player)->isNotEmpty() && !$player->isAdmin()) {
            ChatController::message($player, "You already have a map in queue", []);
            return;
        }

        self::$queue->push(new MapQueueItem($player, $map, time()));

        ChatController::messageAllNew($player, ' juked map ', $map);
        Log::info("$player->NickName juked map $map->Name");

        self::displayMapWidget();
    }

    /**
     * Loads maps from server directory
     */
    private static function loadMaps()
    {
        $mapFiles = File::getDirectoryContents(Config::get('server.maps'))->filter(function ($fileName) {
            return preg_match('/^.+.Gbx$/', $fileName);
        });

        foreach ($mapFiles as $mapFile) {
            $map = Map::where('FileName', $mapFile)->first();
            if (!$map) {
                $mapInfo = Server::getMapInfo($mapFile)->toArray();
                Map::create($mapInfo);
            }

            try {
                Server::getRpc()->addMap($mapFile);
            } catch (FileException $e) {
                Log::error("Map $mapFile not found.");
            } catch (AlreadyInListException $e) {
                Log::warning("Map $mapFile already added.");
            }
        }
    }

    /**
     * Display the map widget
     * @param Player|null $player
     */
    public static function displayMapWidget(Player $player = null)
    {
        $currentMap = self::getCurrentMap();
        $nextMap = self::getNext();

        if ($player) {
            Template::show($player, 'map', ['map' => $currentMap, 'next' => $nextMap]);
        } else {
            Template::showAll('map', ['map' => $currentMap, 'next' => $nextMap]);
        }
    }

    /**
     * Add map from MX
     * @param string[] ...$arguments
     */
    public static function addMap(string ...$arguments)
    {
        $mxIds = $arguments;

        //shift first two entries so we get list of mx ids
        array_shift($mxIds);
        array_shift($mxIds);

        foreach ($mxIds as $mxId) {
            $mxId = (int)$mxId;

            if ($mxId == 0) {
                Log::warning("Requested map with invalid id: " . $mxId);
                ChatController::messageAllNew("Requested map with invalid id: " . $mxId);
                return;
            }

            $map = Map::where('MxId', $mxId)->first();
            if ($map) {
                ChatController::messageAllNew($map, ' already exists');
                continue;
            }

            $response = RestClient::get('http://tm.mania-exchange.com/tracks/download/' . $mxId);

            if ($response->getStatusCode() != 200) {
                Log::error("ManiaExchange returned with non-success code [$response->getStatusCode()] " . $response->getReasonPhrase());
                ChatController::messageAllNew("Can not reach mania exchange.");
                return;
            }

            if ($response->getHeader('Content-Type')[0] != 'application/x-gbx') {
                Log::warning('Not a valid GBX.');
                return;
            }

            $fileName = preg_replace('/^attachment; filename="(.+)"$/', '\1', $response->getHeader('content-disposition')[0]);
            $mapFolder = Config::get('server.maps');
            File::put("$mapFolder/$fileName", $response->getBody());

            $name = str_replace('.Map.Gbx', '', $fileName);

            $map = Map::updateOrCreate([
                'MxId' => $mxId,
                'Name' => $name,
                'FileName' => $fileName
            ]);

            Server::getRpc()->addMap($map->FileName);

            ChatController::messageAllNew('New map added: ', $map);
        }
    }
}