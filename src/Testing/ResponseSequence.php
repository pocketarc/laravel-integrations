<?php

declare(strict_types=1);

namespace Integrations\Testing;

class ResponseSequence
{
    private int $index = 0;

    /** @var list<mixed> */
    private array $responses;

    public function __construct(mixed ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function next(): mixed
    {
        $index = $this->index;

        if (! array_key_exists($index, $this->responses)) {
            return null;
        }

        $this->index++;

        return $this->responses[$index];
    }
}
