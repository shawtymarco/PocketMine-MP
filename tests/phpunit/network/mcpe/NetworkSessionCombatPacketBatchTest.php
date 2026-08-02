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
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\ActorEvent;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\timings\Timings;
use ReflectionProperty;
use function iterator_to_array;
use function ord;
use function substr;

final class NetworkSessionCombatPacketBatchTest extends TestCase{

	protected function setUp() : void{
		Timings::init();
	}

	public function testModernProtocolUsesNoneFramingAndImmediateTransport() : void{
		$sender = new CombatPacketBatchTestSender();
		$compressor = new ZlibCompressor(7, 256, 8 * 1024 * 1024);
		$session = new CombatPacketBatchTestSession($sender, ProtocolInfo::PROTOCOL_1_20_60, $compressor);
		$packet = ActorEventPacket::create(1, ActorEvent::HURT_ANIMATION, 0, null);

		self::assertTrue($session->sendCombatPacketBatch([$packet]));
		self::assertCount(1, $sender->sent);
		[$payload, $immediate, $receiptId] = $sender->sent[0];
		self::assertSame(CompressionAlgorithm::NONE, ord($payload[0]));
		self::assertTrue($immediate);
		self::assertNull($receiptId);
		$this->assertContainsPacket(substr($payload, 1), $packet, ProtocolInfo::PROTOCOL_1_20_60);
	}

	public function testLegacyProtocolUsesSynchronousCompressionWithoutAlgorithmHeader() : void{
		$sender = new CombatPacketBatchTestSender();
		$compressor = new ZlibCompressor(7, 256, 8 * 1024 * 1024);
		$session = new CombatPacketBatchTestSession($sender, ProtocolInfo::PROTOCOL_1_20_0, $compressor);
		$packet = ActorEventPacket::create(1, ActorEvent::HURT_ANIMATION, 0, null);

		self::assertTrue($session->sendCombatPacketBatch([$packet]));
		self::assertCount(1, $sender->sent);
		[$payload, $immediate, $receiptId] = $sender->sent[0];
		self::assertTrue($immediate);
		self::assertNull($receiptId);
		$this->assertContainsPacket($compressor->decompress($payload), $packet, ProtocolInfo::PROTOCOL_1_20_0);
	}

	private function assertContainsPacket(string $rawBatch, ActorEventPacket $expected, int $protocolId) : void{
		$packets = iterator_to_array(PacketBatch::decodeRaw(new ByteBufferReader($rawBatch)), false);
		self::assertCount(1, $packets);
		$writer = new ByteBufferWriter();
		$expected->encode($writer, $protocolId);
		self::assertSame($writer->getData(), $packets[0]);
	}
}

final class CombatPacketBatchTestSession extends NetworkSession{

	// @phpstan-ignore constructor.missingParentCall (This isolated transport test initializes only the fields used by the fast lane.)
	public function __construct(PacketSender $sender, int $protocolId, Compressor $compressor){
		$this->setParentProperty('connected', true);
		$this->setParentProperty('loggedIn', true);
		$this->setParentProperty('protocolId', $protocolId);
		$this->setParentProperty('enableCompression', true);
		$this->setParentProperty('compressor', $compressor);
		$this->setParentProperty('sender', $sender);
		$this->setParentProperty('cipher', null);
		$this->setParentProperty('ip', '127.0.0.1');
		$this->setParentProperty('port', 19132);
	}

	private function setParentProperty(string $name, mixed $value) : void{
		(new ReflectionProperty(NetworkSession::class, $name))->setValue($this, $value);
	}
}

final class CombatPacketBatchTestSender implements PacketSender{

	/** @phpstan-var list<array{string, bool, int|null}> */
	public array $sent = [];

	public function send(string $payload, bool $immediate, ?int $receiptId) : void{
		$this->sent[] = [$payload, $immediate, $receiptId];
	}

	public function close(string $reason = "unknown reason") : void{}
}
