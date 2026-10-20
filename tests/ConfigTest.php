<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Config;
use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use Hampel\MaxMind\Api\Support\Address;

final class ConfigTest extends TestCase
{
    public function testLiveAndSandbox(): void
    {
        $live = Config::live();
        $sandbox = Config::sandbox();

        $this->assertSame('https://geoip.maxmind.com', $live->geoipUri);
        $this->assertSame('https://minfraud.maxmind.com', $live->minfraudUri);
        $this->assertSame('https://download.maxmind.com', $live->downloadUri);
        $this->assertFalse($live->isSandbox());

        $this->assertSame('https://sandbox.maxmind.com', $sandbox->geoipUri);
        $this->assertSame('https://sandbox.maxmind.com', $sandbox->minfraudUri);
        $this->assertNull($sandbox->downloadUri);
        $this->assertTrue($sandbox->isSandbox());
    }

    public function testEveryServiceIsOwned(): void
    {
        $config = Config::live();

        $this->assertTrue($config->ownsUri('https://geoip.maxmind.com/x'));
        $this->assertTrue($config->ownsUri('https://minfraud.maxmind.com/x'));
        $this->assertTrue($config->ownsUri('https://DOWNLOAD.maxmind.com/x'));
        $this->assertFalse($config->ownsUri('https://sandbox.maxmind.com/x'));
        $this->assertFalse($config->ownsUri('not a uri'));
    }

    public function testARedirectHostSuffixMatchesHostsUnderIt(): void
    {
        $config = new Config(downloadRedirectHosts: ['.example.net']);

        $this->assertTrue($config->allowsDownloadRedirect('https://cdn.example.net/x'));
        $this->assertFalse($config->allowsDownloadRedirect('https://example.net.evil.test/x'));
        $this->assertFalse($config->allowsDownloadRedirect('https://' . self::R2_HOST . '/x'));
    }

    public function testABaseUriNeedsAScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config('geoip.maxmind.com');
    }

    public function testARedirectHostMustBeAHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(downloadRedirectHosts: ['https://example.com/']);
    }

    public function testANetworkFromAPrefixLength(): void
    {
        $this->assertSame('81.2.69.160/27', Address::network('81.2.69.170', 27));
        $this->assertSame('0.0.0.0/0', Address::network('81.2.69.170', 0));
        $this->assertSame('81.2.69.170/32', Address::network('81.2.69.170', 32));
        $this->assertSame('2001:db8::/32', Address::network('2001:db8:1:2::5', 32));
        $this->assertSame('2001:db8:1:2::/63', Address::network('2001:db8:1:3::5', 63));
        $this->assertNull(Address::network('81.2.69.170', 33));
        $this->assertNull(Address::network('nonsense', 8));
    }
}
