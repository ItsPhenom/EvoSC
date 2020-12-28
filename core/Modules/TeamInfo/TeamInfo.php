<?php


namespace EvoSC\Modules\TeamInfo;


use EvoSC\Classes\Hook;
use EvoSC\Classes\Module;
use EvoSC\Classes\Template;
use EvoSC\Controllers\ModeController;
use EvoSC\Interfaces\ModuleInterface;
use EvoSC\Models\Player;

class TeamInfo extends Module implements ModuleInterface
{
    public static function start(string $mode, bool $isBoot = false)
    {
        if (ModeController::teams()) {
            Hook::add('PlayerConnect', [self::class, 'showWidget']);
        }
    }

    public static function showWidget(Player $player)
    {
        Template::show($player, 'TeamInfo.widget');
    }
}