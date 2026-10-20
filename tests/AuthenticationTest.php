<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Authentication\AccountKey;
use Hampel\MaxMind\Api\Client;
use Hampel\MaxMind\Api\Config;
use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthenticationTest extends TestCase
{
    public function testTheKeyIsNeverPrintable(): void
    {
        $key = new AccountKey(self::ACCOUNT_ID, self::LICENSE_KEY);

        ob_start();
        var_dump($key);
        print_r($key);
        $dumped = (string) ob_get_clean();

        foreach ([$dumped, (string) $key, (string) json_encode($key), $key->describe(), var_export($key->__debugInfo(), true)] as $shown) {
            $this->assertStringNotContainsString(self::LICENSE_KEY, $shown);
        }

        $this->assertStringContainsString((string) self::ACCOUNT_ID, $key->describe());
    }

    /**
     * MaxMind's own client fails with a TypeError on an empty account id. Here it is a message.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function badStrings(): iterable
    {
        yield 'an empty account id' => ['', 'key'];
        yield 'a non-numeric account id' => ['12a', 'key'];
        yield 'a negative account id' => ['-5', 'key'];
        yield 'a zero account id' => ['0', 'key'];
        yield 'an empty key' => ['42', '  '];
    }

    #[DataProvider('badStrings')]
    public function testFromStringsRefusesWhatIsNotACredential(string $accountId, string $key): void
    {
        $this->expectException(InvalidArgumentException::class);

        AccountKey::fromStrings($accountId, $key);
    }

    public function testFromStringsReadsASettingsPage(): void
    {
        $key = AccountKey::fromStrings(' 123456 ', ' abc ');

        $this->assertSame(123456, $key->accountId);
    }

    public function testTheShortFormIsLiveUnlessToldOtherwise(): void
    {
        $this->assertFalse(Client::withAccountKey(1, 'k', $this->client)->config()->isSandbox());
        $this->assertTrue(Client::withAccountKey(1, 'k', $this->client, Config::sandbox())->config()->isSandbox());
    }

    public function testWithConfigKeepsTheCredentialAndChangesTheHost(): void
    {
        $this->pushMaxMindJson(200, self::fixture('country'), 'country');

        $this->maxmind()->withConfig(Config::sandbox())->geoip()->country('1.2.3.4');

        $this->assertSame('sandbox.maxmind.com', $this->client->lastRequest()->getUri()->getHost());
        $this->assertSame(self::basicAuth(), $this->client->lastRequest()->getHeaderLine('Authorization'));
    }

    public function testWithCredentialChangesOnlyTheCredential(): void
    {
        $this->pushMaxMindJson(200, self::fixture('country'), 'country');

        $this->maxmind()->withCredential(new AccountKey(7, 'other'))->geoip()->country('1.2.3.4');

        $this->assertSame('Basic ' . base64_encode('7:other'), $this->client->lastRequest()->getHeaderLine('Authorization'));
    }
}
