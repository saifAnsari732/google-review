<?php
/**
 * @author Yenier Jimenez <yjmorales86@gmail.com>
 */

namespace App\Core\Cache;

use Common\DataStorage\Redis\RedisCacheRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Throwable;

/**
 * Robust cache registry that attempts Redis first and automatically falls back
 * to a Symfony FilesystemAdapter when Redis is offline or unreachable.
 *
 * Inherits from RedisCacheRegistry for 100% type compatibility with existing controllers.
 */
class FallbackRedisCacheRegistry extends RedisCacheRegistry implements CacheRegistryInterface
{
    /**
     * Filesystem cache adapter used as fallback when Redis is unreachable.
     */
    private FilesystemAdapter $fallbackAdapter;

    /**
     * Optional logger for recording Redis connection failures.
     */
    private ?LoggerInterface $logger;

    /**
     * Flag indicating whether Redis connection has already failed in this request cycle.
     */
    private bool $redisUnavailable = false;

    /**
     * @param string|null          $host        Redis host.
     * @param string               $keyPrefix   Prefix for cache keys.
     * @param string|null          $password    Redis password.
     * @param int|string           $port        Redis port.
     * @param string               $projectDir  Project root directory.
     * @param LoggerInterface|null $logger      Logger instance.
     */
    public function __construct(
        ?string $host,
        string $keyPrefix,
        ?string $password,
        $port,
        string $projectDir,
        ?LoggerInterface $logger = null
    ) {
        $safeHost = (!empty($host)) ? $host : '127.0.0.1';
        $intPort  = is_numeric($port) ? (int)$port : 6379;

        parent::__construct($safeHost, $keyPrefix, $password, $intPort);

        $this->logger          = $logger;
        $this->fallbackAdapter = new FilesystemAdapter(
            'google_review_manager',
            0,
            $projectDir . '/var/cache/app_fallback'
        );
    }

    /**
     * @inheritDoc
     * @return mixed
     */
    public function get(string $key, &$found = null)
    {
        if (!$this->redisUnavailable) {
            try {
                return parent::get($key, $found);
            } catch (Throwable $e) {
                $this->redisUnavailable = true;
                if ($this->logger) {
                    $this->logger->warning('Redis unreachable on get(), falling back to filesystem cache: ' . $e->getMessage());
                }
            }
        }

        try {
            $sanitizedKey = $this->sanitizeKey($key);
            $item         = $this->fallbackAdapter->getItem($sanitizedKey);
            if (!$item->isHit()) {
                $found = false;

                return false;
            }
            $found = true;

            return $item->get();
        } catch (Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Filesystem fallback cache get() failed: ' . $e->getMessage());
            }
            $found = false;

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function set(string $key, $value, int $ttl): bool
    {
        if (!$this->redisUnavailable) {
            try {
                return parent::set($key, $value, $ttl);
            } catch (Throwable $e) {
                $this->redisUnavailable = true;
                if ($this->logger) {
                    $this->logger->warning('Redis unreachable on set(), falling back to filesystem cache: ' . $e->getMessage());
                }
            }
        }

        try {
            $sanitizedKey = $this->sanitizeKey($key);
            $item         = $this->fallbackAdapter->getItem($sanitizedKey);
            $item->set($value);
            $item->expiresAfter($ttl);

            return $this->fallbackAdapter->save($item);
        } catch (Throwable $e) {
            if ($this->logger) {
                $this->logger->error('Filesystem fallback cache set() failed: ' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function purge(string $key): bool
    {
        if (!$this->redisUnavailable) {
            try {
                return parent::purge($key);
            } catch (Throwable $e) {
                $this->redisUnavailable = true;
                if ($this->logger) {
                    $this->logger->warning('Redis unreachable on purge(), falling back to filesystem cache: ' . $e->getMessage());
                }
            }
        }

        try {
            $sanitizedKey = $this->sanitizeKey($key);

            return $this->fallbackAdapter->deleteItem($sanitizedKey);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Strips characters forbidden by PSR-6 cache key specifications: {}()/\@:
     */
    private function sanitizeKey(string $key): string
    {
        return preg_replace('/[{}()\/\\\\@:]/', '_', $key);
    }
}
