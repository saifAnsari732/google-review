<?php

namespace App\Tests\Unit\Security;

use App\Google\GoogleMap\Place\Core\Transport\Transport;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Challenge 5: TLS Verification Inspection
 *
 * Verifies that Transport.php enforces strict TLS peer and host validation
 * (CURLOPT_SSL_VERIFYPEER = true, CURLOPT_SSL_VERIFYHOST = 2, Guzzle verify = true)
 * and properly throws exceptions on connection or certificate failures.
 */
class TlsVerificationChallengeTest extends TestCase
{
    /**
     * Source Code Verification: Ensure strict TLS options are configured in Transport.php
     */
    public function testTransportSourceEnforcesStrictTls(): void
    {
        $transportFile = dirname(__DIR__, 3) . '/src/Google/GoogleMap/Place/Core/Transport/Transport.php';
        $code = file_get_contents($transportFile);

        // Verify CURLOPT_SSL_VERIFYPEER is set to true
        $this->assertMatchesRegularExpression(
            '/curl_setopt\(\s*\$ch\s*,\s*CURLOPT_SSL_VERIFYPEER\s*,\s*true\s*\);/i',
            $code,
            'Transport::viaCurl must explicitly set CURLOPT_SSL_VERIFYPEER to true'
        );

        // Verify CURLOPT_SSL_VERIFYHOST is set to 2
        $this->assertMatchesRegularExpression(
            '/curl_setopt\(\s*\$ch\s*,\s*CURLOPT_SSL_VERIFYHOST\s*,\s*2\s*\);/i',
            $code,
            'Transport::viaCurl must explicitly set CURLOPT_SSL_VERIFYHOST to 2'
        );

        // Verify Guzzle client enforces verify => true
        $this->assertMatchesRegularExpression(
            '/\'verify\'\s*=>\s*true/i',
            $code,
            'Transport::viaGuzzle must configure verify => true'
        );

        // Verify that insecure TLS bypass flags are nowhere in Transport.php
        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)/i',
            $code,
            'CURLOPT_SSL_VERIFYPEER must never be set to false or 0'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/CURLOPT_SSL_VERIFYHOST\s*,\s*(false|0)/i',
            $code,
            'CURLOPT_SSL_VERIFYHOST must never be set to false or 0'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\'verify\'\s*=>\s*false/i',
            $code,
            'Guzzle verify must never be set to false'
        );
    }

    /**
     * Whole Codebase Audit: Verify no file in src/ disables SSL/TLS verification
     */
    public function testEntireCodebaseHasNoSslVerificationBypass(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $content = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression(
                    '/CURLOPT_SSL_VERIFYPEER\s*,\s*(false|0)/i',
                    $content,
                    "Insecure CURLOPT_SSL_VERIFYPEER found in {$file->getPathname()}"
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/CURLOPT_SSL_VERIFYHOST\s*,\s*(false|0)/i',
                    $content,
                    "Insecure CURLOPT_SSL_VERIFYHOST found in {$file->getPathname()}"
                );
            }
        }
    }

    /**
     * Runtime Exception Verification: Transport throws Exception on failed TLS connection
     */
    public function testTransportThrowsExceptionOnFailedConnection(): void
    {
        $transport = new Transport();

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/(cURL error|Failed to connect)/i');

        // Attempting to connect to an unreachable HTTPS port triggers cURL error
        $transport->get('https://127.0.0.1:65534', '/api/test');
    }
}
