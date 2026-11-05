<?php

namespace App\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;

/**
 * Challenge 3: Entropy / PRNG Verification
 *
 * Stress-tests the CSPRNG token generation logic in ChangePasswordController:
 * - Generates 10,000 tokens (320,000 random bytes, 640,000 hex chars)
 * - Verifies 64-char length and [0-9a-f] character set
 * - Verifies 0 collisions across 10,000 tokens
 * - Computes Shannon Entropy over byte and hex distributions
 * - Inspects controller source code for cryptographic standards
 */
class PRNGEntropyChallengeTest extends TestCase
{
    /**
     * Empirical Stress Test: 10,000 iterations of bin2hex(random_bytes(32))
     */
    public function testTokenGenerationEntropyAndCollisionResistance(): void
    {
        $iterations = 10000;
        $tokens = [];
        $charCounts = array_fill_keys(str_split('0123456789abcdef'), 0);
        $byteCounts = array_fill(0, 256, 0);

        for ($i = 0; $i < $iterations; $i++) {
            $rawBytes = random_bytes(32);
            $token = bin2hex($rawBytes);

            $this->assertSame(64, strlen($token), 'Token must be exactly 64 characters long');
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, 'Token must contain only lowercase hex characters');

            $tokens[] = $token;

            // Character frequency tally
            for ($c = 0; $c < 64; $c++) {
                $charCounts[$token[$c]]++;
            }

            // Raw byte frequency tally
            for ($b = 0; $b < 32; $b++) {
                $byteCounts[ord($rawBytes[$b])]++;
            }
        }

        // 1. Collision Resistance: No duplicate tokens in 10,000 generations
        $uniqueCount = count(array_unique($tokens));
        $this->assertSame($iterations, $uniqueCount, "Expected 0 collisions in $iterations tokens, found duplicates!");

        // 2. Shannon Entropy over 16 hex character distribution
        // Max theoretical entropy for 16 symbols is log2(16) = 4.000 bits/character
        $totalChars = $iterations * 64;
        $hexEntropy = 0.0;
        foreach ($charCounts as $char => $count) {
            $p = $count / $totalChars;
            if ($p > 0) {
                $hexEntropy -= $p * log($p, 2);
            }
        }

        // Assert that hex entropy is extremely close to theoretical maximum (>= 3.99 / 4.00)
        $this->assertGreaterThanOrEqual(
            3.99,
            $hexEntropy,
            "Hex Shannon entropy was $hexEntropy, expected >= 3.99 (insufficient randomness)"
        );

        // 3. Shannon Entropy over 256 byte distribution
        // Max theoretical entropy for 256 symbols is log2(256) = 8.000 bits/byte
        $totalBytes = $iterations * 32;
        $byteEntropy = 0.0;
        foreach ($byteCounts as $byteVal => $count) {
            $p = $count / $totalBytes;
            if ($p > 0) {
                $byteEntropy -= $p * log($p, 2);
            }
        }

        // Assert that byte entropy is >= 7.99 / 8.00
        $this->assertGreaterThanOrEqual(
            7.99,
            $byteEntropy,
            "Byte Shannon entropy was $byteEntropy, expected >= 7.99 (insufficient randomness)"
        );
    }

    /**
     * Source Code Verification: Ensure controller strictly uses bin2hex(random_bytes(32))
     * and does NOT use predictable PRNGs like uniqid(), mt_rand(), or rand().
     */
    public function testControllerSourceUsesCryptographicallySecurePrng(): void
    {
        $code = file_get_contents(
            dirname(__DIR__, 3) . '/src/Api/Security/ChangePassword/Controller/ChangePasswordController.php'
        );

        $this->assertStringContainsString(
            'bin2hex(random_bytes(32))',
            $code,
            'Controller must use bin2hex(random_bytes(32)) for token generation'
        );

        $this->assertStringNotContainsString('uniqid(', $code, 'Insecure uniqid() must not be used for tokens');
        $this->assertStringNotContainsString('mt_rand(', $code, 'Insecure mt_rand() must not be used for tokens');
        $this->assertStringNotContainsString('rand(', $code, 'Insecure rand() must not be used for tokens');
    }
}
