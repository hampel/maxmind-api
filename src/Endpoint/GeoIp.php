<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Endpoint;

use Hampel\MaxMind\Api\Config;
use Hampel\MaxMind\Api\Exception\AddressNotFoundException;
use Hampel\MaxMind\Api\Exception\NotFoundException;
use Hampel\MaxMind\Api\Model\City;
use Hampel\MaxMind\Api\Model\Country;
use Hampel\MaxMind\Api\Model\Insights;
use Hampel\MaxMind\Api\Support\Address;

/**
 * The GeoIP2 web services: `GET /geoip/v2.1/{country|city|insights}/{address}`.
 *
 *     $maxmind->geoip()->country('203.0.113.7')->country->isoCode;
 *     $maxmind->geoip()->insights('203.0.113.7')->anonymizer->isAnonymousVpn;
 *     $maxmind->geoip()->country('me');     // whoever is asking - the server itself
 *
 * EACH CALL IS ONE PAID QUERY - Insights costs the most - and nothing here caches. A consumer
 * looking up the same address twice should keep the answer, keyed by `traits->network`, which is
 * the block every address in it shares.
 *
 * ON A REQUEST PATH, SET A SHORT TIMEOUT ON THE PSR-18 CLIENT. Every failure here is an
 * ExceptionInterface, so "no answer in time" and "MaxMind said no" can both be caught as one thing
 * and treated as "allow" - but only the client can stop the wait.
 */
final class GeoIp extends Endpoint
{
    public const PATH = 'geoip/v2.1';

    public function country(string $ipAddress = Address::ME): Country
    {
        return Country::fromArray($this->lookup('country', $ipAddress));
    }

    /**
     * City Plus. The path is `city`; MaxMind renamed the product, not the endpoint.
     */
    public function city(string $ipAddress = Address::ME): City
    {
        return City::fromArray($this->lookup('city', $ipAddress));
    }

    public function insights(string $ipAddress = Address::ME): Insights
    {
        return Insights::fromArray($this->lookup('insights', $ipAddress));
    }

    /**
     * A 404 is an address MaxMind has no data for, with a body or without one: the paths sent
     * here are fixed, so the address is the only thing that can be missing.
     *
     * @return array<string, mixed>
     */
    private function lookup(string $service, string $ipAddress): array
    {
        $ipAddress = Address::check($ipAddress, allowMe: true);

        $uri = Config::resolve($this->connection->config()->geoipUri, self::PATH . '/' . $service . '/' . $ipAddress);

        try {
            return $this->connection->getJson($uri);
        } catch (NotFoundException $e) {
            if ($e instanceof AddressNotFoundException) {
                throw $e;
            }

            throw new AddressNotFoundException(
                sprintf('MaxMind has no data for %s (HTTP %d, no error code).', $ipAddress, $e->statusCode),
                $e->statusCode,
                $e->errorCode,
                $e->body,
                $e
            );
        }
    }
}
