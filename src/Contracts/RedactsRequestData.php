<?php

declare(strict_types=1);

namespace Integrations\Contracts;

interface RedactsRequestData
{
    /**
     * Dot-notation paths to redact from stored request data.
     *
     * @return list<string>
     */
    public function sensitiveRequestFields(): array;

    /**
     * Dot-notation paths to redact from stored response data.
     *
     * @return list<string>
     */
    public function sensitiveResponseFields(): array;
}
