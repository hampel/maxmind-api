<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Support;

use Hampel\MaxMind\Api\Exception\InvalidArgumentException;

/**
 * Encoding a request body and decoding a response, with the two failure modes kept apart.
 *
 * Encoding fails because the CALLER handed us something that cannot be JSON, so it raises.
 * Decoding fails because whatever ANSWERED did not send JSON, which is not the caller's mistake
 * and is handled where the response is.
 */
final class Json
{
    /**
     * @param  array<mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException(
                'Could not encode the request payload as JSON: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Decode a body, answering whether it was JSON at all separately from what it held.
     *
     * @return array{bool, mixed}  whether the body decoded, and the decoded value
     */
    public static function decode(string $body): array
    {
        if (trim($body) === '') {
            return [false, null];
        }

        try {
            return [true, json_decode($body, true, 512, JSON_THROW_ON_ERROR)];
        } catch (\JsonException) {
            return [false, null];
        }
    }
}
