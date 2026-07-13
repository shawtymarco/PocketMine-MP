# PocketMine-MP (shawtymarco Fork)

Fork of [NetherGamesMC/PocketMine-MP](https://github.com/NetherGamesMC/PocketMine-MP) with gameplay patches for competitive Bedrock servers.

Maintained by [@shawtymarco](https://github.com/shawtymarco). Compiled into `PocketMine.phar` via `Archived/Scripts/build_phar.php`.

## Patches

### Bow Charging Robustness

A series of fixes making bow charging reliable under high-ping conditions, matching Java Edition behavior.

**`src/item/Bow.php` — Allow short charge releases** [`8a6950c`](https://github.com/shawtymarco/PocketMine-MP/commit/8a6950c)

Removes the minimum charge tick threshold so short bow pulls still fire. Force is calculated from `getItemUseDuration()` tick delta — near-zero pulls produce near-zero force naturally via the quadratic formula `(p^2 + p*2) / 3`.

**`src/network/mcpe/handler/InGamePacketHandler.php` — No interrupt on repeat click** [`c04b08c`](https://github.com/shawtymarco/PocketMine-MP/commit/c04b08c)

`ACTION_CLICK_AIR` while already using an item no longer resets the use state for Releasable items. Only GoatHorns (which complete client-side without a release packet) clear the flag immediately:

```php
if($this->player->isUsingItem()){
    // ... consume logic ...
    if($heldItem instanceof GoatHorn){
        $this->player->setUsingItem(false);
    }
    return true;
}
```

**`src/player/Player.php` — Block interaction no longer cancels charge** [`b2bbdbf`](https://github.com/shawtymarco/PocketMine-MP/commit/b2bbdbf)

`interactBlock()` only calls `setUsingItem(false)` if the player is NOT holding a `Releasable` item. Bow charging persists through block interaction packets that Bedrock clients interleave under latency:

```php
if(!($this->isUsingItem() && $this->inventory->getItemInHand() instanceof Releasable)){
    $this->setUsingItem(false);
}
```

**`src/player/Player.php` — Inventory listener scoped to held slot** [`b2bbdbf`](https://github.com/shawtymarco/PocketMine-MP/commit/b2bbdbf)

The `onContentChange` listener only cancels item use when the held slot's contents actually changed, not on any inventory content change:

```php
function(Inventory $unused, array $oldContents) : void{
    $heldIndex = $this->inventory->getHeldItemIndex();
    if(!isset($oldContents[$heldIndex]) ||
       !$oldContents[$heldIndex]->equalsExact($this->inventory->getItem($heldIndex))){
        $this->setUsingItem(false);
    }
}
```

**Note:** A Java-style countdown timer approach ([`c9b9ac9`](https://github.com/shawtymarco/PocketMine-MP/commit/c9b9ac9)) was attempted but reverted ([`96d8c1d`](https://github.com/shawtymarco/PocketMine-MP/commit/96d8c1d)) because it felt worse in practice. The original `startAction` tick-delta calculation is retained.

---

### Spectator Item Use Event

[`4e14959`](https://github.com/shawtymarco/PocketMine-MP/commit/4e14959)

**`src/player/Player.php` — `useHeldItem()`**

`PlayerItemUseEvent` fires unconditionally with no spectator-mode gate. Plugins can still cancel via `$ev->isCancelled()`, but the engine no longer silently blocks spectator item use. This enables custom spectator interactions (e.g. compass menu).

---

### Fall Damage Threshold

[`00501aa`](https://github.com/shawtymarco/PocketMine-MP/commit/00501aa)

**`src/entity/Living.php` — `calculateFallDamage()`**

Threshold increased from 3 to 4 blocks before fall damage applies:

```php
return ceil($fallDistance - 4 - ($jumpBoost?->getEffectLevel() ?? 0));
```

---

### Block Visibility Patch

[`060e83f`](https://github.com/shawtymarco/PocketMine-MP/commit/060e83f)

**`src/block/Block.php`**

Exposes internal block state methods as `public` so plugins can encode/decode block states directly:

- `public Block $defaultState` — stores the zero-state clone, set in `__construct()`
- `public function decodeBlockItemState(int $data)` — restores block state from item data
- `public function encodeBlockItemState() : int` — serializes block state to item data

**`src/item/ItemBlock.php`**

`public Block $block` — block reference exposed as public property.

---

### ItemBlock Subclassing

[`443ac1f`](https://github.com/shawtymarco/PocketMine-MP/commit/443ac1f)

**`src/item/ItemBlock.php`**

Removes `final` from the class declaration, allowing plugins to extend `ItemBlock` with custom behavior.

---

### BetterPMMP Patches

[`ba0bfbc`](https://github.com/shawtymarco/PocketMine-MP/commit/ba0bfbc), [`f320a5a`](https://github.com/shawtymarco/PocketMine-MP/commit/f320a5a)

Large patch applying [BetterPMMP](https://github.com/UserX0001/BetterPMMP) optimizations. All additions are marked with `[BETTERPMMP-PATCH]` comments in source.

#### Fixed Light

**`resources/pocketmine.yml`** — Adds `fixed-light` config option (enabled by default). When active, skips light recalculation for static worlds.

**`src/world/World.php`** — Chunk-based block cache, collision box cache, and optimized ticking chunk tracking (`registeredTickingChunks`, `validTickingChunks`).

#### Input Lag Fix (Block Lag Fix)

**`src/network/mcpe/handler/InGamePacketHandler.php`**

Captures a block state snapshot before `interactBlock()`, then uses snapshot-based diffing in `syncBlocksNearby()` to send only changed blocks back to the client. Compares internal block IDs via `BlockTranslator::internalIdToNetworkId()` to skip unchanged blocks:

```php
$oldBlockSnapshot = $this->captureBlockSnapshot($vBlockPos, $data->getFace());
$interactResult = $this->player->interactBlock(...);
$this->syncBlocksNearby($vBlockPos, $syncAdjacentFace, $interactResult ? $oldBlockSnapshot : []);
```

#### Hot Reload

New command `/reloadplugin <all|pluginName>` for live plugin reloading without server restart.

| File | Role |
|------|------|
| `src/command/defaults/ReloadPluginCommand.php` | Command handler — accepts plugin name or `all`, calls `PluginManager::reloadPlugin()` or `reloadAll()` |
| `src/plugin/PluginManager.php` | New `reloadPlugin()` and `reloadAll()` methods — unregisters handlers/commands/permissions, invalidates class cache, re-enables plugin |
| `src/plugin/ClassCacheInvalidator.php` | Detects changed source files via mtime, clears OPcache, re-evals code with versioned namespaces (`MyPlugin\v1\MyClass`) for true class redefinition |
| `src/plugin/PluginResourceIndex.php` | Central registry tracking each plugin's event handlers, commands, permissions, and scheduler tasks for safe cleanup on reload |
| `src/plugin/PluginResources.php` | Data container holding a single plugin's tracked runtime artifacts |

#### Restart

**`src/command/defaults/RestartCommand.php`** — `/restart` command that creates a `restart.flag` file at the server root, broadcasts a shutdown message, and calls `Server::shutdown()`. The external startup script detects the flag and auto-restarts.

#### Lazy Data Folder

**`src/plugin/PluginBase.php`** — Plugin data folder creation deferred to first access. `getDataFolder()`, `saveResource()`, and `saveConfig()` all call `ensureDataFolderExists()` which `mkdir`s on demand instead of at plugin load time.

#### Log Cleanup

**`src/utils/MainLogger.php`** — INFO-level messages use a compact format with only timestamp and message (no thread name, no log level prefix). Other levels (DEBUG, WARNING, ERROR) retain the full format.

#### Other

**`src/Server.php`** — Removes default gamemode and startup link log lines.

**`src/PocketMine.php`** — Replaces source-mode startup warning with BetterPMMP banner. Bypasses Composer sync check when running from source folder.

---

### syncBlocksNearby TypeConverter Fix

[`10b0e1e`](https://github.com/shawtymarco/PocketMine-MP/commit/10b0e1e)

**`src/network/mcpe/handler/InGamePacketHandler.php`**

Passes `TypeConverter` (fetched from `$this->session->getTypeConverter()`) to `World::createBlockUpdatePackets()` in the `syncBlocksNearby()` method. Fixes a crash introduced by the BetterPMMP input lag patch.

## Upstream Syncs

| Commit | Source |
|--------|--------|
| [`c7cdd15`](https://github.com/shawtymarco/PocketMine-MP/commit/c7cdd15) | NetherGamesMC:stable |
| [`de0c8fa`](https://github.com/shawtymarco/PocketMine-MP/commit/de0c8fa) | NetherGamesMC:stable |

## License

This project is licensed under LGPL-3.0. See the [LICENSE](/LICENSE) file for details.
