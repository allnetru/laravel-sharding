<?php

namespace Allnetru\Sharding\Support;

/**
 * The routing entries one request has already looked up, so the shared cache is asked once per slot, not once per query.
 *
 * A page of sixty queries against one tenant asks the routing cache sixty times
 * for the same slot, and each ask is a round trip to the cache store. Bound as
 * a scoped instance, it lives exactly as long as the request or the queued job:
 * Octane and the queue worker forget scoped instances between them, so a slot
 * moved by another process is seen by the next request, never by a later part
 * of this one's reading of a page that was already routed.
 */
final class RoutingMemo
{
    /** @var array<string, array{placement: list<string>, recorded: bool}> */
    protected array $entries = [];

    /**
     * @param string $key The routing key.
     *
     * @return array{placement: list<string>, recorded: bool}|null
     */
    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    /**
     * @param string $key The routing key.
     * @param array{placement: list<string>, recorded: bool} $entry Where the slot lives, and whether the metadata has it.
     *
     * @return void
     */
    public function put(string $key, array $entry): void
    {
        $this->entries[$key] = $entry;
    }

    /**
     * @param string $key The routing key.
     *
     * @return void
     */
    public function forget(string $key): void
    {
        unset($this->entries[$key]);
    }
}
