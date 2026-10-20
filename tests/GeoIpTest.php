<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Config;
use Hampel\MaxMind\Api\Exception\AddressNotFoundException;
use Hampel\MaxMind\Api\Exception\ExceptionInterface;
use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use Hampel\MaxMind\Api\Exception\NotFoundException;
use Hampel\MaxMind\Api\Model\City;
use Hampel\MaxMind\Api\Model\Country;
use Hampel\MaxMind\Api\Model\Insights;
use PHPUnit\Framework\Attributes\DataProvider;

final class GeoIpTest extends TestCase
{
    /**
     * @return iterable<string, array{string, class-string<Country>}>
     */
    public static function services(): iterable
    {
        yield 'country' => ['country', Country::class];
        yield 'city' => ['city', City::class];
        yield 'insights' => ['insights', Insights::class];
    }

    /**
     * @param  class-string<Country>  $model
     */
    #[DataProvider('services')]
    public function testEachServiceHasItsPathAndItsModel(string $service, string $model): void
    {
        $this->pushMaxMindJson(200, self::fixture($service), $service);

        $geoip = $this->maxmind()->geoip();
        $answer = match ($service) {
            'country' => $geoip->country('2001:db8::1'),
            'city' => $geoip->city('2001:db8::1'),
            default => $geoip->insights('2001:db8::1'),
        };

        $this->assertSame($model, $answer::class);
        $this->assertSame('https://geoip.maxmind.com/geoip/v2.1/' . $service . '/2001:db8::1', (string) $this->client->lastRequest()->getUri());
        $this->assertSame('GET', $this->client->lastRequest()->getMethod());
    }

    public function testTheSandboxIsItsOwnHost(): void
    {
        $this->pushMaxMindJson(200, self::fixture('country'), 'country');

        $maxmind = $this->maxmind(Config::sandbox());
        $maxmind->geoip()->country('81.2.69.160');

        $this->assertTrue($maxmind->config()->isSandbox());
        $this->assertSame('sandbox.maxmind.com', $this->client->lastRequest()->getUri()->getHost());
    }

    public function testMeLooksUpWhoeverIsAsking(): void
    {
        $this->pushMaxMindJson(200, self::fixture('country'), 'country');

        $this->maxmind()->geoip()->country();

        $this->assertStringEndsWith('/country/me', (string) $this->client->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'a hostname' => ['example.com'];
        yield 'a path' => ['1.2.3.4/../../account'];
        yield 'an IPv6 zone id' => ['fe80::1%eth0'];
        yield 'an out-of-range octet' => ['1.2.3.256'];
    }

    #[DataProvider('badAddresses')]
    public function testAnAddressThatIsNotOneIsRefusedBeforeSending(string $address): void
    {
        try {
            $this->maxmind()->geoip()->country($address);
            $this->fail('should raise');
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf(ExceptionInterface::class, $e);
            $this->assertSame([], $this->client->requests);
        }
    }

    public function testNotFoundWithACodeIsAddressNotFound(): void
    {
        $this->pushError(404, 'IP_ADDRESS_NOT_FOUND', 'The value 1.2.3.4 is not in the database.');

        $this->expectException(AddressNotFoundException::class);

        $this->maxmind()->geoip()->insights('1.2.3.4');
    }

    /**
     * MaxMind documents a 404 with no body. The path is fixed, so the address is what is missing.
     */
    public function testNotFoundWithNoBodyIsAddressNotFoundToo(): void
    {
        $this->client->pushRaw(404, '');

        try {
            $this->maxmind()->geoip()->city('1.2.3.4');
            $this->fail('should raise');
        } catch (AddressNotFoundException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertNull($e->errorCode);
            $this->assertInstanceOf(NotFoundException::class, $e->getPrevious());
        }
    }
}
