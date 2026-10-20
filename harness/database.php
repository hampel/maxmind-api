<?php

/**
 * Exercise: read a local .mmdb file into the package's models, and time many lookups. Reaches nothing outside the machine.
 *
 * QUESTIONS:
 *
 *   1. WHAT DOES A REAL DOWNLOADED FILE SAY ABOUT ITSELF - its database_type, build date and
 *      languages? Reader picks a model from database_type, so a type it does not recognise is the
 *      first thing to look for when a lookup is refused.
 *   2. IS A LOOKUP CHEAP ENOUGH FOR A PAGE OF ADDRESSES? The first consumer renders lists of IPs
 *      from a local file, one lookup per row. Printed: lookups per second over one Reader.
 *   3. DO THE ANSWERS LOOK RIGHT for a handful of real addresses? MAXMIND_ADDRESSES takes a
 *      comma-separated list; the default is MaxMind's test addresses, which only the test files
 *      answer.
 *
 * MAXMIND_DATABASE is the file to read; the default is the City test database in tests/fixtures.
 * downloads, with MAXMIND_DOWNLOAD=1, writes a real one.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\MaxMind\Api\Database\Reader;
use Hampel\MaxMind\Api\Exception\ExceptionInterface;

$io->title('maxmind · database');

$path = getenv('MAXMIND_DATABASE') ?: dirname(__DIR__) . '/tests/fixtures/mmdb/GeoIP2-City-Test.mmdb';
$addresses = array_filter(array_map('trim', explode(',', getenv('MAXMIND_ADDRESSES') ?: '81.2.69.160,2.125.160.216,1.2.0.1,2001:218::1,10.0.0.1')));

try {
    $reader = Reader::open($path);
} catch (ExceptionInterface $e) {
    $io->error($e->getMessage());

    exit(1);
}

$metadata = $reader->metadata();

$io->info('1 - the file');
$io->values([
    'path' => $path,
    'database_type' => $reader->databaseType(),
    'built' => $reader->buildDate()->format('Y-m-d H:i T'),
    'ip version' => $metadata->ipVersion,
    'languages' => implode(', ', $metadata->languages),
    'reads' => implode(', ', array_filter(array_keys(Reader::READS), $reader->supports(...))),
]);

$io->line();
$io->info('3 - answers');

foreach ($addresses as $address) {
    try {
        $line = match (true) {
            $reader->supports('city') => (static function () use ($reader, $address): string {
                $city = $reader->city($address);

                return sprintf('%s, %s - %s', $city->city->name() ?? '?', $city->country->isoCode ?? '?', $city->traits->network);
            })(),
            $reader->supports('country') => (static function () use ($reader, $address): string {
                $country = $reader->country($address);

                return sprintf('%s - %s', $country->country->isoCode ?? '?', $country->traits->network);
            })(),
            $reader->supports('anonymizer') => (static function () use ($reader, $address): string {
                $answer = $reader->anonymizer($address)->anonymizer;

                return sprintf(
                    'flagged %s, vpn %s, confidence %s, provider %s, residential %s',
                    $answer->isFlagged() ? 'yes' : 'no',
                    match ($answer->isAnonymousVpn) {
                        true => 'yes',
                        false => 'no',
                        null => 'not known',
                    },
                    $answer->confidence ?? '-',
                    $answer->providerName ?? '-',
                    $answer->residential === null ? 'not carried' : ($answer->residential->providerName ?? '-')
                );
            })(),
            default => json_encode($reader->raw($address)[0]) ?: '?',
        };
    } catch (ExceptionInterface $e) {
        $line = $e::class . ': ' . $e->getMessage();
    }

    $io->line(sprintf('  %-16s %s', $address, $line));
}

$io->line();
$io->info('2 - timing');

$method = $reader->supports('city') ? 'city' : ($reader->supports('country') ? 'country' : null);

if ($method === null) {
    $io->warn('this file answers neither city() nor country() - NOT ANSWERED by this run: 2');

    exit(0);
}

$sample = $addresses[0];
$count = 5000;
$started = hrtime(true);

for ($i = 0; $i < $count; $i++) {
    try {
        $reader->{$method}($sample);
    } catch (ExceptionInterface) {
    }
}

$elapsed = (hrtime(true) - $started) / 1e9;

if (extension_loaded('xdebug')) {
    $io->warn('xdebug is loaded, which roughly triples the time below - run with `php -n` to measure the reader');
}

$io->values([
    'lookups' => sprintf('%d x %s(%s)', $count, $method, $sample),
    'decoder' => extension_loaded('maxminddb') ? 'ext-maxminddb, the C extension' : 'pure PHP',
    'elapsed' => sprintf('%.3f s', $elapsed),
    'per second' => sprintf('%.0f', $count / $elapsed),
    'peak memory' => sprintf('%.1f MB', memory_get_peak_usage(true) / 1048576),
]);
