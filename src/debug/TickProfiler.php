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

/**
 * Collects opt-in, per-tick wall-clock attribution beyond the aggregated timings report.
 *
	 * The hot-path hooks remain dormant until the built-in command enables this profiler.
 */
final class TickProfiler{
	private static bool $enabled = false;
	private static bool $tickActive = false;
	private static int $interruptDepth = 0;
	private static int $tick = 0;
	private static int $tickStartedAtNs = 0;
	private static int $previousTickStartedAtNs = 0;
	private static float $tickGapMs = 0.0;
	private static int $memoryStartedAt = 0;

	/** @var array<string, int> */
	private static array $resourceUsageStartedAt = [];
	/** @var array<string, int> */
	private static array $garbageCollectorStartedAt = [];
	/** @var array<string, int> */
	private static array $phases = [];
	/** @var array<string, array<string, array{total_ns: int, max_ns: int, calls: int}>> */
	private static array $contributors = [];
	/** @var array<string, array<string, array{total_ns: int, max_ns: int, calls: int}>> */
	private static array $pendingInterruptContributors = [];
	/** @var list<array{source: string, roots_before: int, roots_after: int, threshold_before: int, threshold_after: int, cycles: int, duration_ms: float}> */
	private static array $garbageCollections = [];
	/** @var list<array{source: string, roots_before: int, roots_after: int, threshold_before: int, threshold_after: int, cycles: int, duration_ms: float}> */
	private static array $pendingGarbageCollections = [];
	/**
	 * @var null|array{
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
	 * }
	 */
	private static ?array $lastSample = null;

	private function __construct(){
		// NOOP
	}

	/** Enables collection starting from the next server tick. */
	public static function enable() : void{
		self::$enabled = true;
		self::$tickActive = false;
		self::$interruptDepth = 0;
		self::$lastSample = null;
		self::$previousTickStartedAtNs = 0;
		self::$pendingInterruptContributors = [];
		self::$pendingGarbageCollections = [];
		self::clearTickState();
	}

	/** Disables collection and drops any partially collected tick. */
	public static function disable() : void{
		self::$enabled = false;
		self::$tickActive = false;
		self::$interruptDepth = 0;
		self::$lastSample = null;
		self::$pendingInterruptContributors = [];
		self::$pendingGarbageCollections = [];
		self::clearTickState();
	}

	public static function isEnabled() : bool{
		return self::$enabled;
	}

	/** Starts a new server-tick sample. */
	public static function beginTick(int $tick) : void{
		if(!self::$enabled){
			return;
		}

		self::clearTickState();
		if(self::$pendingInterruptContributors !== []){
			self::$contributors = self::$pendingInterruptContributors;
			self::$pendingInterruptContributors = [];
		}
		self::$garbageCollections = self::$pendingGarbageCollections;
		self::$pendingGarbageCollections = [];
		self::$tickActive = true;
		self::$tick = $tick;
		self::$tickStartedAtNs = (int) hrtime(true);
		self::$tickGapMs = self::$previousTickStartedAtNs === 0 ? 0.0 : (self::$tickStartedAtNs - self::$previousTickStartedAtNs) / 1_000_000;
		self::$previousTickStartedAtNs = self::$tickStartedAtNs;
		self::$memoryStartedAt = memory_get_usage(false);
		self::$resourceUsageStartedAt = self::readResourceUsage();
		self::$garbageCollectorStartedAt = self::readGarbageCollectorStatus();
	}

	/** Returns a monotonic timer token, or zero while profiling is dormant. */
	public static function startTimer() : int{
		return self::$tickActive || self::$interruptDepth > 0 ? (int) hrtime(true) : 0;
	}

	/** Opens a profiling context for work executed by a sleeper callback between ticks. */
	public static function startInterruptTimer() : int{
		if(!self::$enabled){
			return 0;
		}
		self::$interruptDepth++;
		return (int) hrtime(true);
	}

	/** Adds an exclusive top-level server phase to the current tick. */
	public static function recordPhase(string $name, int $startedAtNs) : void{
		if($startedAtNs === 0 || !self::$tickActive){
			return;
		}
		self::$phases[$name] = (self::$phases[$name] ?? 0) + ((int) hrtime(true) - $startedAtNs);
	}

