<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * The GeoIP2 Connection Type database - `Cable/DSL`, `Cellular`, `Corporate`, `Satellite`.
 *
 * Kept a string, not an enum: MaxMind has renamed and added values before (`Dialup` was retired),
 * and an enum would turn a new value into a null.
 */
final class ConnectionType extends Network
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $connectionType = null,
        ?string $ipAddress = null,
        ?string $network = null,
        array $raw = [],
    ) {
        parent::__construct($ipAddress, $network, $raw);
    }

    public static function fromDatabase(array $record, string $ipAddress, ?string $network): static
    {
        return new self(Cast::string($record['connection_type'] ?? null), $ipAddress, $network, $record);
    }
}
