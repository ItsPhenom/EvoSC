<?php

namespace esc\Modules;


use esc\Classes\ChatCommand;
use esc\Classes\Hook;
use esc\Classes\Log;
use esc\Classes\ManiaLinkEvent;
use esc\Classes\Template;
use esc\Classes\Timer;
use esc\Controllers\MapController;
use esc\Models\AccessRight;
use esc\Models\Player;
use Illuminate\Support\Collection;

class Votes
{
    /**
     * @var Collection
     */
    private static $vote;

    /**
     * @var Collection
     */
    private static $voters;

    /**
     * @var \Carbon\Carbon
     */
    private static $lastVote;

    private static $timeVotesThisRound;
    private static $voteLimit;

    public function __construct()
    {
        self::$voters = collect();
        self::$lastVote = now();
        self::$lastVote->subSeconds(config('votes.cooldown'));
        self::$timeVotesThisRound = 0;
        self::$voteLimit          = config('votes.vote-limit');

        if (!self::$voteLimit) {
            self::$voteLimit = 1;
            Log::error('Failed to get config "votes.vote-limit". Setting limit to 1.');
        }

        AccessRight::createIfNonExistent('vote_custom', 'Create a custom vote. Enter question after command.');

        ChatCommand::add('//vote', [self::class, 'startVoteQuestion'], 'Start a custom vote.', 'vote_custom');
        ChatCommand::add('/skip', [self::class, 'askSkip'], 'Start a vote to skip map.');
        ChatCommand::add('/y', [self::class, 'voteYes'], 'Vote yes.');
        ChatCommand::add('/n', [self::class, 'voteNo'], 'Vote no.');
        ChatCommand::add('/time', [self::class, 'askMoreTime'], 'Start a vote to add 10 minutes.')
            ->addAlias('/replay')
            ->addAlias('/restart')
            ->addAlias('/res');

        Hook::add('EndMatch', [self::class, 'endMatch']);
        Hook::add('BeginMatch', [self::class, 'beginMatch']);

        KeyBinds::add('vote_yes', 'Vote yes in a vote.', [self::class, 'voteYes'], 'F5');
        KeyBinds::add('vote_no', 'Vote no in a vote.', [self::class, 'voteNo'], 'F6');

        ManiaLinkEvent::add('votes.yes', [self::class, 'voteYes']);
        ManiaLinkEvent::add('votes.no', [self::class, 'voteNo']);

        if (config('quick-buttons.enabled')) {
            ManiaLinkEvent::add('vote.approve', [self::class, 'approveVote'], 'vote_decide');
            ManiaLinkEvent::add('vote.decline', [self::class, 'declineVote'], 'vote_decide');
            QuickButtons::addButton('', 'Approve vote', 'vote.approve', 'vote_decide');
            QuickButtons::addButton('', 'Decline vote', 'vote.decline', 'vote_decide');
        }
    }

    public static function startVote(Player $player, string $question, $action)
    {
        if (self::$vote != null) {
            warningMessage('There is already a vot ein progress.')->send($player);

            return;
        }

        self::$vote = collect([
            'question'   => $question,
            'start_time' => now(),
            'duration'   => config('votes.duration'),
            'action'     => $action,
        ]);

        Timer::create('vote.check_state', [self::class, 'checkVoteState'], '1s', true);

        $voteStateJson = '{"yes":0,"no":0}';
        Template::showAll('votes.update-vote', compact('voteStateJson'));

        Template::showAll('votes.vote', compact('question'));
    }

    public static function checkVoteState()
    {
        if (!self::$vote) {
            return;
        }

        if (now()->diffInSeconds(self::$vote['start_time']) > self::$vote['duration']) {
            Timer::destroy('vote.check_state');
            $action = self::$vote['action'];
            $voteState = self::getVoteState();
            $action($voteState['yes'] > $voteState['no']);
            self::$vote = null;
            self::$voters = collect();
            $voteStateJson = '{"yes":-1,"no":-1}';
            Template::showAll('votes.update-vote', compact('voteStateJson'));
        }
    }

