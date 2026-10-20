<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api;

use Hampel\MaxMind\Api\Authentication\Authentication;
use Hampel\MaxMind\Api\Exception\ApiException;
use Hampel\MaxMind\Api\Exception\MalformedResponseException;
use Hampel\MaxMind\Api\Exception\RequestException;
use Hampel\MaxMind\Api\Exception\RuntimeException;
use Hampel\MaxMind\Api\Support\Cast;
use Hampel\MaxMind\Api\Support\Json;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Everything that touches HTTP, in one place.
 *
 * The client is injected as a PSR-18 ClientInterface rather than a concrete one, which is the
 * whole point of the package: MaxMind's own client requires ext-curl and its own request factory,
 * so inside a host application with its own HTTP stack - proxy settings, SSRF checks, timeouts -
 * it bypasses all three. Here the host implements sendRequest() over its stack instead.
 *
 * A PSR-18 client does not throw on an HTTP status, only on a transport failure, so the two
 * failure modes stay cleanly separated here.
 *
 * REDIRECTS ARE THIS CLASS'S BUSINESS, NOT THE CLIENT'S. PSR-18 does not say whether a client
 * follows them, and a host's SSRF-guarded client usually does not. The download service answers
 * with a 302 to a presigned URL on another host, so fetch() follows exactly that one hop itself -
 * to an allowed host, over https, and without the credential.
 */
final class Connection
{
    public const JSON_CONTENT_TYPE = 'application/json';

    public function __construct(
        private readonly Config $config,
        private readonly Authentication $authentication,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
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
     * The injected transport, exposed because a caller assembling something this class does not
     * cover needs the same client and factories to do it.
     */
    public function client(): ClientInterface
    {
        return $this->client;
    }

    public function requestFactory(): RequestFactoryInterface
    {
        return $this->requestFactory;
    }

    public function streamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }

    /**
     * GET a JSON object.
     *
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    public function getJson(string $uri, array $query = [], string $accept = self::JSON_CONTENT_TYPE): array
    {
        return $this->sendJson($this->request('GET', $uri, $query)->withHeader('Accept', $accept));
    }

    /**
     * Build a request without sending it, with the credential applied.
     *
     * AN ABSOLUTE URI THAT IS NOT ONE OF THE CONFIGURED SERVICES IS REFUSED. Every request carries
     * the account id and licence key.
     *
     * @param  array<string, scalar|null>  $query
     */
    public function request(string $method, string $uri, array $query = []): RequestInterface
    {
        $uri = Config::resolve($this->config->geoipUri, $uri, $query);

        if (!$this->config->ownsUri($uri)) {
            throw new RuntimeException(sprintf(
                'Refusing to request "%s": it is not one of the MaxMind services this client is configured for, and every request carries the licence key.',
                $uri
            ));
        }

        return $this->authentication->applyTo($this->requestFactory->createRequest($method, $uri));
    }

    /**
     * Send a request and decode the JSON object it answers with.
     *
     * TWO WAYS A REQUEST FAILS, and they are raised as different things:
     *
     * - it never got an answer: RequestException;
     * - it got a status that is not a success: ApiException::fromResponse(), by status and code.
     *
     * And a third that is not a refusal: a success whose body is not a JSON object is a
     * MalformedResponseException, never an empty answer.
     *
     * @return array<string, mixed>
     */
    public function sendJson(RequestInterface $request): array
    {
        $response = $this->dispatch($request);
        $body = (string) $response->getBody();
        $data = self::decodeObject($response, $body);

        if (!self::isSuccess($response)) {
            throw ApiException::fromResponse($request->getMethod(), (string) $request->getUri(), $response, $body, $data);
        }

        if ($data === null) {
            throw MalformedResponseException::forResponse($request->getMethod(), (string) $request->getUri(), $response, $body);
        }

        return $data;
    }

