<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `city` - City Plus, Insights and the City and Enterprise databases. `confidence` is 0-100, from
 * Insights and Enterprise only.
 */
final class City extends Record
{
    use Names;

    /**
     * @param  array<string, string>  $names
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
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
            Cast::int($row['geoname_id'] ?? null),
            Cast::names($row['names'] ?? null),
            Cast::int($row['confidence'] ?? null),
            $row,
        );
    }
}
