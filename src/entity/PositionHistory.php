<?php

declare(strict_types=1);

namespace pocketmine\entity;

use pocketmine\math\Vector3;

/**
 * Ring buffer that stores entity positions indexed by server tick.
 * Used for lag-compensated hit registration.
 */
final class PositionHistory{

	private const MAX_TICKS = 40; // 2 seconds at 20 TPS

	/** Cached result of the ELIAGIC_HIT_LAGCOMP env lookup (null = not yet resolved). */
	private static ?bool $lagCompEnabled = null;

	/** @var array<int, Vector3> tick => position */
	private array $history = [];

	/**
	 * Whether lag-compensated hit registration is active.
	 *
	 * Controlled by the ELIAGIC_HIT_LAGCOMP environment variable (k8s-style per-container
	 * injection, mirroring BEDWARS_SERVER_ID) so a single replica can be flipped to A/B test
	 * whether hits feel better with rewind on or off. Defaults to ON — only an explicit
	 * "0"/"false"/"off" disables it. Read once and cached because this is queried on the
	 * per-tick entity hot path, where a getenv() every tick would be wasteful.
	 *
	 * When disabled, Living skips per-tick position recording and Player skips the attack
	 * rewind, so hits resolve against live positions (vanilla behaviour).
	 */
	public static function lagCompEnabled() : bool{
		if(self::$lagCompEnabled === null){
			$env = strtolower((string) getenv("ELIAGIC_HIT_LAGCOMP"));
			self::$lagCompEnabled = !($env === "0" || $env === "false" || $env === "off");
		}
		return self::$lagCompEnabled;
	}

	/** Records the entity's current position for the given tick. */
	public function record(int $tick, Vector3 $pos) : void{
		$this->history[$tick] = $pos;

		// Prune entries older than MAX_TICKS
		$cutoff = $tick - self::MAX_TICKS;
		foreach($this->history as $t => $_){
			if($t < $cutoff){
				unset($this->history[$t]);
			}else{
				break; // history keys are in ascending order
			}
		}
	}

	/**
	 * Returns the recorded position closest to the given tick.
	 * Returns null if history is empty.
	 */
	public function getPositionAtTick(int $tick) : ?Vector3{
		if(isset($this->history[$tick])){
			return $this->history[$tick];
		}

		$bestTick = null;
		$bestDist = PHP_INT_MAX;
		foreach($this->history as $t => $pos){
			$dist = abs($t - $tick);
			if($dist < $bestDist){
				$bestDist = $dist;
				$bestTick = $t;
			}
		}

		return $bestTick !== null ? $this->history[$bestTick] : null;
	}

	/**
	 * Returns whether the given point is plausible for this entity's recent hitbox path.
	 */
	public function isNearRecentHitbox(Vector3 $pos, EntitySizeInfo $size, int $currentTick, int $maxAgeTicks, float $margin) : bool{
		$cutoff = $currentTick - $maxAgeTicks;
		$halfWidth = ($size->getWidth() / 2) + $margin;
		$height = $size->getHeight() + $margin;

		foreach($this->history as $tick => $historyPos){
			if($tick < $cutoff){
				continue;
			}
			if(abs($pos->x - $historyPos->x) > $halfWidth || abs($pos->z - $historyPos->z) > $halfWidth){
				continue;
			}
			if($pos->y >= $historyPos->y - $margin && $pos->y <= $historyPos->y + $height){
				return true;
			}
		}

		return false;
	}

	public function clear() : void{
		$this->history = [];
	}
}
