<?php

declare(strict_types=1);

namespace Integrations\Console\Concerns;

use Illuminate\Console\Command;

/**
 * Shared parsing for a `--limit` option, and the honest reporting that has to
 * go with one.
 *
 * A cap that isn't announced reads as a complete answer: "50 row(s) with no
 * external ID" when there are two hundred sends someone away believing they've
 * seen the problem. Every command that truncates says so.
 *
 * @phpstan-require-extends Command
 */
trait ParsesLimitOption
{
    /**
     * The `--limit` value, or false when it's malformed (having already told the
     * operator why). Null means the option was omitted and the command's own
     * default applies.
     */
    protected function parseLimit(?int $default = null): int|false|null
    {
        $option = $this->option('limit');

        if (! is_string($option) || $option === '') {
            return $default;
        }

        if (! ctype_digit($option) || (int) $option <= 0) {
            $this->error('The --limit option must be a positive integer.');

            return false;
        }

        return (int) $option;
    }

    /**
     * Warn when a listing stopped at its cap. Deliberately says "at least",
     * because a command that stops early doesn't know the total and shouldn't
     * imply it does.
     */
    protected function warnIfLimitReached(int $shown, ?int $limit, string $noun): void
    {
        if ($limit === null || $shown < $limit) {
            return;
        }

        $this->warn("Stopped at the --limit of {$limit}; there may be more {$noun}. Raise it to see the rest.");
    }
}
