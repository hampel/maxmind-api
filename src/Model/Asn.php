<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * The autonomous system an address is routed by - the GeoLite2 ASN database, or the ASN fields of
 * the ISP database.
 */
final class Asn extends Network
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $autonomousSystemNumber = null,
        public readonly ?string $autonomousSystemOrganization = null,
        ?string $ipAddress = null,
        ?string $network = null,
        array $raw = [],
    ) {
        parent::__construct($ipAddress, $network, $raw);
    }

    public static function fromDatabase(array $record, string $ipAddress, ?string $network): static
    {
        return new self(
            Cast::int($record['autonomous_system_number'] ?? null),
            Cast::string($record['autonomous_system_organization'] ?? null),
            $ipAddress,
            $network,
            $record,
        );
    }
}
