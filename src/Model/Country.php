<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Record\Continent;
use Hampel\MaxMind\Api\Record\Country as CountryRecord;
use Hampel\MaxMind\Api\Record\MaxMind;
use Hampel\MaxMind\Api\Record\RepresentedCountry;
use Hampel\MaxMind\Api\Record\Traits;
use Hampel\MaxMind\Api\Support\Cast;

/**
 * The GeoIP2 Country web service, or a Country database.
 *
 * THE THREE TIERS EXTEND EACH OTHER - Country, City, Insights - so a function that needs only a
 * country takes a Country and accepts all three, and `instanceof Insights` is how to ask whether
 * an answer can say anything about anonymity.
 *
 * `maxmind` is filled by the web services only; a database file has no account to report on.
 */
class Country extends Model
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly Continent $continent = new Continent(),
        public readonly CountryRecord $country = new CountryRecord(),
        public readonly CountryRecord $registeredCountry = new CountryRecord(),
        public readonly RepresentedCountry $representedCountry = new RepresentedCountry(),
        public readonly Traits $traits = new Traits(),
        public readonly MaxMind $maxmind = new MaxMind(),
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(...self::countryParts($raw), raw: $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{Continent, CountryRecord, CountryRecord, RepresentedCountry, Traits, MaxMind}
     */
    protected static function countryParts(array $raw): array
    {
        return [
            Continent::fromArray(Cast::object($raw['continent'] ?? null)),
            CountryRecord::fromArray(Cast::object($raw['country'] ?? null)),
            CountryRecord::fromArray(Cast::object($raw['registered_country'] ?? null)),
            RepresentedCountry::fromArray(Cast::object($raw['represented_country'] ?? null)),
            Traits::fromArray(Cast::object($raw['traits'] ?? null)),
            MaxMind::fromArray(Cast::object($raw['maxmind'] ?? null)),
        ];
    }
}
