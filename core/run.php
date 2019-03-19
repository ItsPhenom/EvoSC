<?php

require 'autoload.php';
require 'global-functions.php';

use esc\Classes\Log;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EscRun extends Command
{
    protected function configure()
    {
        $this->setName('run')
             ->setDescription('Run Evo Server Controller');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        global $escVersion;
        global $serverName;

        $escVersion = '0.59.*';

        esc\Classes\Config::loadConfigFiles();

        //Check that cache directory exists
        if (!is_dir(cacheDir())) {
            mkdir(cacheDir());
        }

        //Check that logs directory exists
        if (!is_dir(logDir())) {
            mkdir(logDir());
        }

        try {
            $output->writeln("Connecting to server...");

            esc\Classes\Server::init(
                config('server.ip'),
                config('server.port'),
                5,
                config('server.rpc.login'),
                config('server.rpc.password')
            );

            $serverName = \esc\Classes\Server::getServerName();

            if (!\esc\Classes\Server::isAutoSaveValidationReplaysEnabled()) {
                \esc\Classes\Server::autoSaveValidationReplays(true);
            }
            if (!\esc\Classes\Server::isAutoSaveReplaysEnabled()) {
                \esc\Classes\Server::autoSaveReplays(true);
            }

            //Disable all default ManiaPlanet votes
            /*
            $voteRatio = new \Maniaplanet\DedicatedServer\Structures\VoteRatio(\Maniaplanet\DedicatedServer\Structures\VoteRatio::COMMAND_DEFAULT, -1.0);
            \esc\Classes\Server::setCallVoteRatios([$voteRatio]);
            */
            \esc\Classes\Server::setCallVoteTimeOut(0);

            $output->writeln("Connection established.");
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $output->writeln("<error>$msg</error>");
            exit(1);
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output)
    {
        file_put_contents(baseDir(config('server.login') . '_evosc.pid'), getmypid());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $_isVerbose;
        global $_isVeryVerbose;
        global $_isDebug;

        if ($output->isVerbose()) {
            $_isVerbose = true;
        }
        if ($output->isVeryVerbose()) {
            $_isVeryVerbose = true;
        }
        if ($output->isDebug()) {
            $_isDebug = true;
        }

        \esc\Classes\Log::setOutput($output);

        $version = getEscVersion();
        $motd    = "      ______           _____ ______
     / ____/  _______ / ___// ____/
    / __/| | / / __ \\__ \/ /     
   / /___| |/ / /_/ /__/ / /___   
  /_____/|___/\____/____/\____/  $version
";

        $output->writeln("<fg=cyan;options=bold>$motd</>");

        esc\Classes\Log::info("Starting...");

        \esc\Classes\Timer::setInterval(config('server.controller-interval') ?? 200);

        esc\Classes\Database::init();
        esc\Classes\RestClient::init(serverName());
        esc\Controllers\HookController::init();
        esc\Controllers\TemplateController::init();
        esc\Controllers\ChatController::init();
        esc\Classes\ManiaLinkEvent::init();
        esc\Controllers\GroupController::init();
        esc\Controllers\AccessController::init();
        esc\Controllers\QueueController::init();
        esc\Controllers\MapController::init();
        esc\Controllers\PlayerController::init();
        esc\Controllers\AfkController::init();
        esc\Controllers\ModuleController::init();
        \esc\Controllers\PlanetsController::init();

        if (isVerbose()) {
            Log::logAddLine('BOOT', 'Booting core finished.', true);
        }

        esc\Controllers\ModuleController::bootModules();

        if (isVerbose()) {
            Log::logAddLine('BOOT', 'Booting modules finished.', true);
        }

        $map = \esc\Models\Map::where('filename', esc\Classes\Server::getCurrentMapInfo()->fileName)->first();
        esc\Classes\Hook::fire('BeginMap', $map);

        //Set connected players online
        /*
        $playerList = collect(\esc\Classes\Server::rpc()->getPlayerList());

        foreach ($playerList as $maniaPlayer) {
            $player = \esc\Models\Player::whereLogin($maniaPlayer->login)->first();

            if ($player) {
                \esc\Classes\Hook::fire('PlayerConnect', $player);
            }
        }
        */

        //Enable mode script rpc-callbacks else you wont get stuf flike checkpoints and finish
        \esc\Classes\Server::triggerModeScriptEventArray('XmlRpc.EnableCallbacks', ['true']);
        \esc\Classes\Server::disableServiceAnnounces(true);

        $failedConnectionRequests = 0;

        while (true) {
            try {
                esc\Classes\Timer::startCycle();

                try {
                    \esc\Controllers\EventController::handleCallbacks(esc\Classes\Server::executeCallbacks());
                } catch (Exception $e) {
                    Log::logAddLine('ERROR', $e->getMessage(), true);
                    Log::logAddLine('ERROR', $e->getTraceAsString(), isVerbose());
                }

                $pause = esc\Classes\Timer::getNextCyclePause();

                usleep($pause);
            } catch (\Maniaplanet\DedicatedServer\Xmlrpc\Exception $e) {
                Log::logAddLine('MPS', 'Connection problems.');
                Log::logAddLine('MPS', $e->getMessage());
                $failedConnectionRequests++;
                if ($failedConnectionRequests > 10) {
                    Log::logAddLine('MPS', sprintf('Connection failed after %d retires (%d seconds).', $failedConnectionRequests, $failedConnectionRequests * 5));

                    return;
                }
                sleep($failedConnectionRequests * 5);
            } catch (Error $e) {
                $errorClass = get_class($e);
                $output->writeln("<error>$errorClass in " . $e->getFile() . " on Line " . $e->getLine() . "</error>");
                $output->writeln("<fg=white;bg=red;options=bold>" . $e->getMessage() . "</>");
                $output->writeln("<error>===============================================================================</error>");
                $output->writeln("<error>" . $e->getTraceAsString() . "</error>");

                Log::logAddLine('CYCLE-ERROR', 'EvoSC encountered an error: ' . $e->getMessage(), false);
                Log::logAddLine('CYCLE-ERROR', $e->getTraceAsString(), false);
            }
        }
    }
}