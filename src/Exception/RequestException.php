<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * The request never got an answer: DNS, TLS, a timeout, a refused connection.
 *
 * Distinct from every ApiException, all of which mean MaxMind replied. Every request this package
 * makes is a read, so a retry is safe - but a lookup on a request path is usually better treated as
 * "no answer" than retried while somebody waits.
 *
 * The chained exception is the HTTP client's own. Its message carries no credential - MaxMind's is
 * in a header, not the URI - but a PSR-18 RequestExceptionInterface also carries the REQUEST, and
 * with it the `Authorization` header: a logger that dumps the request from a caught exception
 * writes the licence key out in base64.
 */
final class RequestException extends MaxMindException
{
    public static function for(string $method, string $uri, ClientExceptionInterface $previous): self
    {
        return new self(
            sprintf('Could not reach MaxMind for %s %s: %s', $method, $uri, $previous->getMessage()),
            0,
            $previous
        );
    }
}
