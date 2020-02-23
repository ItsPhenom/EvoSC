<?php

namespace esc\Modules;


use esc\Classes\ManiaLinkEvent;
use esc\Classes\ChatCommand;
use esc\Interfaces\ModuleInterface;
use esc\Models\AccessRight;
use esc\Models\Player;

class Warning implements ModuleInterface
{
    public function __construct()
    {
        AccessRight::createIfMissing('warn_player', 'Warn a player.');
    }

    public static function warnPlayer(Player $player, string $targetLogin, string $message)
    {
        $target = Player::whereLogin($targetLogin)->first();

        if ($target) {
            warningMessage("You have been warned by $player: ", secondary($message))->send($target);
        }
    }

    /**
     * @inheritDoc
     */
    public static function start(string $mode, bool $isBoot = false)
    {
        ManiaLinkEvent::add('warn', [self::class, 'warnPlayer'], 'warn_player');
    }
}