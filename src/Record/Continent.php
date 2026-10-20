<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `continent` - a two-letter code (`EU`, `OC`), a GeoNames id and names.
 */
final class Continent extends Record
{
    use Names;

    /**
     * @param  array<string, string>  $names
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $code = null,
        public readonly ?int $geonameId = null,
        public readonly array $names = [],
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
            Cast::string($row['code'] ?? null),
            Cast::int($row['geoname_id'] ?? null),
            Cast::names($row['names'] ?? null),
            $row,
        );
    }
}
