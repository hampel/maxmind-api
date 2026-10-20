<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Model\City;
use Hampel\MaxMind\Api\Model\Country;
use Hampel\MaxMind\Api\Model\Insights;
use Hampel\MaxMind\Api\Model\Model;
use Hampel\MaxMind\Api\Record\Anonymizer;
use Hampel\MaxMind\Api\Record\Record;
use Hampel\MaxMind\Api\Support\Cast;
use PHPUnit\Framework\Attributes\DataProvider;

final class ModelTest extends TestCase
{
    /**
     * @return iterable<string, array{string, callable(array<string, mixed>): Model}>
     */
    public static function examples(): iterable
    {
        yield 'country' => ['country', Country::fromArray(...)];
        yield 'city' => ['city', City::fromArray(...)];
        yield 'insights' => ['insights', Insights::fromArray(...)];
    }

    /**
     * Walks each of MaxMind's example answers and checks that every key with a value arrives in a
     * property with a value, under its camelCase name. A key never mapped, or read under the wrong
     * wire name, fails here - which is how a field MaxMind adds gets noticed when the fixtures are
     * refreshed.
     *
     * @param  callable(array<string, mixed>): Model  $build
     */
    #[DataProvider('examples')]
    public function testEveryFieldInMaxMindsExamplesArrivesWithAValue(string $fixture, callable $build): void
    {
        $model = $build(self::fixture($fixture));

        $this->assertMapped($model, $model->raw, $fixture);
    }

    public function testTheTiersExtendEachOther(): void
    {
        $insights = Insights::fromArray(self::fixture('insights'));

        $this->assertInstanceOf(City::class, $insights);
        $this->assertInstanceOf(Country::class, $insights);
    }

    public function testTheInsightsExampleReadsAsExpected(): void
    {
        $insights = Insights::fromArray(self::fixture('insights'));

        $this->assertSame('US', $insights->country->isoCode);
        $this->assertSame(75, $insights->country->confidence);
        $this->assertSame('Los Angeles', $insights->city->name());
        $this->assertSame('Kalifornien', $insights->mostSpecificSubdivision()->name(['de']));
        $this->assertSame(54321, $insights->maxmind->queriesRemaining);
        $this->assertSame('1.2.3.0/24', $insights->traits->network);
        $this->assertSame(45.5, $insights->traits->ipRiskSnapshot);
        $this->assertSame('military', $insights->representedCountry->type);
        $this->assertTrue($insights->registeredCountry->isInEuropeanUnion);
        $this->assertSame(128321, $insights->location->averageIncome);

        $anonymizer = $insights->anonymizer;
        $this->assertSame(99, $anonymizer->confidence);
        $this->assertTrue($anonymizer->isAnonymousVpn);
        $this->assertSame('2025-01-15', $anonymizer->networkLastSeen?->format('Y-m-d'));
        $this->assertSame('nordvpn', $anonymizer->providerName);
        $this->assertSame(82, $anonymizer->residential->confidence);
        $this->assertSame('quickshift', $anonymizer->residential->providerName);
    }

    /**
     * MaxMind omits a key rather than send it empty. A Country answer has no city, and the model
     * still has a City record to read null from.
     */
    public function testAnAbsentSectionIsAnEmptyRecordNotAnError(): void
    {
        $country = City::fromArray(self::fixture('country'));

        $this->assertTrue($country->city->isEmpty());
        $this->assertNull($country->city->name());
        $this->assertNull($country->location->latitude);
        $this->assertSame([], $country->subdivisions);
        $this->assertTrue($country->mostSpecificSubdivision()->isEmpty());
        $this->assertFalse($country->country->isInEuropeanUnion);
    }

