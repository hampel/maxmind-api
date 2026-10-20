<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api;

use Hampel\MaxMind\Api\Authentication\AccountKey;
use Hampel\MaxMind\Api\Authentication\Authentication;
use Hampel\MaxMind\Api\Endpoint\Downloads;
use Hampel\MaxMind\Api\Endpoint\Endpoint;
use Hampel\MaxMind\Api\Endpoint\GeoIp;
use Hampel\MaxMind\Api\Support\Psr17Discovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The entry point for MaxMind's web services. Hand it an account id, a licence key and any PSR-18
 * client:
 *
 *     $maxmind = Client::withAccountKey(123456, $licenseKey, new GuzzleHttp\Client());
 *     $maxmind = Client::withAccountKey(123456, $licenseKey, new GuzzleHttp\Client(), Config::sandbox());
 *
 *     $maxmind->geoip()->insights('203.0.113.7')->anonymizer->isAnonymousVpn;
 *     $maxmind->downloads()->lastModified('GeoLite2-Country');
 *
 * Or the long form, which names everything:
 *
 *     $factory = new GuzzleHttp\Psr7\HttpFactory();   // PSR-17, both roles
 *     $maxmind = new Client(Config::live(), new AccountKey(123456, $key), $guzzle, $factory, $factory, $logger);
 *
 * A DOWNLOADED DATABASE NEEDS NO CLIENT. Database\Reader opens an `.mmdb` file and answers with
 * the same models the web services do, with no credential and no HTTP.
 */
final class Client
{
    private readonly Connection $connection;

    /** @var array<class-string<Endpoint>, Endpoint> */
    private array $endpoints = [];

    /**
     * @param  RequestFactoryInterface|null  $requestFactory  PSR-17. Leave both null and the
     *         package finds one - Guzzle's, Nyholm's or Diactoros', whichever is installed; see
     *         Psr17Discovery. Pass them to choose, or when none of those is present.
     */
    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($requestFactory === null || $streamFactory === null) {
            [$foundRequest, $foundStream] = Psr17Discovery::find();

            $requestFactory ??= $foundRequest;
            $streamFactory ??= $foundStream;
        }

        $this->connection = new Connection(
            $this->config,
            $this->authentication,
            $client,
            $requestFactory,
            $streamFactory,
            $this->logger
        );
    }

    /**
     * The short form: an account id and licence key, a transport, and live unless told otherwise.
     *
     * Everything the long constructor takes is still available on it; this exists because naming
     * an AccountKey to wrap two values is ceremony, and ceremony in an example is what gets copied.
     */
    public static function withAccountKey(
        int $accountId,
        #[\SensitiveParameter] string $licenseKey,
        ClientInterface $client,
        ?Config $config = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            $config ?? Config::live(),
            new AccountKey($accountId, $licenseKey),
            $client,
            $requestFactory,
            $streamFactory,
            $logger ?? new NullLogger()
        );
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function authentication(): Authentication
    {
        return $this->authentication;
    }

    /**
     * The same credential and transport, under a different configuration - the sandbox switch on
     * a settings page.
     */
    public function withConfig(Config $config): self
    {
        return new self(
            $config,
            $this->authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger
        );
    }

    /**
     * The same environment and transport, under a different credential. A new client rather than
     * a mutation, so two credentials cannot leak into each other's requests.
     */
    public function withCredential(Authentication $authentication): self
    {
        return new self(
            $this->config,
            $authentication,
            $this->connection->client(),
            $this->connection->requestFactory(),
            $this->connection->streamFactory(),
            $this->logger
        );
    }

    /**
     * Any Endpoint subclass, constructed and memoised - the extension point.
     *
     * @template T of Endpoint
     * @param  class-string<T>  $class
     * @return T
     */
    public function endpoint(string $class): Endpoint
    {
        if (!isset($this->endpoints[$class])) {
            Endpoint::assertConstructible($class);

            $this->endpoints[$class] = new $class($this->connection, $this->logger);
        }

        /** @var T $endpoint */
        $endpoint = $this->endpoints[$class];

        return $endpoint;
    }

    /**
     * The GeoIP2 web services - Country, City Plus and Insights.
     */
    public function geoip(): GeoIp
    {
        return $this->endpoint(GeoIp::class);
    }

    /**
     * Database downloads - what changed, the checksum, and the file.
     */
    public function downloads(): Downloads
    {
        return $this->endpoint(Downloads::class);
    }

    /**
     * For anything the endpoints do not cover.
     */
    public function connection(): Connection
    {
        return $this->connection;
    }
}
