<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * MaxMind answered, and the answer was not a success.
 *
 * An error from the web services is `{"code": "...", "error": "..."}`. ONLY `code` IS STABLE:
 * MaxMind documents the message as subject to change, and adds and removes codes without a
 * version bump. So the exception class is chosen by status first and refined by code, and a code
 * this package has never seen lands in its status's class rather than in a generic failure - a new
 * code does not change what a caller's catch blocks do.
 *
 * Several statuses arrive with no body at all - a 503, often a 404 or a 429 - and the download
 * service answers in plain text, so `errorCode` is null whenever the body was not MaxMind's JSON.
 *
 * ONE SUBCLASS IS ALSO RAISED WITHOUT HTTP: AddressNotFoundException, by a database file that has
 * no record for an address. Its `statusCode` is 0 then. It is the same class so that a consumer
 * who lets an administrator choose between the web service and a downloaded file catches one thing.
 */
abstract class ApiException extends MaxMindException
{
    /**
     * @param  int  $statusCode  the HTTP status; 0 when the answer came from a database file
     * @param  string|null  $errorCode  MaxMind's `code`, when the body was its JSON error
     * @param  string  $body  the raw response body
     */
    final public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?string $errorCode = null,
        public readonly string $body = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * A response whose status was not a success, mapped to the class a caller can act on.
     *
     * @param  array<string, mixed>|null  $data  the body decoded, when it was a JSON object
     */
    public static function fromResponse(string $method, string $uri, ResponseInterface $response, string $body, ?array $data): self
    {
        $status = $response->getStatusCode();
        $code = isset($data['code']) && is_string($data['code']) && $data['code'] !== '' ? $data['code'] : null;
        $detail = isset($data['error']) && is_string($data['error']) ? trim($data['error']) : '';

        if ($detail === '' && $data === null && trim($body) !== '' && !self::looksLikeHtml($body)) {
            $detail = trim((string) preg_replace('/\s+/', ' ', substr($body, 0, 300)));
        }

        $message = sprintf(
            'MaxMind rejected %s %s (HTTP %d%s)%s',
            $method,
            $uri,
            $status,
            $code === null ? '' : ', ' . $code,
            $detail === '' ? '' : ': ' . $detail
        );

        $class = self::classFor($status, $code);

        return new $class($message, $status, $code, $body);
    }

    /**
     * The class for a status and code - by status, refined by code, never generic for a status
     * this package knows.
     *
     * @return class-string<self>
     */
    public static function classFor(int $status, ?string $code): string
    {
        if ($code === 'IP_ADDRESS_NOT_FOUND') {
            return AddressNotFoundException::class;
        }

        return match (true) {
            $status === 400 && $code !== null && str_starts_with($code, 'IP_ADDRESS_') => InvalidAddressException::class,
            $status === 400 => BadRequestException::class,
            $status === 401 => NotAuthenticatedException::class,
            $status === 402 => InsufficientFundsException::class,
            $status === 403 => NotPermittedException::class,
            $status === 404 => NotFoundException::class,
            $status === 429 => TooManyRequestsException::class,
            $status === 451 => LegallyBlockedException::class,
            $status >= 500 => ServerException::class,
            default => ClientException::class,
        };
    }

    protected static function looksLikeHtml(string $body): bool
    {
        $start = strtolower(ltrim(substr($body, 0, 200)));

        return str_starts_with($start, '<!doctype html') || str_starts_with($start, '<html');
    }
}
