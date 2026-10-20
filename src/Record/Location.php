<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `location` - an approximate position and how approximate.
 *
 * THE COORDINATES ARE THE CENTRE OF AN AREA, NOT A POINT. `accuracyRadius` is that area's radius
 * in kilometres, and MaxMind is explicit that the pair should not be used to identify a household
 * or a street address.
 *
 * `averageIncome` (US dollars) and `populationDensity` (people per km²) are Insights only.
 */
final class Location extends Record
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?int $accuracyRadius = null,
        public readonly ?string $timeZone = null,
        public readonly ?int $averageIncome = null,
        public readonly ?int $populationDensity = null,
        /** @deprecated MaxMind no longer maintains metro codes; US only, and not updated */
        public readonly ?int $metroCode = null,
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
            Cast::float($row['latitude'] ?? null),
            Cast::float($row['longitude'] ?? null),
            Cast::int($row['accuracy_radius'] ?? null),
            Cast::string($row['time_zone'] ?? null),
            Cast::int($row['average_income'] ?? null),
            Cast::int($row['population_density'] ?? null),
            Cast::int($row['metro_code'] ?? null),
            $row,
        );
    }
}
