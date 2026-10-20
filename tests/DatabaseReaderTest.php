<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Database\Reader;
use Hampel\MaxMind\Api\Exception\AddressNotFoundException;
use Hampel\MaxMind\Api\Exception\ApiException;
use Hampel\MaxMind\Api\Exception\DatabaseException;
use Hampel\MaxMind\Api\Exception\DatabaseMismatchException;
use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use Hampel\MaxMind\Api\Model\City;
use Hampel\MaxMind\Api\Model\Country;
use Hampel\MaxMind\Api\Model\Insights;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Against MaxMind's own test databases, from MaxMind-DB's test-data - see fixtures/README.md for
 * where each address comes from.
 */
final class DatabaseReaderTest extends TestCase
{
    public function testACityDatabaseAnswersWithTheCityModel(): void
    {
        $city = Reader::open(self::database('GeoIP2-City'))->city('81.2.69.160');

        $this->assertSame(City::class, $city::class);
        $this->assertSame('GB', $city->country->isoCode);
        $this->assertSame('London', $city->city->name());
        $this->assertSame('Europe/London', $city->location->timeZone);
        $this->assertSame('ENG', $city->mostSpecificSubdivision()->isoCode);
        $this->assertNull($city->maxmind->queriesRemaining, 'a file has no account to report on');
    }

    /**
     * The two keys the web service sends and a file does not hold.
     */
    public function testTheAddressAndItsNetworkAreFilledIn(): void
    {
        $city = Reader::open(self::database('GeoIP2-City'))->city('81.2.69.170');

        $this->assertSame('81.2.69.170', $city->traits->ipAddress);
        $this->assertSame('81.2.69.160/27', $city->traits->network);
    }

    public function testAnIpv6AddressGetsAnIpv6Network(): void
    {
        $country = Reader::open(self::database('GeoIP2-Country'))->country('2001:218::1');

        $this->assertSame('JP', $country->country->isoCode);
        $this->assertSame('2001:218::/32', $country->traits->network);
    }

    /**
     * A City file is a superset of a Country one, so country() reads it.
     */
    public function testCountryReadsACityOrEnterpriseFile(): void
    {
        foreach (['GeoIP2-Country', 'GeoIP2-City', 'GeoLite2-City', 'GeoIP2-Enterprise'] as $file) {
            $this->assertSame('GB', Reader::open(self::database($file))->country('81.2.69.160')->country->isoCode, $file);
        }
    }

    public function testEnterpriseFillsConfidence(): void
    {
        $city = Reader::open(self::database('GeoIP2-Enterprise'))->city('2.125.160.216');

        $this->assertSame(50, $city->city->confidence);
        $this->assertSame(95, $city->country->confidence);
        $this->assertNotInstanceOf(Insights::class, $city);
    }

    /**
     * The same answer from either source: a model built from the web service's JSON and one built
     * from the file carry the same fields under the same names.
     */
    public function testAFileAndTheWebServiceGiveTheSameModel(): void
    {
        $fromFile = Reader::open(self::database('GeoIP2-City'))->city('81.2.69.160');
        $fromWeb = City::fromArray($fromFile->raw);

        $this->assertEquals($fromWeb, $fromFile);
    }

    public function testAnonymousIpGivesTheFlagsOnly(): void
    {
        $answer = Reader::open(self::database('GeoIP2-Anonymous-IP'))->anonymizer('1.2.0.1');

        $this->assertTrue($answer->anonymizer->isAnonymous);
        $this->assertTrue($answer->anonymizer->isAnonymousVpn);
        $this->assertNull($answer->anonymizer->confidence);
        $this->assertSame('1.2.0.0/16', $answer->network);
    }

    /**
     * `anonymizer_confidence` in the file is `confidence` in Insights, and the model holds one.
     */
    public function testAnonymousPlusFillsTheInsightsFields(): void
    {
        $answer = Reader::open(self::database('GeoIP-Anonymous-Plus'))->anonymizer('1.2.0.1');

        $this->assertSame(30, $answer->anonymizer->confidence);
        $this->assertSame('2025-04-14', $answer->anonymizer->networkLastSeen?->format('Y-m-d'));
        $this->assertSame('foo', $answer->anonymizer->providerName);
        $this->assertSame('1.2.0.1/32', $answer->network);
    }

