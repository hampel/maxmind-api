<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Exception\AddressNotFoundException;
use Hampel\MaxMind\Api\Exception\ApiException;
use Hampel\MaxMind\Api\Exception\BadRequestException;
use Hampel\MaxMind\Api\Exception\ClientException;
use Hampel\MaxMind\Api\Exception\InsufficientFundsException;
use Hampel\MaxMind\Api\Exception\InvalidAddressException;
use Hampel\MaxMind\Api\Exception\LegallyBlockedException;
use Hampel\MaxMind\Api\Exception\MalformedResponseException;
use Hampel\MaxMind\Api\Exception\NotAuthenticatedException;
use Hampel\MaxMind\Api\Exception\NotFoundException;
use Hampel\MaxMind\Api\Exception\NotPermittedException;
use Hampel\MaxMind\Api\Exception\RequestException;
use Hampel\MaxMind\Api\Exception\RuntimeException;
use Hampel\MaxMind\Api\Exception\ServerException;
use Hampel\MaxMind\Api\Exception\TooManyRequestsException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;

final class ConnectionTest extends TestCase
{
    public function testEveryRequestCarriesBasicAuth(): void
    {
        $this->pushMaxMindJson(200, self::fixture('country'), 'country');

        $this->maxmind()->connection()->getJson('https://geoip.maxmind.com/geoip/v2.1/country/1.2.3.4');

        $this->assertSame(self::basicAuth(), $this->client->lastRequest()->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $this->client->lastRequest()->getHeaderLine('Accept'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignUris(): iterable
    {
        yield 'another host' => ['https://attacker.example.com/geoip/v2.1/country/1.2.3.4'];
        yield 'http, not https' => ['http://geoip.maxmind.com/geoip/v2.1/country/1.2.3.4'];
        yield 'a lookalike host' => ['https://geoip.maxmind.com.attacker.example/x'];
        yield 'another port' => ['https://geoip.maxmind.com:8443/x'];
        yield 'userinfo' => ['https://user@geoip.maxmind.com/x'];
        yield 'the redirect target' => ['https://' . self::R2_HOST . '/x'];
    }

    #[DataProvider('foreignUris')]
    public function testARequestToAnyOtherOriginIsRefusedBeforeItCarriesTheCredential(string $uri): void
    {
        try {
            $this->maxmind()->connection()->getJson($uri);
            $this->fail('the request should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('licence key', $e->getMessage());
            $this->assertSame([], $this->client->requests);
        }
    }

    /**
     * @return iterable<string, array{int, ?string, class-string<ApiException>}>
     */
    public static function errors(): iterable
    {
        yield '400 IP_ADDRESS_INVALID' => [400, 'IP_ADDRESS_INVALID', InvalidAddressException::class];
        yield '400 IP_ADDRESS_RESERVED' => [400, 'IP_ADDRESS_RESERVED', InvalidAddressException::class];
        yield '400 a new IP_ADDRESS_ code' => [400, 'IP_ADDRESS_SOMETHING_NEW', InvalidAddressException::class];
        yield '400 SERVICE_INVALID' => [400, 'SERVICE_INVALID', BadRequestException::class];
        yield '401 AUTHORIZATION_INVALID' => [401, 'AUTHORIZATION_INVALID', NotAuthenticatedException::class];
        yield '401 an unknown code' => [401, 'SOMETHING_NEW', NotAuthenticatedException::class];
        yield '402 INSUFFICIENT_FUNDS' => [402, 'INSUFFICIENT_FUNDS', InsufficientFundsException::class];
        yield '403 PERMISSION_REQUIRED' => [403, 'PERMISSION_REQUIRED', NotPermittedException::class];
        yield '404 IP_ADDRESS_NOT_FOUND' => [404, 'IP_ADDRESS_NOT_FOUND', AddressNotFoundException::class];
        yield '404 an unknown code' => [404, 'SOMETHING_NEW', NotFoundException::class];
        yield '429' => [429, null, TooManyRequestsException::class];
        yield '451' => [451, null, LegallyBlockedException::class];
        yield '500 SERVER_ERROR' => [500, 'SERVER_ERROR', ServerException::class];
        yield '503' => [503, null, ServerException::class];
        yield '418' => [418, null, ClientException::class];
    }

    /**
     * Only `code` is stable, and MaxMind adds codes without notice - so an unknown code must land
     * in its status's class, never in a generic one.
     *
     * @param  class-string<ApiException>  $expected
     */
    #[DataProvider('errors')]
    public function testAnErrorIsClassifiedByStatusThenCode(int $status, ?string $code, string $expected): void
    {
        $code === null ? $this->client->pushRaw($status, '') : $this->pushError($status, $code, 'Message ' . $code);

        try {
            $this->maxmind()->connection()->getJson('https://geoip.maxmind.com/geoip/v2.1/country/1.2.3.4');
            $this->fail('should raise');
        } catch (ApiException $e) {
            $this->assertSame($expected, $e::class);
            $this->assertSame($status, $e->statusCode);
            $this->assertSame($code, $e->errorCode);

            if ($code !== null) {
                $this->assertStringContainsString('Message ' . $code, $e->getMessage());
            }
        }
    }

    /**
     * A body is decoded only when the response says it is JSON.
     */
    public function testAJsonBodyLabelledAsTextIsNotReadAsAnError(): void
    {
        $this->client->pushRaw(401, '{"code":"AUTHORIZATION_INVALID","error":"x"}', ['Content-Type' => 'text/plain']);

        try {
            $this->maxmind()->connection()->getJson('https://geoip.maxmind.com/geoip/v2.1/country/1.2.3.4');
            $this->fail('should raise');
        } catch (NotAuthenticatedException $e) {
            $this->assertNull($e->errorCode);
        }
    }

    public function testAPlainTextErrorLandsInTheMessage(): void
    {
        $this->client->pushRaw(451, "Unavailable for legal reasons\n", ['Content-Type' => 'text/plain']);

        $this->expectException(LegallyBlockedException::class);
        $this->expectExceptionMessage('Unavailable for legal reasons');

        $this->maxmind()->connection()->getJson('https://download.maxmind.com/geoip/databases/GeoIP2-City/download');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notObjects(): iterable
    {
        yield 'an HTML page' => ['<!DOCTYPE html><html><body>Captive portal</body></html>', 'text/html'];
        yield 'an empty body' => ['', 'application/json'];
        yield 'a JSON list' => ['[]', 'application/json'];
        yield 'JSON labelled as HTML' => ['{"country":{}}', 'text/html'];
    }

    #[DataProvider('notObjects')]
    public function testASuccessThatIsNotAJsonObjectIsMalformed(string $body, string $type): void
    {
        $this->client->pushRaw(200, $body, ['Content-Type' => $type]);

        $this->expectException(MalformedResponseException::class);

        $this->maxmind()->connection()->getJson('https://geoip.maxmind.com/geoip/v2.1/country/1.2.3.4');
    }

    public function testATransportFailureIsARequestException(): void
    {
        $request = $this->maxmind()->connection()->request('GET', 'https://geoip.maxmind.com/x');
        $this->client->push(new TransportFailure($request, 'Operation timed out'));

        try {
            $this->maxmind()->connection()->getJson('https://geoip.maxmind.com/geoip/v2.1/country/1.2.3.4');
            $this->fail('should raise');
        } catch (RequestException $e) {
            $this->assertStringContainsString('Operation timed out', $e->getMessage());
            $this->assertInstanceOf(TransportFailure::class, $e->getPrevious());
        }
    }

    public function testSomethingThatIsNotAPsr18ExceptionPassesThroughUntouched(): void
    {
        $this->client->pushThrowable(new \RuntimeException('Attempted request without a matching fake.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without a matching fake');

        $this->maxmind()->connection()->getJson('https://geoip.maxmind.com/geoip/v2.1/country/1.2.3.4');
    }

    public function testRequestsAreLoggedAtDebugWithoutTheCredential(): void
    {
        $logger = new RecordingLogger();
        $this->pushMaxMindJson(200, self::fixture('country'), 'country');
        $this->client->pushRaw(503, '');

        $maxmind = $this->maxmind(logger: $logger);
        $maxmind->geoip()->country('1.2.3.4');

        try {
            $maxmind->geoip()->country('1.2.3.5');
        } catch (ServerException) {
        }

        $this->assertCount(2, $logger->records);

        foreach ($logger->records as $record) {
            $this->assertSame(LogLevel::DEBUG, $record['level']);
            $encoded = (string) json_encode($record);
            $this->assertStringNotContainsString(self::LICENSE_KEY, $encoded);
            $this->assertStringNotContainsString(base64_encode(self::ACCOUNT_ID . ':' . self::LICENSE_KEY), $encoded);
        }
    }
}