	/** Adds one nested contributor to either the active tick or the current between-tick callback. */
	public static function recordContributor(string $category, string $name, int $startedAtNs) : void{
		if($startedAtNs === 0){
			return;
		}

		if(self::$tickActive){
			self::$contributors[$category] ??= [];
			self::addContributor(self::$contributors[$category], $name, (int) hrtime(true) - $startedAtNs);
		}elseif(self::$interruptDepth > 0){
			$interruptCategory = "interrupt_" . $category;
			self::$pendingInterruptContributors[$interruptCategory] ??= [];
			self::addContributor(self::$pendingInterruptContributors[$interruptCategory], $name, (int) hrtime(true) - $startedAtNs);
		}
	}

	/** Records a sleeper callback which ran outside the active server tick. */
	public static function recordInterruptContributor(string $category, string $name, int $startedAtNs) : void{
		if($startedAtNs === 0){
			return;
		}

		self::$interruptDepth = max(0, self::$interruptDepth - 1);
		if(!self::$enabled){
			return;
		}

		$interruptCategory = "interrupt_" . $category;
		self::$pendingInterruptContributors[$interruptCategory] ??= [];
		self::addContributor(self::$pendingInterruptContributors[$interruptCategory], $name, (int) hrtime(true) - $startedAtNs);
	}

	/** Records one explicit cyclic-GC invocation with its trigger and buffer state. */
	public static function recordGarbageCollection(
		string $source,
		int $rootsBefore,
		int $rootsAfter,
		int $thresholdBefore,
		int $thresholdAfter,
		int $cycles,
		int $durationNs
	) : void{
		if(!self::$enabled){
			return;
		}

		$entry = [
			"source" => self::normalizeName($source),
			"roots_before" => $rootsBefore,
			"roots_after" => $rootsAfter,
			"threshold_before" => $thresholdBefore,
			"threshold_after" => $thresholdAfter,
			"cycles" => $cycles,
			"duration_ms" => $durationNs / 1_000_000,
		];
		if(self::$tickActive){
			self::$garbageCollections[] = $entry;
		}else{
			self::$pendingGarbageCollections[] = $entry;
		}
	}

	/** Finalizes the active tick after timings bookkeeping has completed. */
	public static function finishTick(int $interruptTimeNs) : void{
		if(!self::$tickActive){
			return;
		}

		$activeNs = (int) hrtime(true) - self::$tickStartedAtNs;
		$phaseNs = array_sum(self::$phases);
		$resourceUsage = self::readResourceUsage();
		$garbageCollector = self::readGarbageCollectorStatus();
		$phases = [];
		foreach(self::$phases as $name => $durationNs){
			$phases[$name] = (float) ($durationNs / 1_000_000);
		}

		$contributors = [];
		foreach(self::$contributors as $category => $entries){
			$ranked = [];
			foreach($entries as $name => $entry){
				$ranked[] = [
					"name" => $name,
					"total_ms" => (float) ($entry["total_ns"] / 1_000_000),
					"max_ms" => (float) ($entry["max_ns"] / 1_000_000),
					"calls" => $entry["calls"],
				];
			}
			usort($ranked, static fn(array $left, array $right) : int => $right["total_ms"] <=> $left["total_ms"]);
			$contributors[$category] = array_slice($ranked, 0, 5);
		}

		self::$lastSample = [
			"tick" => self::$tick,
			"gap_ms" => self::$tickGapMs,
			"active_ms" => (float) ($activeNs / 1_000_000),
			"interrupt_ms" => (float) ($interruptTimeNs / 1_000_000),
			"total_ms" => (float) (($activeNs + $interruptTimeNs) / 1_000_000),
			"unaccounted_ms" => (float) (max(0, $activeNs - $phaseNs) / 1_000_000),
			"phases" => $phases,
			"contributors" => $contributors,
			"memory_delta_kb" => (float) ((memory_get_usage(false) - self::$memoryStartedAt) / 1024),
			"gc_runs" => ($garbageCollector["runs"] ?? 0) - (self::$garbageCollectorStartedAt["runs"] ?? 0),
			"gc_collected" => ($garbageCollector["collected"] ?? 0) - (self::$garbageCollectorStartedAt["collected"] ?? 0),
			"garbage_collections" => self::$garbageCollections,
			"cpu_user_ms" => self::resourceUsageDeltaMs($resourceUsage, self::$resourceUsageStartedAt, "user_us"),
			"cpu_system_ms" => self::resourceUsageDeltaMs($resourceUsage, self::$resourceUsageStartedAt, "system_us"),
			"voluntary_context_switches" => self::resourceUsageDelta($resourceUsage, self::$resourceUsageStartedAt, "voluntary_context_switches"),
			"involuntary_context_switches" => self::resourceUsageDelta($resourceUsage, self::$resourceUsageStartedAt, "involuntary_context_switches"),
			"minor_page_faults" => self::resourceUsageDelta($resourceUsage, self::$resourceUsageStartedAt, "minor_page_faults"),
			"major_page_faults" => self::resourceUsageDelta($resourceUsage, self::$resourceUsageStartedAt, "major_page_faults"),
		];
		self::$tickActive = false;
	}

