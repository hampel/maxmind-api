<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * The GeoIP2 ISP database - who operates the network, and the ASN it is routed by.
 */
final class Isp extends Network
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?int $autonomousSystemNumber = null,
        public readonly ?string $autonomousSystemOrganization = null,
        public readonly ?string $isp = null,
        public readonly ?string $organization = null,
        public readonly ?string $mobileCountryCode = null,
        public readonly ?string $mobileNetworkCode = null,
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
            Cast::string($record['isp'] ?? null),
            Cast::string($record['organization'] ?? null),
            Cast::string($record['mobile_country_code'] ?? null),
            Cast::string($record['mobile_network_code'] ?? null),
            $ipAddress,
            $network,
            $record,
        );
    }
}
