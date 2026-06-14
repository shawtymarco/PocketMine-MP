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

namespace pocketmine\event\entity;

use pocketmine\entity\effect\VanillaEffects;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\math\Vector3;

/**
 * Called when an entity takes damage from another entity.
 */
class EntityDamageByEntityEvent extends EntityDamageEvent{
	private int $damagerEntityId;

	/**
	 * Optional horizontal (x, z) direction override for knockback, as a delta from attacker to victim.
	 * Set by lag-compensated hit registration so the victim is launched along the vector the attacker
	 * actually saw at swing time, instead of the live (already-moved) positions read after event dispatch.
	 */
	private ?Vector3 $knockBackDirection = null;

	/**
	 * @param float[] $modifiers
	 */
	public function __construct(
		Entity $damager,
		Entity $entity,
		int $cause,
		float $damage,
		array $modifiers = [],
		private float $knockBack = Living::DEFAULT_KNOCKBACK_FORCE,
		private float $verticalKnockBackLimit = Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT
	){
		$this->damagerEntityId = $damager->getId();
		parent::__construct($entity, $cause, $damage, $modifiers);
		$this->addAttackerModifiers($damager);
	}

	protected function addAttackerModifiers(Entity $damager) : void{
		if($damager instanceof Living){ //TODO: move this to entity classes
			$effects = $damager->getEffects();
			if(($strength = $effects->get(VanillaEffects::STRENGTH())) !== null){
				$this->setModifier($this->getBaseDamage() * 0.3 * $strength->getEffectLevel(), self::MODIFIER_STRENGTH);
			}

			if(($weakness = $effects->get(VanillaEffects::WEAKNESS())) !== null && $this->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK){
				$this->setModifier(-($this->getBaseDamage() * 0.2 * $weakness->getEffectLevel()), self::MODIFIER_WEAKNESS);
			}
		}
	}

	/**
	 * Returns the attacking entity, or null if the attacker has been killed or closed.
	 */
	public function getDamager() : ?Entity{
		return $this->getEntity()->getWorld()->getServer()->getWorldManager()->findEntity($this->damagerEntityId);
	}

	/**
	 * Returns the force with which the victim will be knocked back from the attacking entity.
	 *
	 * @see Living::DEFAULT_KNOCKBACK_FORCE
	 */
	public function getKnockBack() : float{
		return $this->knockBack;
	}

	/**
	 * Sets the force with which the victim will be knocked back from the attacking entity.
	 * Larger values will knock the victim back further.
	 * Negative values will pull the victim towards the attacker.
	 */
	public function setKnockBack(float $knockBack) : void{
		$this->knockBack = $knockBack;
	}

	/**
	 * Returns the maximum upwards velocity the victim may have after being knocked back.
	 * This ensures that the victim doesn't fly up into the sky when high levels of knockback are applied.
	 *
	 * @see Living::DEFAULT_KNOCKBACK_VERTICAL_LIMIT
	 */
	public function getVerticalKnockBackLimit() : float{
		return $this->verticalKnockBackLimit;
	}

	/**
	 * Sets the maximum upwards velocity the victim may have after being knocked back.
	 * Larger values will allow the victim to fly higher if the knockback force is also large.
	 */
	public function setVerticalKnockBackLimit(float $verticalKnockBackLimit) : void{
		$this->verticalKnockBackLimit = $verticalKnockBackLimit;
	}

	/**
	 * Returns the horizontal (x, z) direction override used for knockback, or null to use the live
	 * attacker→victim vector. The y component is ignored.
	 */
	public function getKnockBackDirection() : ?Vector3{
		return $this->knockBackDirection;
	}

	/**
	 * Overrides the horizontal direction used for knockback, as an attacker→victim (x, z) delta.
	 * Used by lag-compensated hit registration so knockback matches the rewound positions the hit was
	 * validated against. Pass null to fall back to the live positions at damage-apply time.
	 */
	public function setKnockBackDirection(?Vector3 $direction) : void{
		$this->knockBackDirection = $direction;
	}
}
