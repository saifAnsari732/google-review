<?php

namespace App\Tests\Unit\Core\Cache;

use App\Core\Cache\FallbackRedisCacheRegistry;
use PHPUnit\Framework\TestCase;

class FallbackRedisCacheRegistryTest extends TestCase
{
    private string $projectDir;
    private FallbackRedisCacheRegistry $cache;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 4);
        // Using an unroutable Redis port/host to trigger fallback immediately
        $this->cache = new FallbackRedisCacheRegistry(
            '127.0.0.1',
            'test_prefix_',
            null,
            65534,
            $this->projectDir
        );
    }

    public function testSetGetAndPurgeFallback(): void
    {
        $testKey = 'unit_test_key_' . uniqid();
        $testValue = ['user_id' => 42, 'token' => 'abc123xyz'];

        // Test set
        $setResult = $this->cache->set($testKey, $testValue, 60);
        $this->assertTrue($setResult);

        // Test get
        $found = false;
        $retrieved = $this->cache->get($testKey, $found);
        $this->assertTrue($found);
        $this->assertSame($testValue, $retrieved);

        // Test purge
        $purgeResult = $this->cache->purge($testKey);
        $this->assertTrue($purgeResult);

        // Test get after purge
        $foundAfterPurge = true;
        $retrievedAfterPurge = $this->cache->get($testKey, $foundAfterPurge);
        $this->assertFalse($foundAfterPurge);
        $this->assertFalse($retrievedAfterPurge);
    }
}
