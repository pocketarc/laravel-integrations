<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Support\Redactor;
use Integrations\Tests\Fixtures\RedactingProvider;
use Integrations\Tests\Fixtures\TestTokenResponse;
use Integrations\Tests\TestCase;

class RedactionTest extends TestCase
{
    public function test_redacts_sensitive_request_fields(): void
    {
        $json = json_encode(['username' => 'admin', 'password' => 'secret123', 'data' => 'ok']);

        $result = Redactor::redact($json, ['password']);

        $decoded = json_decode($result, true);
        $this->assertSame('[REDACTED]', $decoded['password']);
        $this->assertSame('admin', $decoded['username']);
        $this->assertSame('ok', $decoded['data']);
    }

    public function test_redacts_nested_dot_notation_paths(): void
    {
        $json = json_encode(['user' => ['name' => 'John', 'ssn' => '123-45-6789']]);

        $result = Redactor::redact($json, ['user.ssn']);

        $decoded = json_decode($result, true);
        $this->assertSame('[REDACTED]', $decoded['user']['ssn']);
        $this->assertSame('John', $decoded['user']['name']);
    }

    public function test_returns_original_string_for_non_json(): void
    {
        $result = Redactor::redact('not json', ['field']);
        $this->assertSame('not json', $result);
    }

    public function test_empty_paths_returns_unchanged(): void
    {
        $json = json_encode(['secret' => 'value']);

        $result = Redactor::redact($json, []);
        $this->assertSame($json, $result);
    }

    public function test_persist_request_redacts_when_provider_implements_contract(): void
    {
        $integration = $this->redactingIntegration();

        $integration->request(
            endpoint: '/api/login',
            method: 'POST',
            responseClass: TestTokenResponse::class,
            callback: fn () => ['token' => 'secret-jwt-token', 'user' => 'admin'],
            requestData: json_encode(['password' => 'my-secret', 'username' => 'admin']),
        );

        $request = $integration->requests()->latest()->first();
        $this->assertNotNull($request);

        $requestData = json_decode($request->request_data, true);
        $this->assertSame('[REDACTED]', $requestData['password']);
        $this->assertSame('admin', $requestData['username']);

        $responseData = json_decode($request->response_data, true);
        $this->assertSame('[REDACTED]', $responseData['token']);
        $this->assertSame('admin', $responseData['user']);
    }

    public function test_a_body_that_decodes_to_inf_is_replaced_without_leaking_the_redacted_field(): void
    {
        $result = Redactor::redact('{"token":"secret-jwt-token","score":1e999}', ['token']);

        $this->assertStringStartsWith('[UNENCODABLE', $result);
        $this->assertStringNotContainsString('secret-jwt-token', $result);
    }

    public function test_a_redacted_response_that_decodes_to_inf_is_stored_as_a_marker_and_not_cached(): void
    {
        $integration = $this->redactingIntegration();

        $body = '{"token":"secret-jwt-token","score":1e999}';

        $result = $integration->request(
            endpoint: '/api/login',
            method: 'POST',
            callback: fn (): string => $body,
            cacheFor: now()->addHour(),
        );

        $this->assertSame($body, $result);

        $request = $integration->requests()->latest()->first();
        $this->assertNotNull($request);
        $this->assertTrue($request->response_success);
        $this->assertStringStartsWith('[UNENCODABLE', (string) $request->response_data);
        $this->assertStringNotContainsString('secret-jwt-token', (string) $request->response_data);
        $this->assertNull($request->expires_at);
    }

    public function test_an_unencodable_callback_value_is_stored_without_its_redacted_field(): void
    {
        $integration = $this->redactingIntegration();

        $integration->request(
            endpoint: '/api/login',
            method: 'POST',
            callback: fn (): array => ['token' => 'secret-jwt-token', 'score' => INF],
        );

        $request = $integration->requests()->latest()->first();
        $this->assertNotNull($request);
        $this->assertStringStartsWith('[UNENCODABLE', (string) $request->response_data);
        $this->assertStringNotContainsString('secret-jwt-token', (string) $request->response_data);
    }

    public function test_request_bodies_that_cannot_be_encoded_after_redaction_do_not_share_a_cached_response(): void
    {
        $integration = $this->redactingIntegration();

        $first = $integration->request(
            endpoint: '/api/login',
            method: 'POST',
            callback: fn (): array => ['user' => 'alice'],
            requestData: '{"password":"alice-secret","score":1e999}',
            cacheFor: now()->addHour(),
        );
        $second = $integration->request(
            endpoint: '/api/login',
            method: 'POST',
            callback: fn (): array => ['user' => 'bob'],
            requestData: '{"password":"bob-secret","score":1e999}',
            cacheFor: now()->addHour(),
        );

        $this->assertSame(['user' => 'alice'], $first);
        $this->assertSame(['user' => 'bob'], $second);
        $this->assertSame(2, $integration->requests()->count());
        $this->assertSame(0, $integration->requests()->whereNotNull('expires_at')->count());
    }

    private function redactingIntegration(): Integration
    {
        app(IntegrationManager::class)->register('redacting', RedactingProvider::class);

        return Integration::create(['provider' => 'redacting', 'name' => 'Redacting'])->refresh();
    }
}
