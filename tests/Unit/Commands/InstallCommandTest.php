<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\IntegrationManager;
use Integrations\Models\Integration;
use Integrations\Tests\Fixtures\InstallableProvider;
use Integrations\Tests\Fixtures\OptionalRuleProvider;
use Integrations\Tests\Fixtures\TestDataProvider;
use Integrations\Tests\Fixtures\TestProvider;
use Integrations\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(IntegrationManager::class);
        $manager->register('installable', InstallableProvider::class);
        $manager->register('test-data', TestDataProvider::class);
        $manager->register('test', TestProvider::class);
        $manager->register('optional-rule', OptionalRuleProvider::class);
    }

    public function test_errors_on_unknown_provider(): void
    {
        $this->artisan('integrations:install', ['provider' => 'does-not-exist'])
            ->assertFailed()
            ->expectsOutputToContain("Provider 'does-not-exist' is not registered");

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_installs_non_interactively_from_credential_flags(): void
    {
        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => [
                'api_key=key-123',
                'api_secret=shh',
                'region=eu-west-1',
                'timeout=10',
                'sandbox=true',
            ],
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Installed integration');

        $integration = Integration::query()->where('provider', 'installable')->firstOrFail();

        $this->assertSame('Installable', $integration->name);
        $credentials = $integration->credentialsArray();
        $this->assertSame('key-123', $credentials['api_key']);
        $this->assertSame('shh', $credentials['api_secret']);
        $this->assertSame('eu-west-1', $credentials['region']);
        $this->assertSame(10, $credentials['timeout']);
        $this->assertTrue($credentials['sandbox']);
    }

    public function test_prompts_only_for_required_credentials_interactively(): void
    {
        // Optional fields (region/timeout/sandbox) fall through to their
        // Data-class defaults without prompting. The test confirms only
        // api_key + api_secret are asked.
        $this->artisan('integrations:install', ['provider' => 'installable'])
            ->expectsQuestion('credential: api_key', 'key-abc')
            ->expectsQuestion('credential: api_secret', 'secret-xyz')
            ->assertSuccessful();

        $credentials = Integration::query()->firstOrFail()->credentialsArray();
        $this->assertSame('key-abc', $credentials['api_key']);
        $this->assertSame('secret-xyz', $credentials['api_secret']);
        // region has a null default and timeout/sandbox have typed defaults;
        // the Data cast materialises those when saving, so they're present
        // but carry the declared defaults rather than user input.
        $this->assertNull($credentials['region']);
        $this->assertSame(30, $credentials['timeout']);
        $this->assertFalse($credentials['sandbox']);
    }

    public function test_skipping_a_required_field_fails(): void
    {
        $this->artisan('integrations:install', ['provider' => 'installable'])
            ->expectsQuestion('credential: api_key', '')
            ->assertFailed()
            ->expectsOutputToContain("credential field 'api_key' is required");

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_missing_required_metadata_field_fails(): void
    {
        // test-data requires metadata.region. Without a flag and with an empty
        // prompt response, the command should abort before writing the row.
        $this->artisan('integrations:install', [
            'provider' => 'test-data',
            '--credential' => ['api_key=key'],
        ])
            ->expectsQuestion('metadata: region', '')
            ->assertFailed()
            ->expectsOutputToContain("metadata field 'region' is required");

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_updates_an_existing_integration_with_confirmation(): void
    {
        Integration::create([
            'provider' => 'installable',
            'name' => 'Installable',
            'credentials' => ['api_key' => 'old', 'api_secret' => 'old'],
        ]);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => [
                'api_key=new',
                'api_secret=new',
            ],
        ])
            ->expectsConfirmation(
                "An integration named 'Installable' already exists for 'installable'. Overwrite its credentials and metadata?",
                'yes',
            )
            ->assertSuccessful()
            ->expectsOutputToContain("Updated integration 'Installable'");

        $this->assertDatabaseCount('integrations', 1);
        $credentials = Integration::query()->firstOrFail()->credentialsArray();
        $this->assertSame('new', $credentials['api_key']);
    }

    public function test_declining_the_overwrite_leaves_the_existing_row_alone(): void
    {
        Integration::create([
            'provider' => 'installable',
            'name' => 'Installable',
            'credentials' => ['api_key' => 'old', 'api_secret' => 'old'],
        ]);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=new', 'api_secret=new'],
        ])
            ->expectsConfirmation(
                "An integration named 'Installable' already exists for 'installable'. Overwrite its credentials and metadata?",
                'no',
            )
            ->assertSuccessful()
            ->expectsOutputToContain('Aborted.');

        $credentials = Integration::query()->firstOrFail()->credentialsArray();
        $this->assertSame('old', $credentials['api_key']);
    }

    public function test_force_skips_the_overwrite_prompt(): void
    {
        Integration::create([
            'provider' => 'installable',
            'name' => 'Installable',
            'credentials' => ['api_key' => 'old', 'api_secret' => 'old'],
        ]);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=new', 'api_secret=new'],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('new', Integration::query()->firstOrFail()->credentialsArray()['api_key']);
    }

    public function test_name_option_overrides_the_default_provider_name(): void
    {
        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--name' => 'Installable Staging',
            '--credential' => ['api_key=k', 'api_secret=s'],
        ])->assertSuccessful();

        $this->assertDatabaseHas('integrations', [
            'provider' => 'installable',
            'name' => 'Installable Staging',
        ]);
    }

    public function test_health_check_runs_after_install_when_provider_supports_it(): void
    {
        $provider = new InstallableProvider;
        $this->app->instance(InstallableProvider::class, $provider);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=k', 'api_secret=s'],
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('[PASS] Integration is reachable.');
    }

    public function test_failed_health_check_prompts_to_keep_or_roll_back(): void
    {
        $provider = new InstallableProvider;
        $provider->healthCheckResult = false;
        $this->app->instance(InstallableProvider::class, $provider);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=k', 'api_secret=s'],
        ])
            ->expectsConfirmation('Keep the integration configured anyway?', 'no')
            ->assertSuccessful()
            ->expectsOutputToContain('Installation rolled back.');

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_failed_health_check_on_update_restores_previous_credentials_instead_of_deleting(): void
    {
        // Seed an existing, working row. The update run will overwrite it with
        // new credentials, the health check will fail, and the user will say
        // "no, don't keep it". Previously this called delete() and nuked the
        // entire row; now we restore the captured pre-update snapshot.
        Integration::create([
            'provider' => 'installable',
            'name' => 'Installable',
            'credentials' => [
                'api_key' => 'old-key',
                'api_secret' => 'old-secret',
                'region' => 'us-east-1',
            ],
            'is_active' => true,
        ]);

        $provider = new InstallableProvider;
        $provider->healthCheckResult = false;
        $this->app->instance(InstallableProvider::class, $provider);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=new-key', 'api_secret=new-secret'],
        ])
            ->expectsConfirmation(
                "An integration named 'Installable' already exists for 'installable'. Overwrite its credentials and metadata?",
                'yes',
            )
            ->expectsConfirmation('Keep the integration configured anyway?', 'no')
            ->assertSuccessful()
            ->expectsOutputToContain('Rolled back to previous configuration.');

        $this->assertDatabaseCount('integrations', 1);
        $credentials = Integration::query()->firstOrFail()->credentialsArray();
        $this->assertSame('old-key', $credentials['api_key']);
        $this->assertSame('old-secret', $credentials['api_secret']);
        $this->assertSame('us-east-1', $credentials['region']);
    }

    public function test_force_keeps_integration_even_when_health_check_fails(): void
    {
        $provider = new InstallableProvider;
        $provider->healthCheckResult = false;
        $this->app->instance(InstallableProvider::class, $provider);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=k', 'api_secret=s'],
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('[FAIL] Health check did not pass.');

        $this->assertDatabaseCount('integrations', 1);
    }

    public function test_health_check_exceptions_are_surfaced_as_failures(): void
    {
        $provider = new InstallableProvider;
        $provider->healthCheckThrows = true;
        $this->app->instance(InstallableProvider::class, $provider);

        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=k', 'api_secret=s'],
            '--force' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Health check threw an exception: Health check exploded.');
    }

    public function test_malformed_credential_flag_is_warned_and_ignored(): void
    {
        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => ['api_key=k', 'no-equals-here', 'api_secret=s'],
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Ignoring malformed --credential value');

        $this->assertDatabaseCount('integrations', 1);
    }

    public function test_falls_back_to_rules_keys_when_no_data_class_is_declared(): void
    {
        // TestProvider has credentialRules() -> ['api_key' => '...'] but
        // credentialDataClass() returns null. Command should still prompt for api_key.
        $this->artisan('integrations:install', [
            'provider' => 'test',
            '--credential' => ['api_key=from-flag'],
        ])->assertSuccessful();

        $credentials = Integration::query()->firstOrFail()->credentialsArray();
        $this->assertSame('from-flag', $credentials['api_key']);
    }

    public function test_rules_only_field_without_required_marker_is_not_prompted(): void
    {
        // OptionalRuleProvider declares `note` as just 'string': no required,
        // no nullable. The installer should not prompt for it; only the
        // required `api_key` should be asked. If the installer prompted for
        // `note`, expectsQuestion would fail because we only expect one prompt.
        $this->artisan('integrations:install', ['provider' => 'optional-rule'])
            ->expectsQuestion('credential: api_key', 'key-abc')
            ->assertSuccessful();

        $credentials = Integration::query()->firstOrFail()->credentialsArray();
        $this->assertSame('key-abc', $credentials['api_key']);
        $this->assertArrayNotHasKey('note', $credentials);
    }

    public function test_non_numeric_int_flag_fails_validation_instead_of_silent_cast(): void
    {
        // timeout has rule 'nullable|integer'. Before the fix, (int)"not-a-number"
        // would silently become 0 and pass validation; now the raw string
        // reaches the validator and is rejected.
        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => [
                'api_key=k',
                'api_secret=s',
                'timeout=not-a-number',
            ],
        ])
            ->assertFailed()
            ->expectsOutputToContain('Invalid credentials');

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_unrecognized_bool_flag_fails_validation_instead_of_silent_cast(): void
    {
        // sandbox has rule 'nullable|boolean'. Before the fix, "maybe" would
        // coerce to false and save; now it reaches the validator unchanged.
        $this->artisan('integrations:install', [
            'provider' => 'installable',
            '--credential' => [
                'api_key=k',
                'api_secret=s',
                'sandbox=maybe',
            ],
        ])
            ->assertFailed()
            ->expectsOutputToContain('Invalid credentials');

        $this->assertDatabaseCount('integrations', 0);
    }
}
