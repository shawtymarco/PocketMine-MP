<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\command\utils\InvalidCommandSyntaxException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\utils\TextFormat;
use function count;
use function filter_var;
use const FILTER_VALIDATE_INT;

/** Controls the built-in bounded per-tick profiler. */
final class PerfDebugCommand extends VanillaCommand{
	public function __construct(){
		parent::__construct(
			"perfdebug",
			"Toggle bounded per-tick server performance diagnostics",
			"/perfdebug [seconds|0]"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_PERFDEBUG);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(count($args) > 1){
			throw new InvalidCommandSyntaxException();
		}

		$manager = $sender->getServer()->getPerformanceDebugManager();
		if(count($args) === 0){
			if($manager->isEnabled()){
				$manager->disable("toggle:" . $sender->getName());
				$sender->sendMessage(TextFormat::YELLOW . "Per-tick performance logging disabled.");
				return true;
			}
			$seconds = 15;
		}else{
			$parsed = filter_var($args[0], FILTER_VALIDATE_INT);
			if($parsed === false || $parsed < 0 || $parsed > 120){
				$sender->sendMessage(TextFormat::RED . "Duration must be between 1 and 120 seconds, or 0 to stop.");
				return true;
			}
			$seconds = $parsed;
		}

		if($seconds === 0){
			$stopped = $manager->disable("command:" . $sender->getName());
			$sender->sendMessage($stopped
				? TextFormat::YELLOW . "Per-tick performance logging disabled."
				: TextFormat::GRAY . "Per-tick performance logging is already disabled.");
			return true;
		}

		$manager->enable($seconds, $sender->getName());
		$sender->sendMessage(TextFormat::GREEN . "Per-tick performance logging enabled for " . $seconds . " seconds. Check the server console.");
		$sender->sendMessage(TextFormat::GRAY . "Run /perfdebug again or /perfdebug 0 to stop early.");
		return true;
	}
}