    public function testResidentialProxyFillsOnlyTheResidentialFeed(): void
    {
        $answer = Reader::open(self::database('GeoIP-Residential-Proxy'))->anonymizer('1.2.0.4');

        $this->assertFalse($answer->anonymizer->isAnonymous);
        $this->assertNull($answer->anonymizer->confidence);
        $this->assertSame(82, $answer->anonymizer->residential->confidence);
        $this->assertSame('quickshift', $answer->anonymizer->residential->providerName);
        $this->assertSame('2026-05-11', $answer->anonymizer->residential->networkLastSeen?->format('Y-m-d'));
        $this->assertTrue($answer->anonymizer->isFlagged());
    }

    public function testTheSingleFieldDatabases(): void
    {
        $this->assertSame(1221, Reader::open(self::database('GeoLite2-ASN'))->asn('1.128.0.0')->autonomousSystemNumber);
        $this->assertSame('Telstra Internet', Reader::open(self::database('GeoIP2-ISP'))->isp('1.128.0.0')->isp);
        $this->assertSame(1221, Reader::open(self::database('GeoIP2-ISP'))->asn('1.128.0.0')->autonomousSystemNumber);
        $this->assertSame('Cellular', Reader::open(self::database('GeoIP2-Connection-Type'))->connectionType('80.214.0.0')->connectionType);
        $this->assertSame('maxmind.com', Reader::open(self::database('GeoIP2-Domain'))->domain('1.2.0.0')->domain);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mismatches(): iterable
    {
        yield 'city() on Country' => ['GeoIP2-Country', 'city'];
        yield 'anonymizer() on City' => ['GeoIP2-City', 'anonymizer'];
        yield 'country() on ASN' => ['GeoLite2-ASN', 'country'];
        yield 'isp() on ASN' => ['GeoLite2-ASN', 'isp'];
        yield 'domain() on ISP' => ['GeoIP2-ISP', 'domain'];
    }

    #[DataProvider('mismatches')]
    public function testALookupTheFileCannotAnswerIsRefusedNotEmpty(string $file, string $method): void
    {
        $reader = Reader::open(self::database($file));

        $this->assertFalse($reader->supports($method));
        $this->expectException(DatabaseMismatchException::class);
        $this->expectExceptionMessage($file);

        $reader->{$method}('1.2.0.0');
    }

    /**
     * The same class the web service raises, so a consumer with both sources catches one thing.
     */
    public function testAnAddressNotInTheFileIsAddressNotFound(): void
    {
        try {
            Reader::open(self::database('GeoIP2-City'))->city('10.0.0.1');
            $this->fail('should raise');
        } catch (AddressNotFoundException $e) {
            $this->assertInstanceOf(ApiException::class, $e);
            $this->assertSame(0, $e->statusCode);
            $this->assertSame('IP_ADDRESS_NOT_FOUND', $e->errorCode);
        }
    }

    public function testAnAddressThatIsNotOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Reader::open(self::database('GeoIP2-City'))->city('not an address');
    }

    public function testAFileThatIsNotADatabaseIsADatabaseException(): void
    {
        $this->expectException(DatabaseException::class);

        Reader::open(__FILE__);
    }

    public function testAMissingFileIsADatabaseException(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('no-such.mmdb');

        Reader::open(__DIR__ . '/no-such.mmdb');
    }

    public function testMetadata(): void
    {
        $reader = Reader::open(self::database('GeoLite2-Country'));

        $this->assertSame('GeoLite2-Country', $reader->databaseType());
        $this->assertSame('GeoLite2-Country', $reader->metadata()->databaseType);
        $this->assertSame('UTC', $reader->buildDate()->getTimezone()->getName());
        $this->assertGreaterThan(1_400_000_000, $reader->buildDate()->getTimestamp());
    }

    public function testRawReadsAnyFile(): void
    {
        [$record, $prefix] = Reader::open(self::database('GeoIP2-Domain'))->raw('1.2.0.0');

        $this->assertSame(['domain' => 'maxmind.com'], $record);
        $this->assertSame(16, $prefix);
    }

    public function testOneReaderAnswersManyLookups(): void
    {
        $reader = Reader::open(self::database('GeoIP2-Country'));
        $seen = [];

        foreach (['81.2.69.160', '81.2.69.161', '2.125.160.216'] as $address) {
            $seen[] = $reader->country($address)->country->isoCode;
        }

        $this->assertSame(['GB', 'GB', 'GB'], $seen);
        $this->assertInstanceOf(Country::class, $reader->country('81.2.69.160'));
    }
}
