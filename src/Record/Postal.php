<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

use Hampel\MaxMind\Api\Support\Cast;

/**
 * `postal` - a postal code, which for some countries is only its first part (`EC1A` for a UK
 * code). `confidence` is from Insights and Enterprise only.
 */
final class Postal extends Record
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $code = null,
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
            Cast::string($row['code'] ?? null),
            Cast::int($row['confidence'] ?? null),
            $row,
        );
    }
}
