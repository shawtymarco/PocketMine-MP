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

namespace pocketmine\network\mcpe;

use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;
use function spl_object_id;

/**
 * Collects approved melee animation and sound feedback for latency-prioritized delivery.
 */
final class CombatFeedback{

	/** @phpstan-var array<int, Player> */
	private array $priorityRecipients = [];
	/** @phpstan-var array<int, list<ClientboundPacket>> */
	private array $packetsByRecipient = [];

	public function __construct(
		Player $attacker,
		Entity $target
	){
		$this->priorityRecipients[spl_object_id($attacker)] = $attacker;
		if($target instanceof Player){
			$this->priorityRecipients[spl_object_id($target)] = $target;
		}
	}

	/**
	 * Removes priority recipients from a normal broadcast and records their packets for one immediate batch.
	 *
	 * @param Player[]            $recipients
	 * @param ClientboundPacket[] $packets
	 * @return Player[]
	 * @phpstan-param array<int, Player> $recipients
	 * @phpstan-param array<int, ClientboundPacket> $packets
	 * @phpstan-return array<int, Player>
	 */
	public function capturePackets(array $recipients, array $packets) : array{
		foreach($recipients as $key => $recipient){
			$id = spl_object_id($recipient);
			if(($this->priorityRecipients[$id] ?? null) === $recipient){
				foreach($packets as $packet){
					$this->packetsByRecipient[$id][] = $packet;
				}
				unset($recipients[$key]);
			}
		}
		return $recipients;
	}

	/** Sends each priority recipient's collected feedback as one queue-bypassing batch. */
	public function sendImmediately() : void{
		foreach($this->priorityRecipients as $id => $recipient){
			if(isset($this->packetsByRecipient[$id])){
				$recipient->getNetworkSession()->sendCombatPacketBatch($this->packetsByRecipient[$id]);
			}
		}
		$this->clear();
	}

	/** Restores normal buffered delivery when fast-lane reordering would violate entity lifecycle ordering. */
	public function releaseNormally() : void{
		foreach($this->priorityRecipients as $id => $recipient){
			if(isset($this->packetsByRecipient[$id])){
				NetworkBroadcastUtils::broadcastPackets([$recipient], $this->packetsByRecipient[$id]);
			}
		}
		$this->clear();
	}

	private function clear() : void{
		$this->packetsByRecipient = [];
	}
}
