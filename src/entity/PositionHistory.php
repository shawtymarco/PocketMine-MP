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

	/** @var array<int, Vector3> tick => position */
	private array $history = [];

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
