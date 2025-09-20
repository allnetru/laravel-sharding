# Code Style

The **laravel-sharding** package follows the same formatting defaults as the Laravel framework and its [Pint](https://laravel.com/docs/12.x/pint) preset.  When writing new features or adjusting examples, keep the conventions below in mind so the documentation, console commands, configuration and support classes feel consistent with the rest of the package.

## Naming Conventions

### Namespaces and Classes

* Package code lives under the `Allnetru\Sharding` root namespace and follows PSR-4 autoloading rules.  Organise classes by behaviour: strategies live in `Strategies`, ID generators in `IdGenerators`, console commands in `Console\Commands\Shards`, and reusable helpers in `Support`.
* Strategy implementations should end with the `Strategy` suffix (`HashStrategy`, `RedisStrategy`, `DbHashRangeStrategy`).  Console commands should use a descriptive verb such as `Rebalance` or `Distribute`.
* Traits are named after the capability they provide, for example `Models\Concerns\Shardable` adds shard routing behaviour to an Eloquent model.

### Database

Although the package does not ship with application tables, it provides migrations for the metadata that powers the sharding strategies.  Follow the same naming approach when expanding these migrations or creating examples for consumer applications.

#### Tables

Metadata tables are always plural (`shard_sequences`, `shard_ranges`, `shard_slots`).  When you demonstrate sharded application tables in the guides, use plural table names as well and let the sharding strategies resolve the correct physical connection.

```php
Schema::create('shard_ranges', function (Blueprint $table) {
    $table->id();
    $table->string('table')->index();
    $table->unsignedBigInteger('start');
    $table->unsignedBigInteger('end')->nullable();
    $table->string('connection');
    $table->timestamps();
});
```

Relation tables use `snake_case` with the base table singular and the related table plural (`user_orders`, `organization_subscriptions`).  This keeps them compatible with Laravel's automatic pivot-table discovery.

#### Columns

Columns use `snake_case` and should be ordered for readability and query performance:

1. Primary key.
2. Foreign keys or references to other tables.
3. Business fields (names, identifiers, payloads).
4. Metrics and counters.
5. Status and type flags.
6. Timestamps and audit columns.

Denormalising shard metadata is encouraged.  Include the shard key, the resolved connection or group, and other lookup fields alongside the data so strategies like `Shardable` can make routing decisions without additional queries.

```php
Schema::create('shard_slots', function (Blueprint $table) {
    $table->id();
    $table->string('table')->index();
    $table->unsignedBigInteger('slot');
    $table->string('connection');
    $table->timestamps();
});
```

### Configuration Keys

Configuration arrays (`config/sharding.php`) use `snake_case` keys.  Group related settings and reuse common defaults so examples remain terse:

```php
return [
    'default' => 'hash',
    'strategies' => [
        'hash' => HashStrategy::class,
        'redis' => RedisStrategy::class,
    ],
    'tables' => [
        'users' => [
            'strategy' => 'redis',
            'redis_connection' => 'shards',
            'redis_prefix' => 'user_shard:',
        ],
    ],
];
```

## Variables

PHP variables use `camelCase`.  This keeps service classes—such as the `ShardingManager`—readable and consistent with the Laravel ecosystem.

```php
public function connectionFor(Model|string $model, mixed $key): array
{
    [$strategy, $tableConfig] = $this->strategyFor($model);

    $migrations = $this->config['migrations'] ?? [];
    if ($migrations) {
        $tableConfig['connections'] = array_diff_key(
            $tableConfig['connections'],
            $migrations
        );
    }

    return $strategy->determine($key, $tableConfig);
}
```

## Comments

Single-line comments start with a lowercase letter.  Reserve uppercase markers for TODO, FIXME, or KLUDGE annotations.

```php
// resolve shard strategy from configuration
// TODO: support custom backoff policy for rebalance command
```

## Input Data

Input coming from artisan options, console arguments, or configuration arrays should use `snake_case` for keys and `kebab-case` for command options.  Keep option names short and explicit so they map directly to sharding terminology.

```php
protected $signature = 'shards:rebalance {table} {--from=} {--to=} {--start=} {--end=}';
```

When documenting request payloads for host applications, follow the same `snake_case` style because it matches Laravel's validation rules and casts.

## Console Commands

The package exposes artisan commands under the `shards` namespace.  Use a noun for the namespace and a verb for the action (`shards:rebalance`, `shards:distribute`).  Arguments describe the subject (e.g. `{table}`) and options describe filters or ranges (`--from`, `--to`).

```php
php artisan shards:rebalance users --from=shard-1 --to=shard-3 --start=1 --end=5000
```

## Enums

The core package does not currently rely on PHP `enum`s.  If you introduce one—for example to describe shard connection states—name the cases in lowercase and prefer integer-backed enums when the value is persisted to the database.  This keeps the tables compact and avoids migration churn when you add new values.

```php
enum ShardState: int
{
    case active = 1;
    case draining = 2;
    case retired = 3;
}
```
