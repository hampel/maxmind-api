<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `country` or `registered_country` - where MaxMind places the address, and where the block it
 * belongs to is registered. They differ for a multinational's network or a mobile carrier's
 * roaming range, which is why both exist.
 *
 * `isoCode` is ISO 3166-1 alpha-2, the field to key a rule on. `confidence` is 0-100 and arrives
 * only from Insights and the Enterprise database. `isInEuropeanUnion` is false when absent, as
 * MaxMind documents - it sends the key only when it is true.
 */
class Country extends Record
{
    use Names;

    /**
     * @param  array<string, string>  $names
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $isoCode = null,
        public readonly ?int $geonameId = null,
        public readonly array $names = [],
        public readonly ?int $confidence = null,
        public readonly bool $isInEuropeanUnion = false,
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
            Cast::string($row['iso_code'] ?? null),
            Cast::int($row['geoname_id'] ?? null),
            Cast::names($row['names'] ?? null),
            Cast::int($row['confidence'] ?? null),
            Cast::flag($row['is_in_european_union'] ?? null),
            $row,
        );
    }
}
