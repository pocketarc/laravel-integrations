<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Illuminate\Support\Facades\File;
use Integrations\Tests\TestCase;

class MakeProviderCommandTest extends TestCase
{
    /** @var list<string> */
    private array $generated = [];

    protected function tearDown(): void
    {
        foreach ($this->generated as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, bool>  $options
     */
    private function generate(string $class, array $options): string
    {
        $this->artisan('make:integration-provider', ['name' => $class, ...$options])
            ->assertSuccessful();

        $path = app_path("Integrations/{$class}.php");
        $this->generated[] = $path;

        return File::get($path);
    }

    public function test_rate_limit_alone_declares_the_interface_without_sync(): void
    {
        $contents = $this->generate('RateLimitOnlyProvider', ['--rate-limit' => true]);

        $this->assertStringContainsString('use Integrations\Contracts\DeclaresRateLimit;', $contents);
        $this->assertStringContainsString('use Integrations\RateLimit;', $contents);
        $this->assertStringContainsString('implements DeclaresRateLimit, IntegrationProvider', $contents);
        $this->assertStringContainsString('public function defaultRateLimit(): ?RateLimit', $contents);

        // A request-only provider gets none of the sync surface.
        $this->assertStringNotContainsString('HasScheduledSync', $contents);
        $this->assertStringNotContainsString('public function sync(', $contents);
    }

    public function test_sync_and_rate_limit_together_do_not_duplicate(): void
    {
        $contents = $this->generate('SyncAndRateLimitProvider', ['--sync' => true, '--rate-limit' => true]);

        // HasScheduledSync extends DeclaresRateLimit, so the interface is
        // implied and never listed or imported alongside it.
        $this->assertStringContainsString('implements HasScheduledSync, IntegrationProvider', $contents);
        $this->assertStringNotContainsString('DeclaresRateLimit', $contents);

        // defaultRateLimit() is emitted exactly once, and RateLimit imported once.
        $this->assertSame(1, substr_count($contents, 'public function defaultRateLimit('));
        $this->assertSame(1, substr_count($contents, 'use Integrations\RateLimit;'));
    }

    public function test_sync_alone_still_emits_default_rate_limit(): void
    {
        $contents = $this->generate('SyncOnlyProvider', ['--sync' => true]);

        $this->assertStringContainsString('implements HasScheduledSync, IntegrationProvider', $contents);
        $this->assertStringNotContainsString('DeclaresRateLimit', $contents);
        $this->assertSame(1, substr_count($contents, 'public function defaultRateLimit('));
    }
}
