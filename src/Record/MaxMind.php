<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `maxmind` - about the account rather than the address. Web services only.
 *
 * `queriesRemaining` counts down with every lookup. When it reaches zero, the next lookup is an
 * InsufficientFundsException, so it is the number to show an administrator before that happens.
 */
final class MaxMind extends Record
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $queriesRemaining = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::int($row['queries_remaining'] ?? null),
            $row,
        );
    }
}
