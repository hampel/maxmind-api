<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * A success whose body is not what MaxMind sends - not a JSON object where one was expected.
 *
 * A captive portal, a proxy's error document or a truncated response is a 200 with something else
 * in it, and decoded permissively it becomes a lookup with every field null - which reaches the
 * caller as "MaxMind knows nothing about this address".
 *
 * Extends ApiException so an existing `catch (ApiException)` sees it, even though nothing was
 * rejected.
 */
final class MalformedResponseException extends ApiException
{
    public static function forResponse(string $method, string $uri, ResponseInterface $response, string $body, string $expected = 'a JSON object'): self
    {
        $excerpt = trim(substr($body, 0, 200));

        return new self(
            sprintf(
                'MaxMind answered %s %s with HTTP %d but the body is not %s (Content-Type: %s)%s',
                $method,
                $uri,
                $response->getStatusCode(),
                $expected,
                $response->getHeaderLine('Content-Type') ?: 'none',
                $excerpt === '' ? '; the body was empty' : ': ' . $excerpt
            ),
            $response->getStatusCode(),
            null,
            $body
        );
    }
}
