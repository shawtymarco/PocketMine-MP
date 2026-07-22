<?php

declare(strict_types=1);

namespace pocketmine\debug;

use PHPUnit\Framework\TestCase;

final class TickProfilerTest extends TestCase{
	protected function tearDown() : void{
		TickProfiler::disable();
	}

	public function testCollectsPhasesAndContributors() : void{
		TickProfiler::enable();
		TickProfiler::beginTick(42);

		$phaseStartedAt = TickProfiler::startTimer();
		TickProfiler::recordPhase("scheduler", $phaseStartedAt);
		$contributorStartedAt = TickProfiler::startTimer();
		TickProfiler::recordContributor("task", "Example:Task", $contributorStartedAt);
		TickProfiler::finishTick(2_000_000);

		$sample = TickProfiler::getLastSample();
		self::assertNotNull($sample);
		self::assertSame(42, $sample["tick"]);
		self::assertSame(2.0, $sample["interrupt_ms"]);
		self::assertArrayHasKey("scheduler", $sample["phases"]);
		self::assertSame("Example:Task", $sample["contributors"]["task"][0]["name"]);
		self::assertSame(1, $sample["contributors"]["task"][0]["calls"]);
	}

	public function testCarriesSleeperHandlersIntoNextTick() : void{
		TickProfiler::enable();
		$interruptStartedAt = TickProfiler::startInterruptTimer();
		$packetStartedAt = TickProfiler::startTimer();
		TickProfiler::recordContributor("packet_receive", "MovePlayerPacket", $packetStartedAt);
		TickProfiler::recordInterruptContributor("handler", "AsyncPool", $interruptStartedAt);
		self::assertSame(0, TickProfiler::startTimer());

		TickProfiler::beginTick(7);
		TickProfiler::finishTick(1);

		$sample = TickProfiler::getLastSample();
		self::assertNotNull($sample);
		self::assertSame("AsyncPool", $sample["contributors"]["interrupt_handler"][0]["name"]);
		self::assertSame("MovePlayerPacket", $sample["contributors"]["interrupt_packet_receive"][0]["name"]);
	}

	public function testRecordsGarbageCollectorTriggerDetails() : void{
		TickProfiler::enable();
		TickProfiler::beginTick(8);
		TickProfiler::recordGarbageCollection("threshold", 10_001, 0, 10_001, 20_001, 0, 41_612_000);
		TickProfiler::finishTick(0);

		$sample = TickProfiler::getLastSample();
		self::assertNotNull($sample);
		self::assertSame([
			"source" => "threshold",
			"roots_before" => 10_001,
			"roots_after" => 0,
			"threshold_before" => 10_001,
			"threshold_after" => 20_001,
			"cycles" => 0,
			"duration_ms" => 41.612,
		], $sample["garbage_collections"][0]);
	}

	public function testDormantTimersDoNotCollect() : void{
		TickProfiler::disable();
		self::assertSame(0, TickProfiler::startTimer());
		self::assertSame(0, TickProfiler::startInterruptTimer());
		self::assertNull(TickProfiler::getLastSample());
	}
}
