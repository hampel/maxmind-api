<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Record\City as CityRecord;
use Hampel\MaxMind\Api\Record\Continent;
use Hampel\MaxMind\Api\Record\Country as CountryRecord;
use Hampel\MaxMind\Api\Record\Location;
use Hampel\MaxMind\Api\Record\MaxMind;
use Hampel\MaxMind\Api\Record\Postal;
use Hampel\MaxMind\Api\Record\RepresentedCountry;
use Hampel\MaxMind\Api\Record\Subdivision;
use Hampel\MaxMind\Api\Record\Traits;
use Hampel\MaxMind\Api\Support\Cast;

/**
 * The GeoIP2 City Plus web service, or a City or Enterprise database - everything Country has,
 * and the city, position, postal code and subdivisions, and the network owner in `traits`.
 *
 * GeoLite2 City has the place fields and lacks the network owner: `isp`, `organization`, `domain`
 * and `connectionType` are null from it.
 */
class City extends Country
{
    /**
     * @param  list<Subdivision>  $subdivisions  largest first - state, then county
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        Continent $continent = new Continent(),
        CountryRecord $country = new CountryRecord(),
        CountryRecord $registeredCountry = new CountryRecord(),
        RepresentedCountry $representedCountry = new RepresentedCountry(),
        Traits $traits = new Traits(),
        MaxMind $maxmind = new MaxMind(),
        public readonly CityRecord $city = new CityRecord(),
        public readonly Location $location = new Location(),
        public readonly Postal $postal = new Postal(),
        public readonly array $subdivisions = [],
        array $raw = [],
    ) {
        parent::__construct($continent, $country, $registeredCountry, $representedCountry, $traits, $maxmind, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(...self::countryParts($raw), ...self::cityParts($raw), raw: $raw);
    }

    /**
     * The smallest subdivision MaxMind has - the county rather than the state when there are two.
     * An empty record when there is none.
     */
    public function mostSpecificSubdivision(): Subdivision
    {
        return $this->subdivisions[count($this->subdivisions) - 1] ?? new Subdivision();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{CityRecord, Location, Postal, list<Subdivision>}
     */
    protected static function cityParts(array $raw): array
    {
        return [
            CityRecord::fromArray(Cast::object($raw['city'] ?? null)),
            Location::fromArray(Cast::object($raw['location'] ?? null)),
            Postal::fromArray(Cast::object($raw['postal'] ?? null)),
            Cast::objects($raw['subdivisions'] ?? null, Subdivision::fromArray(...)),
        ];
    }
}
