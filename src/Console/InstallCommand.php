<?php

declare(strict_types=1);

namespace Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Integrations\Console\Support\FieldIntrospector;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IntegrationProvider;
use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Spatie\LaravelData\Data;

use function Safe\preg_match;

/**
 * Interactive installer for integrations. Introspects the provider's
 * credentialDataClass() / metadataDataClass() to discover which fields to
 * ask about, prompts for each (masking secret-looking names), validates
 * with the provider's rules, optionally runs a health check, and upserts
 * the Integration row.
 *
 * Non-interactive callers can supply every field via repeatable --credential
 * and --metadata flags. Missing required fields under --no-interaction fail
 * fast rather than producing a half-configured row.
 */
class InstallCommand extends Command
{
    protected $signature = 'integrations:install
        {provider : The provider key registered with IntegrationManager}
        {--name= : A friendly name for this integration (defaults to the provider name)}
        {--credential=* : Credential field in key=value form (repeatable)}
        {--metadata=* : Metadata field in key=value form (repeatable)}
        {--force : Skip health-check confirmation and overwrite an existing row without asking}';

    protected $description = 'Install an integration: gather credentials, validate, and upsert the row.';

    public function handle(IntegrationManager $manager): int
    {
        $providerKey = $this->stringArgument('provider');
        if ($providerKey === null) {
            return self::FAILURE;
        }

        if (! $manager->has($providerKey)) {
            $this->error("Provider '{$providerKey}' is not registered. Register it in config/integrations.php before installing.");

            return self::FAILURE;
        }

        $provider = $manager->provider($providerKey);
        $name = $this->resolveName($provider);
        $force = (bool) $this->option('force');

        $existing = Integration::query()
            ->where('provider', $providerKey)
            ->where('name', $name)
            ->first();

        if ($existing !== null && ! $this->shouldOverwrite($providerKey, $name, $force)) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        // Captured before persistIntegration() mutates the row. If the health
        // check later fails and the user declines, we restore these attributes
        // instead of deleting the row, so an update can't nuke previously
        // working credentials.
        $previousState = $existing !== null ? $this->snapshot($existing) : null;

        $credentials = $this->gatherValues(
            'credential',
            $provider->credentialDataClass(),
            $provider->credentialRules(),
            $this->parseKeyValueFlags('credential'),
        );
        if ($credentials === null) {
            return self::FAILURE;
        }

        $metadata = $this->gatherValues(
            'metadata',
            $provider->metadataDataClass(),
            $provider->metadataRules(),
            $this->parseKeyValueFlags('metadata'),
        );
        if ($metadata === null) {
            return self::FAILURE;
        }

        $integration = $this->persistIntegration($providerKey, $name, $credentials, $metadata);

        $this->info($existing !== null
            ? "Updated integration '{$name}' (provider '{$providerKey}')."
            : "Installed integration '{$name}' (provider '{$providerKey}').",
        );

        if ($provider instanceof HasHealthCheck) {
            $this->runHealthCheck($provider, $integration, $force, $previousState);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{credentials: array<string, mixed>, metadata: array<string, mixed>, is_active: bool}
     */
    private function snapshot(Integration $integration): array
    {
        return [
            'credentials' => $integration->credentialsArray(),
            'metadata' => $this->metadataAsArray($integration),
            'is_active' => $integration->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadataAsArray(Integration $integration): array
    {
        $metadata = $integration->metadata;

        if ($metadata instanceof Data) {
            /** @var array<string, mixed> */
            return $metadata->toArray();
        }

        return is_array($metadata) ? $metadata : [];
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);
        if (! is_string($value) || $value === '') {
            $this->error("Missing required argument '{$name}'.");

            return null;
        }

        return $value;
    }

    private function resolveName(IntegrationProvider $provider): string
    {
        $fromOption = $this->option('name');

        if (is_string($fromOption) && $fromOption !== '') {
            return $fromOption;
        }

        return $provider->name();
    }

    private function shouldOverwrite(string $providerKey, string $name, bool $force): bool
    {
        if ($force) {
            return true;
        }

        return $this->confirm(
            "An integration named '{$name}' already exists for '{$providerKey}'. Overwrite its credentials and metadata?",
            default: false,
        );
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $metadata
     */
    private function persistIntegration(string $providerKey, string $name, array $credentials, array $metadata): Integration
    {
        $integration = Integration::query()->updateOrCreate(
            ['provider' => $providerKey, 'name' => $name],
            [
                'credentials' => $credentials,
                'metadata' => $metadata,
                'is_active' => true,
            ],
        );

        // Refresh so we pick up DB column defaults (health_status, etc.) that
        // updateOrCreate doesn't populate on the in-memory instance. Without
        // this, a downstream recordSuccess() sees health_status=null and
        // trips the event dispatcher's type check.
        $integration->refresh();

        return $integration;
    }

    /**
     * Parse repeatable `--flag=key=value` options into an associative array.
     * Warns and drops entries without an `=` separator; validation at the
     * next layer catches any resulting missing values.
     *
     * @return array<string, string>
     */
    private function parseKeyValueFlags(string $option): array
    {
        $values = $this->option($option);

        if (! is_array($values)) {
            return [];
        }

        $parsed = [];

        foreach ($values as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $separator = mb_strpos($entry, '=');
            if ($separator === false) {
                $this->warn("Ignoring malformed --{$option} value (expected key=value): {$entry}");

                continue;
            }

            $key = mb_substr($entry, 0, $separator);
            $value = mb_substr($entry, $separator + 1);

            if ($key !== '') {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }

    /**
     * Gather values for either credentials or metadata. Introspects the Data
     * class (if any) to discover field names/types; otherwise falls back to
     * the validation-rule keys. Returns null if the user cancels (interactive)
     * or required values are missing (non-interactive).
     *
     * @param  class-string<Data>|null  $dataClass
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $fromFlags
     * @return array<string, mixed>|null
     */
    private function gatherValues(string $label, ?string $dataClass, array $rules, array $fromFlags): ?array
    {
        $fields = FieldIntrospector::discover($dataClass, $rules);

        if ($fields === []) {
            return [];
        }

        $values = $this->resolveFieldValues($label, $fields, $fromFlags);
        if ($values === null) {
            return null;
        }

        return $this->validateValues($label, $values, $rules);
    }

    /**
     * @param  array<string, array{type: string, nullable: bool, hasDefault: bool, default: mixed, required: bool}>  $fields
     * @param  array<string, string>  $fromFlags
     * @return array<string, mixed>|null
     */
    private function resolveFieldValues(string $label, array $fields, array $fromFlags): ?array
    {
        $values = [];
        $interactive = $this->input->isInteractive();

        foreach ($fields as $name => $field) {
            if (array_key_exists($name, $fromFlags)) {
                $values[$name] = $this->castValue($fromFlags[$name], $field['type']);

                continue;
            }

            // Only prompt for fields the provider explicitly declared as
            // required. Optional fields (nullable, defaulted, or just not
            // marked required in the rules) fall through to whatever
            // default the Data class provides.
            if (! $field['required']) {
                continue;
            }

            $resolved = $this->resolveRequiredField($label, $name, $field, $interactive);
            if ($resolved === null) {
                return null;
            }

            $values[$name] = $resolved;
        }

        return $values;
    }

    /**
     * @param  array{type: string, nullable: bool, hasDefault: bool, default: mixed, required: bool}  $field
     */
    private function resolveRequiredField(string $label, string $name, array $field, bool $interactive): mixed
    {
        if (! $interactive) {
            $this->error("{$label} field '{$name}' is required but was not supplied; pass it via --{$label}={$name}=VALUE or run without --no-interaction.");

            return null;
        }

        $prompted = $this->promptField($label, $name, $field);

        if ($prompted === null) {
            $this->error("{$label} field '{$name}' is required.");

            return null;
        }

        return $prompted;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>|null
     */
    private function validateValues(string $label, array $values, array $rules): ?array
    {
        $validator = Validator::make($values, $rules);

        if ($validator->fails()) {
            $this->error("Invalid {$label}s:");

            foreach ($validator->errors()->all() as $message) {
                $this->line("  - {$message}");
            }

            return null;
        }

        return $values;
    }

    /**
     * Prompt for one required field. Returns null if the user left it blank.
     * Secret-looking names use masked input.
     *
     * @param  array{type: string, nullable: bool, hasDefault: bool, default: mixed, required: bool}  $field
     */
    private function promptField(string $label, string $name, array $field): mixed
    {
        $question = "{$label}: {$name}";

        $raw = $this->isSensitive($name)
            ? $this->secret($question)
            : $this->ask($question);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return $this->castValue($raw, $field['type']);
    }

    private function isSensitive(string $name): bool
    {
        return preg_match('/secret|token|key|password/i', $name) === 1;
    }

    /**
     * Coerce raw flag input into the constructor-declared type so it matches
     * the provider's validation rules, but only when the string clearly
     * represents that type. For ambiguous input ("abc" for int, "maybe" for
     * bool) return the raw string so the validator can reject it rather than
     * silently casting garbage to 0 / false.
     */
    private function castValue(string $raw, string $type): mixed
    {
        return match ($type) {
            'int' => $this->castInt($raw),
            'bool' => $this->castBool($raw),
            default => $raw,
        };
    }

    private function castInt(string $raw): int|string
    {
        $parsed = filter_var($raw, FILTER_VALIDATE_INT);

        return $parsed === false ? $raw : $parsed;
    }

    private function castBool(string $raw): bool|string
    {
        return match (mb_strtolower($raw)) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => $raw,
        };
    }

    /**
     * @param  array{credentials: array<string, mixed>, metadata: array<string, mixed>, is_active: bool}|null  $previousState
     */
    private function runHealthCheck(HasHealthCheck $provider, Integration $integration, bool $force, ?array $previousState): void
    {
        $this->line('Running health check...');

        try {
            $healthy = $provider->healthCheck($integration);
        } catch (\Throwable $e) {
            $this->warn("Health check threw an exception: {$e->getMessage()}");
            $healthy = false;
        }

        if ($healthy) {
            $this->info('  [PASS] Integration is reachable.');
            $integration->recordSuccess();

            return;
        }

        $this->error('  [FAIL] Health check did not pass.');

        if ($force || $this->confirm('Keep the integration configured anyway?', default: true)) {
            return;
        }

        // Fresh install: drop the row. Update: restore the row's previous
        // credentials/metadata/is_active so we don't lose whatever was
        // working before the user tried to reconfigure.
        if ($previousState === null) {
            $integration->delete();
            $this->info('Installation rolled back.');

            return;
        }

        $integration->update($previousState);
        $this->info('Rolled back to previous configuration.');
    }
}
