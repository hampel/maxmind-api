<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

/**
 * The base of every answer about an address, from a web service or a database file.
 *
 * MODELS ARE DATA AND HOLD NO CONNECTION. `raw` is the answer as it arrived - the decoded JSON, or
 * the record from the file with `traits.ip_address` and `traits.network` added - so a field MaxMind
 * adds before this package models it is still readable.
 */
abstract class Model implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly array $raw = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->raw;
    }
}
