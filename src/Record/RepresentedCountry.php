<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `represented_country` - the country an address is used on behalf of, when that is not where it
 * is: a military base abroad is `"type": "military"`. Usually absent.
 */
final class RepresentedCountry extends Country
{
    /**
     * @param  array<string, string>  $names
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        ?string $isoCode = null,
        ?int $geonameId = null,
        array $names = [],
        ?int $confidence = null,
        bool $isInEuropeanUnion = false,
        public readonly ?string $type = null,
        array $raw = [],
    ) {
        parent::__construct($isoCode, $geonameId, $names, $confidence, $isInEuropeanUnion, $raw);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            Cast::string($row['iso_code'] ?? null),
            Cast::int($row['geoname_id'] ?? null),
            Cast::names($row['names'] ?? null),
            Cast::int($row['confidence'] ?? null),
            Cast::flag($row['is_in_european_union'] ?? null),
            Cast::string($row['type'] ?? null),
            $row,
        );
    }
}
