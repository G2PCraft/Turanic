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

namespace pocketmine\item;


use pocketmine\entity\Entity;
use pocketmine\entity\Projectile;
use pocketmine\event\entity\EntityShootBowEvent;
use pocketmine\event\entity\ProjectileLaunchEvent;
use pocketmine\level\sound\LaunchSound;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\Player;

class Bow extends Tool {
	/**
	 * Bow constructor.
	 *
	 * @param int $meta
	 * @param int $count
	 */
	public function __construct($meta = 0, $count = 1){
		parent::__construct(self::BOW, $meta, $count, "Bow");
	}

    public function onReleaseUsing(Player $player) : bool{
        if($player->isSurvival() and !$player->getInventory()->contains(Item::get(Item::ARROW, -1))){
            $player->getInventory()->sendContents($player);
            return false;
        }

        $directionVector = $player->getDirectionVector();

        $arrow = null;
        $index = $player->getInventory()->first(Item::get(Item::ARROW, -1));
        if($index !== -1){
            $arrow = $player->getInventory()->getItem($index);
            $arrow->setCount(1);
        }elseif($player->isCreative()){
            $arrow = Item::get(Item::ARROW, 0, 1);
        }else{
            $player->getInventory()->sendContents($player);
            return false;
        }

        $nbt = new CompoundTag("", [
            new ListTag("Pos", [
                new DoubleTag("", $player->x),
                new DoubleTag("", $player->y + $player->getEyeHeight()),
                new DoubleTag("", $player->z)
            ]),
            new ListTag("Motion", [
                new DoubleTag("", $directionVector->x),
                new DoubleTag("", $directionVector->y),
                new DoubleTag("", $directionVector->z)
            ]),
            new ListTag("Rotation", [
                new FloatTag("", ($player->yaw > 180 ? 360 : 0) - $player->yaw), //arrow yaw must range from -180 to +180
                new FloatTag("", -$player->pitch)
            ]),
            new ShortTag("Fire", $player->isOnFire() ? 45 * 60 : 0),
            new ShortTag("Potion", $arrow->getDamage())
        ]);

        $diff = $player->getItemUseDuration();
        $p = $diff / 20;
        $force = min((($p ** 2) + $p * 2) / 3, 1) * 2;


        $entity = Entity::createEntity("Arrow", $player->getLevel(), $nbt, $player, $force == 2);
        if($entity instanceof Projectile){
            $ev = new EntityShootBowEvent($player, $this, $entity, $force);

            if($force < 0.1 or $diff < 5){
                $ev->setCancelled();
            }

            $player->getServer()->getPluginManager()->callEvent($ev);

            $entity = $ev->getProjectile(); //This might have been changed by plugins

            if($ev->isCancelled()){
                $entity->kill();
                $player->getInventory()->sendContents($player);
            }else{
                $entity->setMotion($entity->getMotion()->multiply($ev->getForce()));
                if($player->isSurvival()){
                    $player->getInventory()->removeItem(Item::get(Item::ARROW, 0, 1));
                    $this->setDamage($this->getDamage() + 1);
                    if($this->getDamage() >= $this->getMaxDurability()){
                        $player->getInventory()->setItemInHand(Item::get(Item::AIR, 0, 0));
                    }else{
                        $player->getInventory()->setItemInHand($this);
                    }
                }

                if($entity instanceof Projectile){
                    $player->getServer()->getPluginManager()->callEvent($projectileEv = new ProjectileLaunchEvent($entity));
                    if($projectileEv->isCancelled()){
                        $ev->getProjectile()->kill();
                    }else{
                        $ev->getProjectile()->spawnToAll();
                        $player->level->addSound(new LaunchSound($player), $player->getViewers());
                    }
                }else{
                    $entity->spawnToAll();
                }
            }
        }else{
            $entity->spawnToAll();
        }
        return true;
    }
}