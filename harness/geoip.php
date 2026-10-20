<?php

/**
 * Exercise: query the GeoIP2 web services and probe how MaxMind says no. Sandbox by default; live spends paid queries and is opt-in.
 *
 * QUESTIONS:
 *
 *   1. WHAT DOES EACH SERVICE ANSWER FOR A SANDBOX TEST ADDRESS - and does the sandbox Insights
 *      carry `anonymizer`, and `anonymizer.residential`? The models are written from the openapi
 *      examples; this is the first look at what MaxMind really sends.
 *   2. WHAT CONTENT TYPE does a success carry? Connection decodes an error body only when the
 *      type says JSON, so a vendor type that does not contain "json" would hide every error code.
 *   3. DOES A 404 FOR AN UNKNOWN ADDRESS HAVE A BODY? GeoIp turns a body-less 404 into
 *      AddressNotFoundException on the strength of MaxMind's documentation saying some do.
 *   4. WHAT DO A RESERVED ADDRESS AND A WRONG KEY ANSWER - status, code and type? One wrong key
 *      is sent, once.
 *   5. DOES THE ACCEPT HEADER MATTER? The package sends `application/json`; MaxMind documents a
 *      vendor type. The same lookup with each.
 *
 * Needs MAXMIND_SANDBOX_ACCOUNT_ID and MAXMIND_SANDBOX_LICENSE_KEY. With MAXMIND_GEOIP_LIVE=1
 * it reads the live account instead, with MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY - five or six
 * paid queries, Insights among them - and an agent is refused that unless
 * MAXMIND_AGENT_MAY_QUERY_LIVE=1 is on its command line.
 *
 * MAXMIND_ADDRESS is the address for question 1; the default is a sandbox test address.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\MaxMind\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/harness.php';

$io->title('maxmind · geoip');

$live = harness_mode($io, 'MAXMIND_GEOIP_LIVE', 'MAXMIND_AGENT_MAY_QUERY_LIVE', 'live queries - the sandbox answers instead');
$maxmind = harness_client($io, $live);
harness_environment($io, $maxmind);

$base = $maxmind->config()->geoipUri . '/geoip/v2.1/';
$address = getenv('MAXMIND_ADDRESS') ?: '81.2.69.160';

$io->info(sprintf('1 and 2 - %s through the package', $address));

$failure = false;

foreach (['country', 'city', 'insights'] as $service) {
    try {
        $answer = $maxmind->geoip()->{$service}($address);

        $io->values([
            $service => sprintf('%s, %s, network %s', $answer->country->isoCode ?? '?', $answer->country->name() ?? '?', $answer->traits->network ?? '?'),
            '  queries remaining' => $answer->maxmind->queriesRemaining ?? '?',
            '  sections' => implode(', ', array_keys($answer->raw)),
        ]);

        if ($service === 'insights') {
            $anonymizer = $answer->anonymizer;

            $io->values([
                '  anonymizer' => $anonymizer->isEmpty() ? 'ABSENT' : json_encode($anonymizer->raw),
                '  residential' => $anonymizer->residential->isEmpty() ? 'ABSENT' : json_encode($anonymizer->residential->raw),
                '  traits keys' => implode(', ', array_keys($answer->traits->raw)),
            ]);
        }
    } catch (ExceptionInterface $e) {
        $failure = true;
        $io->error(sprintf('%s: %s', $e::class, $e->getMessage()));
    }
}

$io->line();
$io->info('2, 3, 4 and 5 - raw answers');

$probes = [
    '2 a success' => [$base . 'country/' . $address, []],
    '3 an unknown address' => [$base . 'country/203.0.113.77', []],
    '4 a reserved address' => [$base . 'country/10.0.0.1', []],
    '5 vendor Accept' => [$base . 'country/' . $address, ['Accept' => 'application/vnd.maxmind.com-country+json; charset=UTF-8; version=2.1']],
];

foreach ($probes as $label => [$uri, $headers]) {
    $response = harness_raw($maxmind, 'GET', $uri, $headers);

    $io->line(sprintf('  %s', $label));
    $io->line(sprintf('    %s', harness_describe($response, $label === '2 a success' || $label === '5 vendor Accept' ? 0 : 200)));
}

$io->line();
$io->info('4 - a wrong licence key, sent once');

$refused = harness_raw(harness_client($io, $live, 'deliberately-wrong-' . bin2hex(random_bytes(4))), 'GET', $base . 'country/' . $address);
$io->line('  ' . harness_describe($refused, 240));

exit($failure ? 1 : 0);