    public static function askMoreTime(Player $player)
    {
        // $mapStartDiff = MapController::getMapStart()->diffInSeconds();
        // $waitTime     = MapController::getTimeLimit() - 180;
        // if ($mapStartDiff < $waitTime) {
        //     warningMessage('Please wait ', secondary(($waitTime - $mapStartDiff) . ' seconds'), ' before asking for more time.')->send($player);
        //
        //     return;
        // }

        if (self::$timeVotesThisRound >= self::$voteLimit) {
            warningMessage('The maximum timelimit is already rechaded, sorry.')->send($player);

            return;
        }

        $diffInSeconds = self::$lastVote->diffInSeconds();
        if ($diffInSeconds < config('votes.cooldown') && !$player->hasAccess('vote_always')) {
            $waitTime = config('votes.cooldown') - $diffInSeconds;
            warningMessage('There already was a vote recently, please ', secondary('wait ' . $waitTime . ' seconds'),
                ' before voting again.')->send($player);

            return;
        }

        $secondsToAdd = MapController::getOriginalTimeLimit() * config('votes.time-multiplier');
        $question = 'Add ' . round($secondsToAdd / 60, 1) . ' minutes?';
        self::startVote($player, $question, function ($success) use ($secondsToAdd, $question) {
            if ($success) {
                infoMessage('Vote ', secondary($question), ' was successful.')->sendAll();
                MapController::addTime($secondsToAdd);
                self::$timeVotesThisRound++;
            } else {
                infoMessage('Vote ', secondary($question), ' did not pass.')->sendAll();
            }
        });

        self::$lastVote = now();

        infoMessage($player, ' started a vote to ', secondary('add 10 minutes?'), '. Use ', secondary('F5/F6'), ' and ',
            secondary('/y'), ' or ', secondary('/n'), ' to vote.')->sendAll();
    }

    public static function startVoteQuestion(Player $player, string $cmd, ...$questionArray)
    {
        $question = implode(' ', $questionArray);

        self::startVote($player, $question, function (bool $success) use ($question) {
            infoMessage('Vote ', secondary($question), ' ended with ', secondary($success ? 'yes' : 'no'))->sendAll();
        });
    }

    public static function askSkip(Player $player)
    {
        $mapStartDiff = MapController::getMapStart()
            ->diffInSeconds();
        if ($mapStartDiff < 60) {
            warningMessage('Please wait ', secondary((60 - $mapStartDiff) . ' seconds'),
                ' before asking to skip the map.')->send($player);

            return;
        }

        $diffInSeconds = self::$lastVote->diffInSeconds();
        if ($diffInSeconds < config('votes.cooldown') && !$player->hasAccess('vote_always')) {
            $waitTime = config('votes.cooldown') - $diffInSeconds;
            warningMessage('There already was a vote recently, please ', secondary('wait ' . $waitTime . ' seconds'),
                ' before voting again.')->send($player);

            return;
        }

        self::startVote($player, 'Skip map?', function (bool $success) {
            if ($success) {
                infoMessage('Vote to skip map was successful.')->sendAll();
                MapController::skip();
            } else {
                infoMessage('Vote to skip map was not successful.')->sendAll();
            }
        });

        self::$lastVote = now();

        infoMessage($player, ' started a vote to ', secondary('skip the map'), '. Use ', secondary('F5/F6'), ' and ',
            secondary('/y'), ' or ', secondary('/n'), ' to vote.')->sendAll();
    }

    private static function getVoteState(): Collection
    {
        $yesVotes = self::$voters->filter(function ($vote) {
            return $vote == true;
        })
            ->count();

        $noVotes = self::$voters->filter(function ($vote) {
            return $vote == false;
        })
            ->count();

        return collect([
            'yes' => $yesVotes,
            'no'  => $noVotes,
        ]);
    }

    private static function updateVoteState()
    {
        $voteStateJson = self::getVoteState()
            ->toJson();

        Template::showAll('votes.update-vote', compact('voteStateJson'));
    }

    public static function voteYes(Player $player)
    {
        self::$voters->put($player->Login, true);
        self::updateVoteState();
    }

    public static function voteNo(Player $player)
    {
        self::$voters->put($player->Login, false);
        self::updateVoteState();
    }

    public static function approveVote(Player $player)
    {
        Timer::destroy('vote.check_state');
        $action = self::$vote['action'];

        try {
            $action(true);
        } catch (\Error $e) {
            Log::logAddLine('Votes', $e->getMessage());
        }

        self::$vote = null;
        self::$voters = collect();
        infoMessage($player, ' passes vote.')->sendAll();
        $voteStateJson = '{"yes":-1,"no":-1}';
        Template::showAll('votes.update-vote', compact('voteStateJson'));
    }

    public static function declineVote(Player $player)
    {
        Timer::destroy('vote.check_state');
        $action = self::$vote['action'];

        try {
            $action(false);
        } catch (\Error $e) {
            Log::logAddLine('Votes', $e->getMessage());
        }

        self::$vote = null;
        self::$voters = collect();
        infoMessage($player, ' cancels vote.')->sendAll();
        $voteStateJson = '{"yes":-1,"no":-1}';
        Template::showAll('votes.update-vote', compact('voteStateJson'));
    }

    public static function endMatch()
    {
        if (self::$vote != null) {
            Timer::destroy('vote.check_state');
            $action = self::$vote['action'];

            try {
                $action(false);
            } catch (\Error $e) {
                Log::logAddLine('Votes', $e->getMessage());
            }

            self::$vote = null;
            self::$voters = collect();
            $voteStateJson = '{"yes":-1,"no":-1}';
            Template::showAll('votes.update-vote', compact('voteStateJson'));
            infoMessage('Vote cancelled.')->sendAll();
        }
    }

    public static function beginMatch()
    {
        self::$timeVotesThisRound = 0;
    }
}