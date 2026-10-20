<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Hampel\MaxMind\Api\Authentication\AccountKey;
use Hampel\MaxMind\Api\Client;
use Hampel\MaxMind\Api\Config;
use Hampel\MaxMind\Api\Exception\RuntimeException;
use Hampel\MaxMind\Api\Support\Psr17Discovery;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Ported from xenforo-api, where the class was written.
 */
final class Psr17DiscoveryTest extends TestCase
{
    public function testAClientCanBeBuiltWithoutNamingAFactory(): void
    {
        $maxmind = new Client(Config::live(), new AccountKey(self::ACCOUNT_ID, self::LICENSE_KEY), $this->client);

        $this->assertInstanceOf(RequestFactoryInterface::class, $maxmind->connection()->requestFactory());
        $this->assertInstanceOf(StreamFactoryInterface::class, $maxmind->connection()->streamFactory());

        // and the found factory actually works end to end
        $this->pushMaxMindJson(200, self::fixture('country'), 'country');
        $this->assertSame('US', $maxmind->geoip()->country('1.2.3.4')->country->isoCode);
    }

    public function testOnThisMachineItFindsGuzzle(): void
    {
        [$request, $stream] = Psr17Discovery::find();

        $this->assertInstanceOf(HttpFactory::class, $request);
        $this->assertSame($request, $stream, 'Guzzle ships one class for both roles; it should be built once.');
    }

    public function testAFactoryThatIsGivenIsUsedRatherThanDiscovered(): void
    {
        $given = new HttpFactory();

        $maxmind = new Client(Config::live(), new AccountKey(self::ACCOUNT_ID, self::LICENSE_KEY), $this->client, $given, $given);

        $this->assertSame($given, $maxmind->connection()->requestFactory());
        $this->assertSame($given, $maxmind->connection()->streamFactory());
    }

    public function testTheShortFormDiscoversItsFactories(): void
    {
        $maxmind = Client::withAccountKey(self::ACCOUNT_ID, self::LICENSE_KEY, $this->client);

        $this->assertInstanceOf(RequestFactoryInterface::class, $maxmind->connection()->requestFactory());
    }

    /**
     * The not-found path cannot be reached with Guzzle installed, so it is reached through the list
     * instead.
     */
    public function testNothingFoundSaysWhatToPassAndWhatToInstall(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RequestFactoryInterface::class);
        $this->expectExceptionMessage('guzzlehttp/psr7');

        Psr17Discovery::from([['No\Such\Factory', 'No\Such\Factory']]);
    }

    public function testAClassThatExistsButIsNotAFactoryIsSkipped(): void
    {
        $this->expectException(RuntimeException::class);

        Psr17Discovery::from([[\stdClass::class, \stdClass::class]]);
    }

    public function testLaterCandidatesAreTriedWhenEarlierOnesAreAbsent(): void
    {
        [$request] = Psr17Discovery::from([
            ['No\Such\Factory', 'No\Such\Factory'],
            [\stdClass::class, \stdClass::class],
            [HttpFactory::class, HttpFactory::class],
        ]);

        $this->assertInstanceOf(HttpFactory::class, $request);
    }
}
