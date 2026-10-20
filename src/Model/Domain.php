<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * The GeoIP2 Domain database - the second-level domain the address's reverse DNS belongs to.
 */
final class Domain extends Network
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $domain = null,
        ?string $ipAddress = null,
        ?string $network = null,
        array $raw = [],
    ) {
        parent::__construct($ipAddress, $network, $raw);
    }

    public static function fromDatabase(array $record, string $ipAddress, ?string $network): static
    {
        return new self(Cast::string($record['domain'] ?? null), $ipAddress, $network, $record);
    }
}
