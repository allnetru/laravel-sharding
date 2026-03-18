# CLAUDE.md

## Project Overview

Laravel Sharding (`allnetru/laravel-sharding`) — PHP package for distributing data across multiple databases while preserving standard Eloquent workflow. Supports MySQL, PostgreSQL, SQL Server, SQLite. Optional Redis for Redis-backed strategy.

- **PHP**: ^8.2
- **Laravel**: 12.x (Illuminate 12 components)
- **Namespace**: `Allnetru\Sharding`
- **Autoload**: PSR-4 — `src/` maps to `Allnetru\Sharding\`, `tests/` maps to `Allnetru\Sharding\Tests\`

## Quick Commands

```bash
composer test           # PHPUnit (tests/Unit + tests/Feature)
composer analyse        # PHPStan level 5
composer lint           # PHP-CS-Fixer dry-run check
```

Run all three before submitting any changes.

## Code Style

- Follow Laravel conventions with project-specific rules in `docs/en/dev/cs.md`
- **Formatting**: enforced by `.php_cs.dist.php` (PHP-CS-Fixer). Fix with: `vendor/bin/php-cs-fixer fix`
- **Static analysis**: PHPStan level 5 with Larastan, config in `phpstan.neon.dist`
- **Naming**:
  - Classes/Models: `StudlyCase` — strategy classes end with `Strategy` suffix
  - Variables: `camelCase`
  - DB tables/columns: `snake_case` (metadata tables always plural)
  - Routes/console options: `kebab-case`
  - Request payload keys: `snake_case`
  - Enum cases: `lowercase`; prefer integer-backed when persisted to DB
- **Console commands**: noun namespace + verb action (`shards:rebalance`, `shards:distribute`)
- **Column ordering**: PK → FK → Business fields → Metrics → Status flags → Timestamps
- **Comments**: single-line lowercase; uppercase for `TODO`, `FIXME`, `KLUDGE`

## Architecture

```
src/
├── Console/Commands/Shards/   # Artisan commands (Distribute, Rebalance, Migrate)
├── Contracts/                 # Interfaces (MetricServiceInterface)
├── IdGenerators/              # ID generation strategies (Snowflake, TableSequence)
├── Models/
│   ├── Concerns/Shardable.php # Core trait for shard-aware models
│   ├── ShardRange.php         # Range metadata model
│   ├── ShardSequence.php      # Sequence metadata model
│   └── ShardSlot.php          # Slot metadata model
├── Providers/                 # Service provider (auto-discovered)
├── Relations/                 # Shard-aware Eloquent relations (BelongsTo, HasMany, etc.)
├── Strategies/                # Sharding strategies (Hash, Redis, Range, DbRange, DbHashRange)
├── Support/
│   ├── Config/Shards.php      # Env-based shard connection builder
│   ├── Coroutine/             # Swoole/sync coroutine dispatching for fan-out queries
│   └── Database/              # DB-specific detectors (FK constraints, unique violations)
├── ShardBuilder.php           # Extended Eloquent builder for cross-shard queries
├── ShardingManager.php        # Central manager — resolves strategies, connections, groups
└── IdGenerator.php            # ID generator facade/manager
```

## Key Concepts

- **Strategies**: `HashStrategy`, `RedisStrategy`, `RangeStrategy`, `DbRangeStrategy`, `DbHashRangeStrategy` — each determines how a record maps to a shard connection
- **Groups**: related tables (e.g. `users` + `profiles`) share the same shard via group config
- **ID Generators**: `SnowflakeStrategy` (default, 64-bit sortable) and `TableSequenceStrategy` (DB-backed)
- **Coroutine support**: fan-out queries run concurrently under Swoole; configurable via `coroutines` config key

## Git Workflow

- `main` branch is stable; work on short-lived topic branches
- Branch naming: `feature/`, `bugfix/`, `docs/`, `refactor/`, `release/` prefixes, `kebab-case`, English
- PRs need Markdown summary, issue link when available, passing CI
- **CHANGELOG.md** is updated automatically during release — do not edit manually

## Testing Notes

- PHPUnit bootstrap: `tests/bootstrap.php`
- Default test DB: SQLite in-memory (configured in `phpunit.xml`)
- Test stubs in `tests/Stubs/` (fake coroutine drivers)
- Tests use Orchestra Testbench for Laravel package testing
