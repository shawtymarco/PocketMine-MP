# PocketMine-MP Update Instructions

## Scope

This repository is the PocketMine-MP source fork used for CoreWar-compatible builds.
Keep PocketMine-MP source changes separate from BedrockProtocol, BedrockData, and CoreWar runtime changes.

## Branches And Remotes

- Work on version-specific branches such as `1.26.30-pre` when updating Minecraft protocol support.
- Push PocketMine-MP-NG protocol work to `pmmp-ng` (`https://github.com/shawtymarco/PocketMine-MP-NG.git`) unless explicitly instructed otherwise.
- Treat `origin` (`https://github.com/bellemyviolet/PocketMine-MP.git`) as the stable CoreWar fork unless the task targets it.
- Do not commit generated phars, `vendor/`, BDS files, worlds, logs, runtime state, or local caches.

## Protocol Update Dependencies

A full Bedrock update usually requires all three layers:

1. `pocketmine/bedrock-protocol` for packet IDs and serializers.
2. `pocketmine/bedrock-data` for block, item, biome, entity, creative, and recipe runtime data.
3. PocketMine-MP source glue for composer pins, translators, login/start-game flow, inventory, resource packs, and build fixes.

WaterdogPE/proxy support is a separate rollout layer and should be updated after direct PMMP validation.

## Composer Pinning

Pin forks explicitly on pre-release branches:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/shawtymarco/BedrockProtocol"
  },
  {
    "type": "vcs",
    "url": "https://github.com/shawtymarco/BedrockData"
  }
]
```

Use exact branch requirements such as:

- `pocketmine/bedrock-protocol: dev-1.26.30-pre`
- `pocketmine/bedrock-data: dev-1.26.30-pre`

After any BedrockProtocol or BedrockData force-push, refresh `composer.lock`.

## PMMP Code Changes

Update only the glue needed for the target protocol:

- block and item translators
- item schema ID
- protocol-specific constants and login checks
- `StartGamePacket` creation
- inventory content/slot usage
- resource pack and item registry flow
- codegen paths for BedrockData files

Avoid changing gameplay behavior while doing protocol work.

## Validation

Minimum validation:

```powershell
php composer.phar validate --no-check-publish
git diff --name-only -- '*.php' | % { php -l $_ }
php "-dphar.readonly=0" build/server-phar.php
```

Preferred validation when local PHP extensions are available:

```powershell
php composer.phar install --classmap-authoritative --no-dev --prefer-dist --ignore-platform-reqs
vendor\bin\phpunit.bat --colors=never
vendor\bin\phpstan.bat analyse
```

Runtime validation order:

1. Direct PMMP boot.
2. Direct 1.26.30 client login.
3. Resource pack handshake.
4. Inventory content/slot updates.
5. Block place/break and item use.
6. Entity attack and armor equipment.
7. Chunk loading and subchunk requests.
8. WaterdogPE proxy path only after direct PMMP works.

If local PHP extensions block runtime validation, report the missing extensions and still provide the phar build result.

## Commit And Push

- Use English commit messages.
- Push completed work to the active version branch.
- If the branch produces a release workflow, verify the GitHub release artifact before deploying it into CoreWar.
