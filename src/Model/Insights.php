<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Record\Anonymizer;
use Hampel\MaxMind\Api\Record\City as CityRecord;
use Hampel\MaxMind\Api\Record\Continent;
use Hampel\MaxMind\Api\Record\Country as CountryRecord;
use Hampel\MaxMind\Api\Record\Location;
use Hampel\MaxMind\Api\Record\MaxMind;
use Hampel\MaxMind\Api\Record\Postal;
use Hampel\MaxMind\Api\Record\RepresentedCountry;
use Hampel\MaxMind\Api\Record\Traits;
use Hampel\MaxMind\Api\Support\Cast;

/**
 * The GeoIP2 Insights web service - everything City Plus has, with `confidence` on the place
 * records, income and density on `location`, the risk and user fields on `traits`, and the
 * `anonymizer` section, which is Insights-only and the usual reason to pay for it.
 */
final class Insights extends City
{
    /**
     * @param  list<\Hampel\MaxMind\Api\Record\Subdivision>  $subdivisions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        Continent $continent = new Continent(),
        CountryRecord $country = new CountryRecord(),
        CountryRecord $registeredCountry = new CountryRecord(),
        RepresentedCountry $representedCountry = new RepresentedCountry(),
        Traits $traits = new Traits(),
        MaxMind $maxmind = new MaxMind(),
        CityRecord $city = new CityRecord(),
        Location $location = new Location(),
        Postal $postal = new Postal(),
        array $subdivisions = [],
        public readonly Anonymizer $anonymizer = new Anonymizer(),
        array $raw = [],
    ) {
        parent::__construct(
            $continent,
            $country,
            $registeredCountry,
            $representedCountry,
            $traits,
            $maxmind,
            $city,
            $location,
            $postal,
            $subdivisions,
            $raw
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            ...self::countryParts($raw),
            ...self::cityParts($raw),
            anonymizer: Anonymizer::fromArray(Cast::object($raw['anonymizer'] ?? null)),
            raw: $raw
        );
    }
}
