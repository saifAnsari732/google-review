<?php
/**
 * @author Yenier Jimenez <yjmorales86@gmail.com>
 */

namespace App\Core\Cache;

/**
 * Interface for application cache registries supporting key/value caching with TTL.
 */
interface CacheRegistryInterface
{
    /**
     * Retrieves an item from cache.
     *
     * @param string $key   Cache key.
     * @param bool|null $found Optional output reference indicating if the item was found.
     *
     * @return mixed|false
     */
    public function get(string $key, &$found = null);

    /**
     * Stores an item into cache with a TTL in seconds.
     *
     * @param string $key   Cache key.
     * @param mixed  $value Value to store.
     * @param int    $ttl   Time to live in seconds.
     *
     * @return bool True on success, false on failure.
     */
    public function set(string $key, $value, int $ttl): bool;

    /**
     * Removes an item from cache based on its key.
     *
     * @param string $key Cache key.
     *
     * @return bool True on success, false on failure.
     */
    public function purge(string $key): bool;
}
