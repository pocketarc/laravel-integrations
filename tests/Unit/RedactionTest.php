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
        app(IntegrationManager::class)->register('redacting', RedactingProvider::class);

        $integration = Integration::create(['provider' => 'redacting', 'name' => 'Redacting']);
        $integration->refresh();

        $integration->requestAs(
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
}
