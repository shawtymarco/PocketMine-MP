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

namespace pocketmine\debug;

use pocketmine\Server;
use function array_slice;
use function array_sum;
use function count;
use function implode;
use function memory_get_usage;
use function number_format;
use function sprintf;

/** Owns the built-in bounded /perfdebug capture and emits one diagnostic line per completed tick. */
final class PerformanceDebugManager{
	private bool $enabled = false;
	private int $stopAtTick = 0;
	private int $sequence = 0;
	private float $lastProbeCostMs = 0.0;

	public function __construct(
		private Server $server
	){}

	public function isEnabled() : bool{
		return $this->enabled;
	}

	/** Enables a fresh bounded capture, replacing any active capture. */
	public function enable(int $seconds, string $actor) : void{
		$seconds = max(1, min(120, $seconds));
		$this->enabled = true;
		$this->stopAtTick = $this->server->getTick() + ($seconds * Server::TARGET_TICKS_PER_SECOND);
		$this->sequence = 0;
		$this->lastProbeCostMs = 0.0;
		TickProfiler::enable();
		$this->server->getLogger()->warning(sprintf(
			"[PerfDebug] enabled actor=%s duration=%ds stop_tick=%d",
			$actor,
			$seconds,
			$this->stopAtTick
		));
	}

	/** Disables the active capture and reports how many completed ticks were emitted. */
	public function disable(string $reason) : bool{
		if(!$this->enabled){
			return false;
		}

		$this->enabled = false;
		TickProfiler::disable();
		$this->server->getLogger()->warning(sprintf(
			"[PerfDebug] disabled reason=%s samples=%d",
			$reason,
			$this->sequence
		));
		return true;
	}

	/** Emits the sample finalized by Server::tick(), including debug-probe overhead from the previous line. */
	public function onTickComplete() : void{
		if(!$this->enabled){
			return;
		}

		$sample = TickProfiler::getLastSample();
		if($sample === null){
			return;
		}

		$probeStartedAt = (int) hrtime(true);
		$asyncQueueSizes = $this->server->getAsyncPool()->getTaskQueueSizes();
		$worlds = [];
		foreach($this->server->getWorldManager()->getWorlds() as $world){
			$worlds[] = sprintf(
				"%s{chunks=%d,ticking=%d,entities=%d,players=%d,tick_ms=%.3f}",
				$world->getFolderName(),
				count($world->getLoadedChunks()),
				count($world->getTickingChunks()),
				count($world->getEntities()),
				count($world->getPlayers()),
				$world->getTickRateTime()
			);
		}

		$this->sequence++;
		$line = sprintf(
			"[PerfDebug] seq=%d tick=%d prev_tps=%.2f avg_tps=%.2f prev_load=%.2f%% avg_load=%.2f%% online=%d mem_mb=%.1f real_mem_mb=%.1f async_workers=%d async_queued=%d worlds=%s core=%s prev_probe_ms=%.3f",
			$this->sequence,
			$this->server->getTick(),
			$this->server->getTicksPerSecond(),
			$this->server->getTicksPerSecondAverage(),
			$this->server->getTickUsage(),
			$this->server->getTickUsageAverage(),
			count($this->server->getOnlinePlayers()),
			memory_get_usage(false) / 1024 / 1024,
			memory_get_usage(true) / 1024 / 1024,
			count($asyncQueueSizes),
			array_sum($asyncQueueSizes),
			$worlds === [] ? "none" : implode(";", $worlds),
			$this->formatCoreProfile($sample),
			$this->lastProbeCostMs
		);

		$this->server->getLogger()->info($line);
		$this->lastProbeCostMs = ((int) hrtime(true) - $probeStartedAt) / 1_000_000;

		if($this->server->getTick() >= $this->stopAtTick){
			$this->disable("duration-complete");
		}
	}

	/**
	 * @param array{
	 *     tick: int,
	 *     gap_ms: float,
	 *     active_ms: float,
	 *     interrupt_ms: float,
	 *     total_ms: float,
	 *     unaccounted_ms: float,
	 *     phases: array<string, float>,
	 *     contributors: array<string, list<array{name: string, total_ms: float, max_ms: float, calls: int}>>,
	 *     memory_delta_kb: float,
	 *     gc_runs: int,
	 *     gc_collected: int,
	 *     garbage_collections: list<array{source: string, roots_before: int, roots_after: int, threshold_before: int, threshold_after: int, cycles: int, duration_ms: float}>,
	 *     cpu_user_ms: float,
	 *     cpu_system_ms: float,
	 *     voluntary_context_switches: int,
	 *     involuntary_context_switches: int,
	 *     minor_page_faults: int,
	 *     major_page_faults: int
	 * } $sample
	 */
	private function formatCoreProfile(array $sample) : string{
		$phases = [];
		foreach($sample["phases"] as $name => $durationMs){
			$phases[] = $name . ":" . number_format($durationMs, 3, ".", "");
		}

		$tops = [];
		foreach($sample["contributors"] as $category => $entries){
			$categoryEntries = [];
			foreach(array_slice($entries, 0, 3) as $entry){
				$categoryEntries[] = sprintf(
					"%s:%.3f/%d/max%.3f",
					$entry["name"],
					$entry["total_ms"],
					$entry["calls"],
					$entry["max_ms"]
				);
			}
			if($categoryEntries !== []){
				$tops[] = $category . "{" . implode(",", $categoryEntries) . "}";
			}
		}

		$garbageCollections = [];
		foreach($sample["garbage_collections"] as $entry){
			$garbageCollections[] = sprintf(
				"%s{roots=%d>%d|threshold=%d>%d|cycles=%d|ms=%.3f}",
				$entry["source"],
				$entry["roots_before"],
				$entry["roots_after"],
				$entry["threshold_before"],
				$entry["threshold_after"],
				$entry["cycles"],
				$entry["duration_ms"]
			);
		}

		return sprintf(
			"[tick:%d,gap:%.3f,total:%.3f,active:%.3f,interrupt:%.3f,unaccounted:%.3f,cpu_user:%.3f,cpu_sys:%.3f,mem_delta_kb:%.1f,gc_runs:%d,gc_collected:%d,gc_detail:%s,vcsw:%d,ivcsw:%d,minflt:%d,majflt:%d,phases:%s,tops:%s]",
			$sample["tick"],
			$sample["gap_ms"],
			$sample["total_ms"],
			$sample["active_ms"],
			$sample["interrupt_ms"],
			$sample["unaccounted_ms"],
			$sample["cpu_user_ms"],
			$sample["cpu_system_ms"],
			$sample["memory_delta_kb"],
			$sample["gc_runs"],
			$sample["gc_collected"],
			$garbageCollections === [] ? "none" : implode(";", $garbageCollections),
			$sample["voluntary_context_switches"],
			$sample["involuntary_context_switches"],
			$sample["minor_page_faults"],
			$sample["major_page_faults"],
			$phases === [] ? "none" : implode(",", $phases),
			$tops === [] ? "none" : implode(";", $tops)
		);
	}
}
