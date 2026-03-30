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
        if ($this->index >= count($this->responses)) {
            return null;
        }

        return $this->responses[$this->index++];
    }
}
