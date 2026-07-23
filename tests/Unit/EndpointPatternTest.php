<?php

declare(strict_types=1);

namespace Integrations\Tests\Unit;

use Integrations\Support\EndpointPattern;
use Integrations\Tests\TestCase;

class EndpointPatternTest extends TestCase
{
    public function test_a_wildcard_matches_within_one_segment_only(): void
    {
        $this->assertTrue(EndpointPattern::matchesAny(['issues/*'], 'issues/42', 'GET'));
        $this->assertFalse(EndpointPattern::matchesAny(['issues/*'], 'issues/42/comments', 'GET'));
    }

    public function test_a_method_prefix_narrows_a_pattern_to_one_verb(): void
    {
        $this->assertTrue(EndpointPattern::matchesAny(['POST:embeddings'], 'embeddings', 'POST'));
        $this->assertFalse(EndpointPattern::matchesAny(['POST:embeddings'], 'embeddings', 'GET'));
    }

    public function test_an_exact_pattern_matches_without_a_wildcard(): void
    {
        $this->assertTrue(EndpointPattern::matchesAny(['chat/completions'], 'chat/completions', 'POST'));
        $this->assertFalse(EndpointPattern::matchesAny(['chat/completions'], 'chat/other', 'POST'));
    }

    public function test_a_non_string_entry_is_skipped_not_fatal(): void
    {
        // A non-string in a provider's pattern list must not crash the request
        // it gates; a valid pattern alongside the bad entries still matches.
        $this->assertTrue(EndpointPattern::matchesAny([123, null, 'chat/*'], 'chat/completions', 'POST'));
        $this->assertFalse(EndpointPattern::matchesAny([123, null], 'chat/completions', 'POST'));
    }

    public function test_an_empty_pattern_list_matches_nothing(): void
    {
        $this->assertFalse(EndpointPattern::matchesAny([], 'anything', 'GET'));
    }
}
