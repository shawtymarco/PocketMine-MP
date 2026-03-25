<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use function count;
use function implode;
use function strtolower;

/** [BETTERPMMP-PATCH] */
class ReloadPluginCommand extends VanillaCommand{

	private static string $lastPluginName = '';

	public function __construct(){
		parent::__construct(
			"reload",
			"Reload a plugin or all plugins",
			"/reload <all|pluginName>"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_RELOAD);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if($sender instanceof Player && !$sender->getServer()->isOp($sender->getName())){
			$sender->sendMessage('§cYou don\'t have permission to reload.');
			return true;
		}

		$pluginName = count($args) > 0 ? implode(" ", $args) : self::$lastPluginName;

		if($pluginName === ''){
			$sender->sendMessage('§cUsage: /reload <all|pluginName>');
			return true;
		}

		if(strtolower($pluginName) === 'all'){
			$pluginManager = $sender->getServer()->getPluginManager();
			$total = count($pluginManager->getPlugins());

			$sender->sendMessage("§eReloading all §f{$total}§e plugins (two-phase)...");

			try{
				$results = $pluginManager->reloadAll();
			}catch(\Throwable $e){
				$sender->getServer()->getLogger()->logException($e);
				$sender->sendMessage("§cCritical error during reload all.");
				return true;
			}

			$success = 0;
			$failed = [];
			foreach($results as $name => $ok){
				if($ok){
					$success++;
				}else{
					$failed[] = $name;
				}
			}

			if(count($failed) > 0){
				$sender->sendMessage("§cFailed/skipped: §f" . implode("§c, §f", $failed));
			}
			$sender->sendMessage("§aReloaded §f{$success}§a/§f{$total}§a plugins.");
			return true;
		}

		$plugin = $sender->getServer()->getPluginManager()->getPlugin($pluginName);

		if($plugin === null){
			$sender->sendMessage('§cCan\'t find plugin.');
			return true;
		}

		self::$lastPluginName = $pluginName;

		try{
			$result = $sender->getServer()->getPluginManager()->reloadPlugin($plugin);
		}catch(\Throwable $e){
			$sender->getServer()->getLogger()->logException($e);
			$result = false;
		}

		if($result){
			$sender->sendMessage('§aPlugin reloaded successfully.');
		}else{
			$sender->sendMessage('§cFailed to reload plugin.');
		}

		return true;
	}

}