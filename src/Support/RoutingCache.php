<?php

namespace Allnetru\Sharding\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Where a slot's rows live, remembered so the question is not asked per query.
 *
 * Sharding is a promise about round trips: a read that names its key touches
 * the one connection that holds it. A strategy that keeps its routing in a
 * table breaks that promise quietly — before the pinned read can run, the slot
 * has to be looked up, and that is a round trip to the metadata database on
 * every query. Measured with two shards: a keyed read cost two metadata queries
 * and one shard query, three trips in sequence against the two in parallel a
 * fan-out would have made. The pinning was slower than what it replaced.
 *
 * So the routing is cached. Written through by the strategy whenever it
 * changes the metadata itself — recording a slot, moving a key — so the cache
 * is stale for exactly as long as the write takes, across every process that
 * shares the store. And namespaced by the connection list and the replica
 * count, so a change of topology is a change of namespace rather than a
 * window of wrong answers.
 *
 * An entry remembers whether the metadata has it. A strategy answers a key
 * it has never seen by hashing, and that answer is worth remembering too — but
 * it is not evidence the slot was ever written down, and the one caller that
 * skips a write when the cache agrees must not be fooled by it.
 *
 * A cache that cannot be reached is a cache that is not there: every failure
 * falls through to the lookup, because routing has to keep working when the
 * cache does not, and a wrong shard is worse than a slow one.
 */
final class RoutingCache
{
    /**
     * The placement a slot resolves to, from the cache or from the resolver.
     *
     * @param string $namespace What routing this belongs to, see namespaceFor().
     * @param string $entry The slot or range within it.
     * @param callable(): array{placement: list<string>, recorded: bool} $resolve The lookup, run on a miss.
     * @return list<string>
     */
    public static function remember(string $namespace, string $entry, callable $resolve): array
    {
        $cached = self::get($namespace, $entry);

        if ($cached !== null) {
            return $cached['placement'];
        }

        $resolved = $resolve();

        self::put($namespace, $entry, $resolved['placement'], $resolved['recorded']);

        return $resolved['placement'];
    }

    /**
     * The cached entry, or null when there is none or the cache is off.
     *
     * @param string $namespace
     * @param string $entry
     * @return array{placement: list<string>, recorded: bool}|null
     */
    public static function get(string $namespace, string $entry): ?array
    {
        if (!self::enabled()) {
            return null;
        }

        try {
            $cached = self::store()->get(self::key($namespace, $entry));
        } catch (Throwable) {
            return null;
        }

        if (!is_array($cached) || !is_array($cached['placement'] ?? null)) {
            return null;
        }

        return [
            'placement' => array_values($cached['placement']),
            'recorded' => (bool) ($cached['recorded'] ?? false),
        ];
    }

    /**
     * Remember a placement.
     *
     * @param string $namespace
     * @param string $entry
     * @param list<string> $placement
     * @param bool $recorded Whether the metadata holds this placement, or only the hash says so.
     * @return void
     */
    public static function put(string $namespace, string $entry, array $placement, bool $recorded): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            self::store()->put(
                self::key($namespace, $entry),
                ['placement' => $placement, 'recorded' => $recorded],
                self::ttl(),
            );
        } catch (Throwable) {
            // the next lookup asks the database, which is where the truth is
        }
    }

    /**
     * Forget a placement.
     *
     * @param string $namespace
     * @param string $entry
     * @return void
     */
    public static function forget(string $namespace, string $entry): void
    {
        if (!self::enabled()) {
            return;
        }

        try {
            self::store()->forget(self::key($namespace, $entry));
        } catch (Throwable) {
            // a stale entry outlives its TTL at worst
        }
    }

    /**
     * The namespace a strategy's routing lives under.
     *
     * The scope the metadata is stored under, plus a fingerprint of the
     * connections as configured and the replica count. A placement is only
     * meaningful against the topology it was computed for: add a shard, or
     * change one's weight, and every hashed fallback changes, and a cache that
     * did not know would go on answering for the old list until it expired —
     * with two processes, one warm and one cold, disagreeing about where a new
     * row belongs.
     *
     * The configuration has to be the one the strategy routes by, which is
     * what `ShardingManager::strategyFor()` hands out: the reader and the
     * writer of an entry must agree on the namespace, or a handover written
     * through under one list is invisible to reads made under another.
     *
     * @param array<string, mixed> $config The strategy's configuration.
     * @return string
     */
    public static function namespaceFor(array $config): string
    {
        $scope = (string) ($config['group'] ?? $config['table'] ?? '');
        $connections = (array) ($config['connections'] ?? []);
        ksort($connections);

        $topology = substr(sha1(json_encode([$connections, (int) ($config['replica_count'] ?? 0)]) ?: ''), 0, 12);

        return "{$scope}@{$topology}";
    }

    /**
     * @return bool
     */
    protected static function enabled(): bool
    {
        return (bool) config('sharding.routing_cache.enabled', true);
    }

    /**
     * @return Repository
     */
    protected static function store(): Repository
    {
        $store = config('sharding.routing_cache.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }

    /**
     * @return int
     */
    protected static function ttl(): int
    {
        return max(1, (int) config('sharding.routing_cache.ttl', 300));
    }

    /**
     * @param string $namespace
     * @param string $entry
     * @return string
     */
    protected static function key(string $namespace, string $entry): string
    {
        return "sharding:routing:{$namespace}:{$entry}";
    }
}
