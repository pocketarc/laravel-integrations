<?php

declare(strict_types=1);

namespace Integrations\Console;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Integrations\Enums\RateLimitWindow;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Throwable;

class RateLimitCommand extends Command
{
    protected $signature = 'integrations:rate-limit
        {integration : The integration id}
        {action : One of set|clear|status}
        {--limit= : Requests allowed per window (set)}
        {--window= : Window length in seconds (set)}
        {--sliding : Use a sliding window instead of a fixed one (set)}
        {--until= : Expiry for the override (Carbon-parseable, e.g. "+1 hour")}';

    protected $description = "Inspect or override an integration's rate limit.";

    public function handle(): int
    {
        $integration = $this->resolveIntegration();
        if ($integration === null) {
            return self::FAILURE;
        }

        $action = $this->argument('action');
        if (! is_string($action) || ! in_array($action, ['set', 'clear', 'status'], true)) {
            $this->error('The action must be one of: set, clear, status.');

            return self::FAILURE;
        }

        return match ($action) {
            'set' => $this->setOverride($integration),
            'clear' => $this->clearOverride($integration),
            default => $this->showStatus($integration),
        };
    }

    private function setOverride(Integration $integration): int
    {
        $limit = $this->intOption('limit');
        $window = $this->intOption('window');
        if ($limit === null || $window === null) {
            $this->error('--limit and --window are required positive integers for "set".');

            return self::FAILURE;
        }

        $until = $this->resolveUntil();
        if ($until === false) {
            return self::FAILURE;
        }

        try {
            $rateLimit = new RateLimit($limit, $window, $this->option('sliding') === true ? RateLimitWindow::Sliding : RateLimitWindow::Fixed);
        } catch (Throwable $e) {
            $this->error('Invalid rate limit: '.$e->getMessage());

            return self::FAILURE;
        }

        $integration->overrideRateLimit($rateLimit, $until);
        $this->info("Rate limit override set for '{$integration->name}'.");

        return $this->showStatus($integration);
    }

    private function clearOverride(Integration $integration): int
    {
        $integration->clearRateLimitOverride();
        $this->info("Rate limit override cleared for '{$integration->name}'.");

        return $this->showStatus($integration);
    }

    private function showStatus(Integration $integration): int
    {
        $effective = $integration->effectiveRateLimit();
        $hasOverride = is_array($integration->rate_limit_override);

        $this->table(['Field', 'Value'], [
            ['Integration', "{$integration->name} (#{$integration->id})"],
            ['Source', $hasOverride ? 'override' : ($effective !== null ? 'provider' : 'none')],
            ['Limit', $effective !== null ? (string) $effective->limit : 'unlimited'],
            ['Window (s)', $effective !== null ? (string) $effective->windowSeconds : '—'],
            ['Type', $effective !== null ? $effective->window->value : '—'],
            ['Override until', $integration->rate_limit_override_until?->toDateTimeString() ?? '—'],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return CarbonInterface|null|false false signals a validation failure.
     */
    private function resolveUntil(): CarbonInterface|null|false
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

        return $until;
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
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
}
