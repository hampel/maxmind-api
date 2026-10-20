<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Endpoint;

use Hampel\MaxMind\Api\Connection;
use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The base class for everything that groups a set of endpoints - this package's own, and anybody
 * else's.
 *
 * There is nothing to register, nothing to boot and no container. The class IS the registration:
 *
 *     $maxmind->endpoint(MyEndpoint::class)->something();
 */
abstract class Endpoint
{
    public function __construct(
        protected readonly Connection $connection,
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Refuse a class name that cannot be constructed as an endpoint.
     *
     * Takes a plain string rather than a `class-string<Endpoint>`: a consumer reading a class name
     * out of configuration reaches here, and Endpoint itself or an abstract subclass satisfies the
     * type and would still fatal on `new`.
     */
    public static function assertConstructible(string $class): void
    {
        if (!is_subclass_of($class, self::class) || !(new \ReflectionClass($class))->isInstantiable()) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot be constructed as an API endpoint: it must be a concrete subclass of %s.',
                $class,
                self::class
            ));
        }
    }
}
