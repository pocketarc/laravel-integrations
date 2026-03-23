<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit\Commands;

use Integrations\Models\Integration;
use Integrations\Tests\TestCase;

class ListCommandTest extends TestCase
{
    public function test_lists_integrations(): void
    {
        Integration::create(['provider' => 'zendesk', 'name' => 'Prod ZD']);
        Integration::create(['provider' => 'github', 'name' => 'GitHub']);

        $this->artisan('integrations:list')
            ->assertSuccessful()
            ->expectsOutputToContain('Prod ZD')
            ->expectsOutputToContain('GitHub');
    }

    public function test_empty_state(): void
    {
        $this->artisan('integrations:list')
            ->assertSuccessful()
            ->expectsOutputToContain('No integrations registered');
    }
}
