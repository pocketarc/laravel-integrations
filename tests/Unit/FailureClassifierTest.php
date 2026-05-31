<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Integrations\Enums\FailureClass;
use Integrations\Exceptions\CircuitOpenException;
use Integrations\Exceptions\RetryableException;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Support\FailureClassifier;
use Integrations\Tests\Fixtures\ClassifyingProvider;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FailureClassifierTest extends TestCase
{
    protected function tearDown(): void
    {
        ClassifyingProvider::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, array{int, FailureClass}>
     */
    public static function statusProvider(): array
    {
        return [
            '500 -> upstream' => [500, FailureClass::Upstream],
            '502 -> upstream' => [502, FailureClass::Upstream],
            '503 -> upstream' => [503, FailureClass::Upstream],
            '504 -> upstream' => [504, FailureClass::Upstream],
            '501 -> client' => [501, FailureClass::Client],
            '429 -> throttle' => [429, FailureClass::Throttle],
            '400 -> client' => [400, FailureClass::Client],
            '401 -> client' => [401, FailureClass::Client],
            '403 -> client' => [403, FailureClass::Client],
            '404 -> client' => [404, FailureClass::Client],
            '422 -> client' => [422, FailureClass::Client],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_classifies_by_http_status(int $status, FailureClass $expected): void
    {
        $e = new HttpException($status, 'boom');

        $this->assertSame($expected, FailureClassifier::classify($e));
    }

    public function test_classifies_wrapped_guzzle_status(): void
    {
        $request = new Request('GET', 'https://example.com');
        $guzzle = new GuzzleRequestException('down', $request, new Response(503));
        $wrapper = new RuntimeException('SDK error', 0, $guzzle);

        $this->assertSame(FailureClass::Upstream, FailureClassifier::classify($wrapper));
    }

    public function test_connection_exception_is_upstream(): void
    {
        $this->assertSame(FailureClass::Upstream, FailureClassifier::classify(new ConnectionException('refused')));
    }

    public function test_wrapped_connection_exception_is_upstream(): void
    {
        $wrapper = new RuntimeException('SDK error', 0, new ConnectionException('refused'));

        $this->assertSame(FailureClass::Upstream, FailureClassifier::classify($wrapper));
    }

    public function test_unrecognised_exception_is_unknown(): void
    {
        // No HTTP status, no connection error: not enough evidence to penalise.
        $this->assertSame(FailureClass::Unknown, FailureClassifier::classify(new RuntimeException('mystery')));
    }

    public function test_bare_retryable_exception_is_unknown(): void
    {
        // Retryability and upstream-failure are separate axes now.
        $this->assertSame(FailureClass::Unknown, FailureClassifier::classify(new RetryableException('later')));
    }

    public function test_circuit_open_exception_is_unknown(): void
    {
        $e = new CircuitOpenException(
            Integration::make(['name' => 'Test']),
            CarbonImmutable::now(),
            60,
        );

        $this->assertSame(FailureClass::Unknown, FailureClassifier::classify($e));
    }

    public function test_provider_verdict_overrides_status(): void
    {
        ClassifyingProvider::$result = FailureClass::Throttle;
        $provider = new ClassifyingProvider;

        // Status says client (418), provider says throttle: provider wins.
        $e = new HttpException(418, 'teapot');

        $this->assertSame(FailureClass::Throttle, FailureClassifier::classify($e, $provider));
    }

    public function test_provider_null_defers_to_defaults(): void
    {
        ClassifyingProvider::$result = null;
        $provider = new ClassifyingProvider;

        $e = new HttpException(503, 'down');

        $this->assertSame(FailureClass::Upstream, FailureClassifier::classify($e, $provider));
    }

    public function test_duck_typed_get_status_code(): void
    {
        $e = new class('sdk') extends RuntimeException
        {
            public function getStatusCode(): int
            {
                return 503;
            }
        };

        $this->assertSame(FailureClass::Upstream, FailureClassifier::classify($e));
    }

    public function test_duck_typed_get_http_status_code(): void
    {
        // Postmark-style accessor.
        $e = new class('sdk') extends RuntimeException
        {
            public function getHttpStatusCode(): int
            {
                return 429;
            }
        };

        $this->assertSame(FailureClass::Throttle, FailureClassifier::classify($e));
    }

    public function test_get_code_used_only_when_in_http_range(): void
    {
        // GitHub-style: getCode() carries the HTTP status.
        $inRange = new RuntimeException('boom', 500);
        $this->assertSame(FailureClass::Upstream, FailureClassifier::classify($inRange));

        // A vendor error code outside the HTTP range is ignored.
        $outOfRange = new RuntimeException('boom', 40012);
        $this->assertSame(FailureClass::Unknown, FailureClassifier::classify($outOfRange));

        // The default 0 code is ignored too.
        $this->assertSame(FailureClass::Unknown, FailureClassifier::classify(new RuntimeException('boom')));
    }

    public function test_resolves_provider_from_integration_manager(): void
    {
        app(IntegrationManager::class)->register('test', TestProvider::class);
        $integration = Integration::create(['provider' => 'test', 'name' => 'Test']);

        // TestProvider doesn't classify, so it defers to the status default.
        $this->assertSame(
            FailureClass::Upstream,
            FailureClassifier::classify(new HttpException(500, 'down'), $integration->provider()),
        );
    }

    /**
     * @return array<string, array{FailureClass, bool}>
     */
    public static function countsProvider(): array
    {
        return [
            'upstream counts' => [FailureClass::Upstream, true],
            'throttle does not' => [FailureClass::Throttle, false],
            'client does not' => [FailureClass::Client, false],
            'unknown does not' => [FailureClass::Unknown, false],
        ];
    }

    #[DataProvider('countsProvider')]
    public function test_counts_as_failure_truth_table(FailureClass $class, bool $counts): void
    {
        $this->assertSame($counts, $class->countsAsFailure());
    }

    /**
     * @return array<string, array{FailureClass, bool}>
     */
    public static function retryableProvider(): array
    {
        return [
            'upstream retryable' => [FailureClass::Upstream, true],
            'throttle retryable' => [FailureClass::Throttle, true],
            'client not retryable' => [FailureClass::Client, false],
            'unknown not retryable' => [FailureClass::Unknown, false],
        ];
    }

    #[DataProvider('retryableProvider')]
    public function test_is_retryable_truth_table(FailureClass $class, bool $retryable): void
    {
        $this->assertSame($retryable, $class->isRetryable());
    }
}