    /**
     * Send a request whose answer is a file, following one redirect without the credential.
     *
     * The answer is returned with its body unread, so a caller can stream a database of tens of
     * megabytes to disk rather than hold it in a string. A response that is not a success is
     * raised - the download service answers errors in plain text, which lands in the message.
     *
     * TWO SHAPES OF SUCCESS ARE ACCEPTED, because PSR-18 allows both: a 302 this method follows,
     * and a 200 from a client that followed the redirect itself. In the second case nothing here
     * can see whether the client forwarded `Authorization` to the redirect target - Guzzle strips
     * it on a change of host; a hand-written client may not. A client given to this package should
     * either not follow redirects or follow them as Guzzle does.
     */
    public function fetch(RequestInterface $request): ResponseInterface
    {
        $response = $this->dispatch($request);
        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400 && $request->getMethod() === 'GET') {
            $location = $response->getHeaderLine('Location');

            if ($location === '' || !$this->config->allowsDownloadRedirect($location)) {
                throw new RuntimeException(sprintf(
                    'MaxMind redirected %s to "%s", which is not an https URI on an allowed download host (%s). It was not followed.',
                    self::redact((string) $request->getUri()),
                    self::redact($location),
                    implode(', ', $this->config->downloadRedirectHosts)
                ));
            }

            // A fresh request, not a copy of the first: nothing of the original's headers - the
            // credential above all - can reach the redirect target by accident.
            $request = $this->requestFactory->createRequest('GET', $location);
            $response = $this->dispatch($request);
        }

        if (!self::isSuccess($response)) {
            $body = (string) $response->getBody();

            throw ApiException::fromResponse(
                $request->getMethod(),
                self::redact((string) $request->getUri()),
                $response,
                $body,
                self::decodeObject($response, $body)
            );
        }

        return $response;
    }

    /**
     * Send a HEAD request and return the response, raising on a status that is not a success.
     *
     * A redirect is returned rather than followed: the presigned URL is signed for a GET, and its
     * headers are what a HEAD is asking for anyway.
     */
    public function head(RequestInterface $request): ResponseInterface
    {
        $response = $this->dispatch($request);
        $status = $response->getStatusCode();

        if ($status >= 400 || $status < 200) {
            throw ApiException::fromResponse('HEAD', (string) $request->getUri(), $response, '', null);
        }

        return $response;
    }

    /**
     * Log it at `debug`, send it, and keep a transport failure distinct from an HTTP status.
     *
     * NOTHING HERE LOGS A FAILURE, BECAUSE EVERY FAILURE IS RAISED. Whether an exception is a
     * failure is decided by whoever catches it - an address MaxMind does not know is an answer -
     * and an `error` written before the throw would report it as a fault.
     *
     * The log line names the method and URI and nothing else: the credential is in a header, and
     * no header is logged. A presigned redirect target is logged without its query string, which
     * is its signature.
     *
     * The catch is ClientExceptionInterface and not \Throwable, deliberately. Laravel's
     * StrayRequestException is a plain RuntimeException, and it reaches the consumer's test naming
     * the URL only because it passes through here untouched.
     */
    private function dispatch(RequestInterface $request): ResponseInterface
    {
        $method = $request->getMethod();
        $uri = self::redact((string) $request->getUri());

        $this->logger->debug('MaxMind API request', [
            'method' => $method,
            'uri' => $uri,
        ]);

        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw RequestException::for($method, $uri, $e);
        }
    }

    private static function isSuccess(ResponseInterface $response): bool
    {
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /**
     * The body as a JSON object, or null - decoded only when the response says it is JSON, since
     * the download service answers errors in plain text and several statuses arrive with no body.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeObject(ResponseInterface $response, string $body): ?array
    {
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')) {
            return null;
        }

        [$decoded, $value] = Json::decode($body);

        return $decoded && is_array($value) && !array_is_list($value) ? Cast::object($value) : null;
    }

    /**
     * A URI without its query string when it is not one of MaxMind's own - a presigned URL's query
     * IS the credential for that one request, and has no business in a log or a message.
     */
    private static function redact(string $uri): string
    {
        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));

        if ($host === '' || str_ends_with($host, 'maxmind.com') || !str_contains($uri, '?')) {
            return $uri;
        }

        return substr($uri, 0, (int) strpos($uri, '?')) . '?[signature withheld]';
    }
}
