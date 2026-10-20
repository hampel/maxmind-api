<?php

/**
 * Not an exercise. Discovery is glob('harness/*.php'), top level only, so a file down here is
 * required by the exercises that need it rather than listed as one of them.
 *
 * The layers that keep an accident out of this harness:
 *
 *   1. rig itself does not load the package's .env when CLAUDECODE is set.
 *   2. every exercise defaults to what costs nothing: the sandbox, a HEAD, a local file.
 *   3. this: the opt-in to anything that costs - a live query, a download - is refused under an
 *      agent, whatever .env says.
 *
 * WHAT COSTS HERE. Nothing this harness does writes anything at MaxMind, but a live GeoIP query
 * spends a paid credit and a download spends one of a daily allowance of 1,000 - 30 for GeoLite.
 * The sandbox is free and is a separate account with its own id and key, which is why there are
 * two credential pairs.
 */

use GuzzleHttp\Client as Guzzle;
use Hampel\MaxMind\Api\Client;
use Hampel\MaxMind\Api\Config;
use Hampel\Rig\Io;
use Psr\Http\Message\ResponseInterface;

/**
 * Whether a cost must be refused: an agent is running, and has not been told that this once it
 * may. Fails safe - with CLAUDECODE absent this answers false, and the ordinary opt-in, which
 * defaults to off, is what decides.
 */
function harness_agent_refuses(string $variable): bool
{
    return getenv('CLAUDECODE') !== false && getenv($variable) !== '1';
}

/**
 * A client for the sandbox or for live, from the matching credential pair, or an explanation and
 * an exit. Guzzle is given allow_redirects off, as a host's SSRF-guarded client would be, so the
 * package's own redirect handling is what runs.
 */
function harness_client(Io $io, bool $live, ?string $licenseKey = null): Client
{
    $prefix = $live ? 'MAXMIND_' : 'MAXMIND_SANDBOX_';
    $accountId = getenv($prefix . 'ACCOUNT_ID') ?: '';
    $configured = getenv($prefix . 'LICENSE_KEY') ?: '';

    if ($accountId === '' || $configured === '') {
        $io->error(sprintf('%sACCOUNT_ID and %sLICENSE_KEY are not both set.', $prefix, $prefix));
        $io->line();
        $io->line('If you are a person: copy .env.example to .env beside the package and fill it in.');
        $io->line('If you are an agent: this is the guard working. Do not go looking for the');
        $io->line('credentials, and do not edit .env - ask.');

        exit(1);
    }

    return Client::withAccountKey(
        (int) $accountId,
        $licenseKey ?? $configured,
        new Guzzle(['timeout' => 120, 'allow_redirects' => false, 'http_errors' => false]),
        $live ? Config::live() : Config::sandbox()
    );
}

/**
 * Print which environment the run is against, above the work.
 */
function harness_environment(Io $io, Client $maxmind): void
{
    $config = $maxmind->config();

    if ($config->isSandbox()) {
        $io->info(sprintf('environment: sandbox - %s, free, fixed data for test addresses only', $config->geoipUri));
    } else {
        $io->warn(sprintf('environment: LIVE - %s, %s', $config->geoipUri, $config->downloadUri ?? 'no downloads'));
    }

    $io->line(sprintf('             credential: %s', $maxmind->authentication()->describe()));
    $io->line();
}

/**
 * Print the mode ABOVE the work, and answer whether the cost may be spent.
 */
function harness_mode(Io $io, string $optIn, string $override, string $cost): bool
{
    if (getenv($optIn) !== '1') {
        $io->warn('mode: free - nothing will be spent');
        $io->line(sprintf('      what is skipped: %s', $cost));
        $io->line(sprintf('      set %s=1 to do it for real', $optIn));
        $io->line();

        return false;
    }

    if (harness_agent_refuses($override)) {
        $io->warn(sprintf('mode: free - %s is set, and is ignored in an agent session', $optIn));
        $io->line(sprintf('      what is skipped: %s', $cost));
        $io->line(sprintf('      %s=1 on the command line unlocks it for one run', $override));
        $io->line();

        return false;
    }

    $io->warn(sprintf('mode: SPENDING - %s', $cost));
    $io->line();

    return true;
}

/**
 * A request with the client's credential, answered RAW - status, headers and body as they
 * arrived, nothing turned into an exception and no redirect followed. The package maps statuses
 * to exceptions, and when the question is what MaxMind sends, the mapping hides the answer.
 */
function harness_raw(Client $maxmind, string $method, string $uri, array $headers = []): ResponseInterface
{
    $request = $maxmind->connection()->request($method, $uri);

    foreach ($headers as $name => $value) {
        $request = $request->withHeader($name, $value);
    }

    return $maxmind->connection()->client()->sendRequest($request);
}

/**
 * One line describing a raw response, with the body cut short.
 */
function harness_describe(ResponseInterface $response, int $excerpt = 200): string
{
    $body = trim((string) $response->getBody());

    return sprintf(
        'HTTP %d, %s, %d bytes%s',
        $response->getStatusCode(),
        $response->getHeaderLine('Content-Type') ?: 'no content type',
        strlen($body),
        $excerpt === 0 || $body === '' ? '' : ': ' . str_replace(["\r", "\n"], ' ', substr($body, 0, $excerpt))
    );
}

/**
 * A URI with its query string withheld - for printing a presigned URL, whose query IS a
 * credential for that one request.
 */
function harness_redact(string $uri): string
{
    return str_contains($uri, '?') ? substr($uri, 0, (int) strpos($uri, '?')) . '?[withheld]' : $uri;
}