	/**
	 * Returns the most recently completed server-tick sample.
	 *
	 * @return null|array{
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
	 * }
	 */
	public static function getLastSample() : ?array{
		return self::$lastSample;
	}

	private static function clearTickState() : void{
		self::$phases = [];
		self::$contributors = [];
		self::$garbageCollections = [];
		self::$resourceUsageStartedAt = [];
		self::$garbageCollectorStartedAt = [];
	}

	private static function normalizeName(string $name) : string{
		$name = str_replace(["\r", "\n", ";", "|"], [" ", " ", ",", "/"], $name);
		return strlen($name) > 180 ? substr($name, 0, 177) . "..." : $name;
	}

	/** @param array<string, array{total_ns: int, max_ns: int, calls: int}> $entries */
	private static function addContributor(array &$entries, string $name, int $durationNs) : void{
		$name = self::normalizeName($name);
		$current = $entries[$name] ?? null;
		if($current === null){
			$entries[$name] = [
				"total_ns" => $durationNs,
				"max_ns" => $durationNs,
				"calls" => 1,
			];
			return;
		}

		$current["total_ns"] += $durationNs;
		$current["max_ns"] = max($current["max_ns"], $durationNs);
		$current["calls"]++;
		$entries[$name] = $current;
	}

	/** @return array<string, int> */
	private static function readGarbageCollectorStatus() : array{
		$status = gc_status();
		return [
			"runs" => $status["runs"],
			"collected" => $status["collected"],
		];
	}

	/** @return array<string, int> */
	private static function readResourceUsage() : array{
		$usage = getrusage();
		if($usage === false){
			return [];
		}
		return [
			"user_us" => self::resourceTimeUs($usage, "ru_utime"),
			"system_us" => self::resourceTimeUs($usage, "ru_stime"),
			"voluntary_context_switches" => (int) ($usage["ru_nvcsw"] ?? 0),
			"involuntary_context_switches" => (int) ($usage["ru_nivcsw"] ?? 0),
			"minor_page_faults" => (int) ($usage["ru_minflt"] ?? 0),
			"major_page_faults" => (int) ($usage["ru_majflt"] ?? 0),
		];
	}

	/** @param array<string, int> $usage */
	private static function resourceTimeUs(array $usage, string $prefix) : int{
		return ((int) ($usage[$prefix . ".tv_sec"] ?? 0) * 1_000_000) + (int) ($usage[$prefix . ".tv_usec"] ?? 0);
	}

	/**
	 * @param array<string, int> $current
	 * @param array<string, int> $previous
	 */
	private static function resourceUsageDelta(array $current, array $previous, string $key) : int{
		return ($current[$key] ?? 0) - ($previous[$key] ?? 0);
	}

	/**
	 * @param array<string, int> $current
	 * @param array<string, int> $previous
	 */
	private static function resourceUsageDeltaMs(array $current, array $previous, string $key) : float{
		return self::resourceUsageDelta($current, $previous, $key) / 1000;
	}
}
