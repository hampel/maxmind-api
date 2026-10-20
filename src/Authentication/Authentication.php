<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Authentication;

use Psr\Http\Message\RequestInterface;

/**
 * How a request proves who it is.
 *
 * One implementation, because every MaxMind service this package reaches - the GeoIP web
 * services, the database downloads and minFraud - takes the same HTTP basic credential. The
 * interface is for what will want to sit behind it: a credential resolved per site at request
 * time, or read from a secret store that rotates underneath the process.
 */
interface Authentication
{
    public function applyTo(RequestInterface $request): RequestInterface;

    /**
     * What this credential is, for a log line or an exception message.
     *
     * MUST NOT INCLUDE A SECRET, or any part of one long enough to be useful.
     */
    public function describe(): string;
}
