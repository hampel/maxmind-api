<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

/**
 * The base of every section of a MaxMind answer - `country`, `city`, `traits` and the rest.
 *
 * RECORDS ARE ALWAYS PRESENT, EVEN WHEN THE SECTION WAS NOT. MaxMind omits a key rather than send
 * it empty, so a Country lookup has no `city` at all; the model still holds a City record, with
 * every property null. `$insights->city->name()` is null for an address MaxMind has no city for,
 * rather than an error, and isEmpty() says whether the section arrived.
 *
 * EVERY RECORD KEEPS `raw`, the section exactly as it arrived. A key MaxMind adds without a
 * version bump - it has done so twice in 2026 - is readable there before this package models it.
 */
abstract class Record implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $raw = [],
    ) {
    }

    /**
     * Whether the section was absent or empty in the answer.
     */
    public function isEmpty(): bool
    {
        return $this->raw === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
