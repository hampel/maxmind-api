<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * One entry of `subdivisions` - a state, province or region. `isoCode` is the ISO 3166-2 code
 * WITHOUT its country prefix: `NSW`, not `AU-NSW`.
 */
final class Subdivision extends Record
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
            $row,
        );
    }
}