    public function testAnUnknownKeyIsIgnoredAndKeptOnRaw(): void
    {
        $answer = self::fixture('country');
        $answer['traits'] = ['something_new' => 'value'] + Cast::object($answer['traits'] ?? null);
        $answer['brand_new_section'] = ['x' => 1];

        $country = Country::fromArray($answer);

        $this->assertSame('value', $country->traits->raw['something_new']);
        $this->assertSame(['x' => 1], $country->raw['brand_new_section']);
        $this->assertSame($answer, $country->jsonSerialize());
    }

    public function testAMalformedSectionDoesNotTakeTheRestWithIt(): void
    {
        $country = Insights::fromArray([
            'country' => 'not an object',
            'subdivisions' => ['nor this', ['iso_code' => 'NSW']],
            'anonymizer' => ['confidence' => 'high', 'is_anonymous' => 'yes'],
            'traits' => ['ip_address' => '1.2.3.4'],
        ]);

        $this->assertNull($country->country->isoCode);
        $this->assertCount(1, $country->subdivisions);
        $this->assertSame('NSW', $country->subdivisions[0]->isoCode);
        $this->assertNull($country->anonymizer->confidence);
        $this->assertFalse($country->anonymizer->isAnonymous, 'only true is true');
        $this->assertSame('1.2.3.4', $country->traits->ipAddress);
    }

    public function testNamesFallBackThroughTheLocalesAskedForThenEnglish(): void
    {
        $country = Country::fromArray(self::fixture('country'));

        $this->assertSame('États-Unis', $country->country->name(['xx', 'fr']));
        $this->assertSame('United States', $country->country->name(['xx']));
        $this->assertSame('United States', $country->country->name());
        $this->assertNull((new Country())->country->name());
    }

    /**
     * Any confidence from 1 to 99, not an enumeration of 30 and 99.
     */
    public function testAnonymizerConfidenceIsAnyInteger(): void
    {
        $this->assertSame(50, Anonymizer::fromArray(['confidence' => 50])->confidence);
    }

    /**
     * The residential feed can be present when no flag is set, and that is still a finding.
     */
    public function testResidentialAloneMakesTheAddressFlagged(): void
    {
        $anonymizer = Anonymizer::fromArray(['residential' => ['confidence' => 82, 'provider_name' => 'quickshift']]);

        $this->assertFalse($anonymizer->isAnonymous);
        $this->assertTrue($anonymizer->hasResidential());
        $this->assertTrue($anonymizer->isFlagged());
        $this->assertFalse(Anonymizer::fromArray([])->isFlagged());
    }

    public function testAnUnparseableDateIsNull(): void
    {
        $this->assertNull(Anonymizer::fromArray(['network_last_seen' => '2025-13-45'])->networkLastSeen);
        $this->assertNull(Anonymizer::fromArray(['network_last_seen' => '15/01/2025'])->networkLastSeen);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function assertMapped(object $object, array $raw, string $path): void
    {
        foreach ($raw as $key => $value) {
            $where = $path . '.' . $key;

            if ($value === null) {
                continue;
            }

            $property = lcfirst(str_replace('_', '', ucwords($key, '_')));

            $this->assertTrue(property_exists($object, $property), sprintf('%s is not mapped: %s has no $%s', $where, $object::class, $property));

            $mapped = (new \ReflectionProperty($object, $property))->getValue($object);

            if ($mapped instanceof Record) {
                $this->assertIsArray($value);
                /** @var array<string, mixed> $value */
                $this->assertMapped($mapped, $value, $where);

                continue;
            }

            if (is_array($mapped) && array_is_list($mapped) && ($mapped[0] ?? null) instanceof Record) {
                foreach ($mapped as $i => $record) {
                    $this->assertInstanceOf(Record::class, $record);
                    $this->assertMapped($record, $record->raw, $where . '[' . $i . ']');
                }

                continue;
            }

            $this->assertTrue(
                $mapped !== null && $mapped !== [] && $mapped !== false,
                sprintf('%s has a value in the example and $%s on %s is empty', $where, $property, $object::class)
            );
        }
    }
}
