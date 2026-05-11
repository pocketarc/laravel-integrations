<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\PlainProvider;
use Integrations\Tests\TestCase;

class BinaryResponseTest extends TestCase
{
    private Integration $integration;

    protected function setUp(): void
    {
        parent::setUp();

        app(IntegrationManager::class)->register('plain', PlainProvider::class);

        $this->integration = Integration::create(['provider' => 'plain', 'name' => 'Plain']);
        $this->integration->refresh();
    }

    public function test_binary_response_is_replaced_with_marker_before_db_insert(): void
    {
        $pngBytes = "\x89PNG\r\n\x1A\n\0\0\0\rIHDR".random_bytes(100);

        $result = $this->integration->at('https://example.com/x.png')->get(fn () => $pngBytes);

        $this->assertSame($pngBytes, $result, 'Caller should still receive the raw binary body.');

        $request = $this->integration->requests()->latest()->first();
        $this->assertNotNull($request);
        $this->assertMatchesRegularExpression(
            '/^\[BINARY \d+ bytes sha256=[0-9a-f]{64}\]$/',
            (string) $request->response_data,
        );
        $this->assertStringContainsString(
            (string) strlen($pngBytes),
            (string) $request->response_data,
        );
        $this->assertStringContainsString(
            hash('sha256', $pngBytes),
            (string) $request->response_data,
        );
    }

    public function test_binary_response_nulls_expires_at_even_when_cache_for_was_set(): void
    {
        $pngBytes = "\x89PNG\r\n\x1A\n\0\0\0\rIHDR".random_bytes(50);

        $this->integration->request(
            endpoint: 'https://example.com/y.png',
            method: 'GET',
            callback: fn () => $pngBytes,
            cacheFor: now()->addHour(),
        );

        $request = $this->integration->requests()->latest()->first();
        $this->assertNotNull($request);
        $this->assertNull(
            $request->expires_at,
            'Binary responses must not become cache sources, even when cacheFor was set.',
        );
    }

    public function test_utf8_response_is_stored_unchanged_and_cache_for_is_preserved(): void
    {
        $json = '{"ok": true, "msg": "héllo"}';
        $cacheUntil = now()->addHour();

        $this->integration->request(
            endpoint: '/api/text',
            method: 'GET',
            callback: fn () => $json,
            cacheFor: $cacheUntil,
        );

        $request = $this->integration->requests()->latest()->first();
        $this->assertNotNull($request);
        $this->assertSame($json, $request->response_data);
        $this->assertNotNull($request->expires_at);
    }

    public function test_binary_request_data_is_replaced_with_marker_before_db_insert(): void
    {
        $binaryRequestData = "\x89PNG\r\n\x1A\n\0\0\0\rIHDR".random_bytes(80);

        $this->integration->request(
            endpoint: '/api/upload',
            method: 'POST',
            callback: fn () => ['received' => true],
            requestData: $binaryRequestData,
        );

        $request = $this->integration->requests()->latest()->first();
        $this->assertNotNull($request);
        $this->assertMatchesRegularExpression(
            '/^\[BINARY \d+ bytes sha256=[0-9a-f]{64}\]$/',
            (string) $request->request_data,
        );
        $this->assertStringContainsString(
            hash('sha256', $binaryRequestData),
            (string) $request->request_data,
        );
    }
}
