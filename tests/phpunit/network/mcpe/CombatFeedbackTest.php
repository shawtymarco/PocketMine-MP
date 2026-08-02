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

use PHPUnit\Framework\TestCase;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;
use ReflectionMethod;
use function array_values;

final class CombatFeedbackTest extends TestCase{

	public function testCapturesOnlyPriorityRecipientsAndSendsOneBatchEach() : void{
		$attackerSession = $this->createMock(NetworkSession::class);
		$victimSession = $this->createMock(NetworkSession::class);
		$attacker = new CombatFeedbackTestPlayer($attackerSession);
		$victim = new CombatFeedbackTestPlayer($victimSession);
		$observer = $this->createPlayerMock();

		$animation = $this->createMock(ClientboundPacket::class);
		$sound = $this->createMock(ClientboundPacket::class);
		$feedback = new CombatFeedback($attacker, $victim);

		$remaining = $feedback->capturePackets([$attacker, $observer, $victim], [$animation]);
		self::assertSame([$observer], array_values($remaining));
		$remaining = $feedback->capturePackets([$attacker, $observer], [$sound]);
		self::assertSame([$observer], array_values($remaining));

		$attackerSession->expects(self::once())
			->method('sendCombatPacketBatch')
			->with([$animation, $sound])
			->willReturn(true);
		$victimSession->expects(self::once())
			->method('sendCombatPacketBatch')
			->with([$animation])
			->willReturn(true);

		$feedback->sendImmediately();
		$feedback->sendImmediately();
	}

	public function testMotionBroadcastingKeepsEntityFifoOrdering() : void{
		$method = new ReflectionMethod(Living::class, 'broadcastMotion');

		self::assertSame(Entity::class, $method->getDeclaringClass()->getName());
	}

	private function createPlayerMock() : Player{
		return new CombatFeedbackTestPlayer($this->createMock(NetworkSession::class));
	}
}

final class CombatFeedbackTestPlayer extends Player{

	// @phpstan-ignore constructor.missingParentCall (This isolated test double intentionally skips runtime player setup.)
	public function __construct(private NetworkSession $testNetworkSession){}

	public function getNetworkSession() : NetworkSession{
		return $this->testNetworkSession;
	}

	public function __destruct(){}
}
