<?php

declare(strict_types=1);

namespace Integrations\Console;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Integrations\CircuitBreaker;
use Integrations\Models\Integration;
use Integrations\Support\Config;
use Throwable;

class CircuitCommand extends Command
{
    protected $signature = 'integrations:circuit
        {integration : The integration id}
        {action : One of open|close|disable|auto|status}
        {--until= : Expiry for the override (Carbon-parseable, e.g. "+1 hour")}';

    protected $description = "Inspect or override an integration's circuit breaker.";

    public function handle(): int
    {
        $integration = $this->resolveIntegration();
        if ($integration === null) {
            return self::FAILURE;
        }

        $action = $this->argument('action');
        if (! is_string($action) || ! in_array($action, ['open', 'close', 'disable', 'auto', 'status'], true)) {
            $this->error('The action must be one of: open, close, disable, auto, status.');

            return self::FAILURE;
        }

        if ($action === 'status') {
            return $this->showStatus($integration);
        }

        $until = $this->resolveUntil($action);
        if ($until === false) {
            return self::FAILURE;
        }

        $this->applyAction($integration, $action, $until);
        $this->info("Circuit for '{$integration->name}' set to '{$action}'.");

        return $this->showStatus($integration);
    }

    private function applyAction(Integration $integration, string $action, ?CarbonInterface $until): void
    {
        match ($action) {
            'open' => $integration->forceCircuitOpen($until),
            'close' => $integration->forceCircuitClosed($until),
            'disable' => $integration->disableCircuit($until),
            default => $integration->clearCircuitOverride(),
        };
    }

    /**
     * @return CarbonInterface|null|false false signals a validation failure.
     */
    private function resolveUntil(string $action): CarbonInterface|null|false
    {
        $option = $this->option('until');
        if (! is_string($option) || $option === '') {
            return null;
        }

        try {
            $until = Carbon::parse($option);
        } catch (Throwable) {
            $this->error("Could not parse --until value: {$option}");

            return false;
        }

        if ($until->isPast()) {
            $this->error('The --until value must be in the future.');

            return false;
        }

        if ($action === 'auto') {
            $this->warn('--until is ignored for the "auto" action.');
        }

        return $until;
    }

    private function resolveIntegration(): ?Integration
    {
        $argument = $this->argument('integration');

        if (! is_string($argument) || ! ctype_digit($argument) || (int) $argument <= 0) {
            $this->error('The integration argument must be a positive integer id.');

            return null;
        }

        $integration = Integration::query()->find((int) $argument);

        if ($integration === null) {
            $this->error("Integration #{$argument} not found.");

            return null;
        }

        return $integration;
    }

    private function showStatus(Integration $integration): int
    {
        $override = $integration->effectiveCircuitOverride();
        $live = (new CircuitBreaker($integration))->inspect();
        $openedAt = $live['opened_at'];

        $this->table(['Field', 'Value'], [
            ['Integration', "{$integration->name} (#{$integration->id})"],
            ['Override', $override !== null ? $override->value : 'auto'],
            ['Override until', $integration->circuit_override_until?->toDateTimeString() ?? '—'],
            ['Live state', $live['state']],
            ['Failures', (string) $live['failures']],
            ['Opened at', $openedAt !== null ? Carbon::createFromTimestamp($openedAt)->toDateTimeString() : '—'],
        ]);

        if (! Config::circuitOverridesEnabled()) {
            $this->warn('Circuit overrides are disabled in config; any override above is ignored.');
        }

        return self::SUCCESS;
    }
}
