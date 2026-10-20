<?php

/**
 * Exercise: ask the live download service what is current, and - opted in - download, verify and extract a database. Needs a live key; a download counts against the daily limit.
 *
 * QUESTIONS:
 *
 *   1. WHAT DOES A HEAD ANSWER - a 200 with Last-Modified, or the 302? lastModified() assumes a
 *      200 carrying the header; MaxMind documents a HEAD as free of the download limit.
 *   2. DOES THE 302 POINT AT THE PINNED HOST? Config::DOWNLOAD_REDIRECT_HOSTS names the R2 host
 *      MaxMind publishes for firewalls; a different one means every download fails until the
 *      Config is changed. Printed without the signature.
 *   3. WHAT IS IN THE .sha256 FILE? checksum() reads the first 64 hex digits and assumes
 *      sha256sum output - nobody has confirmed the format.
 *   4. DOES THE PRESIGNED RESPONSE CARRY Content-Disposition AND Last-Modified? The checksum is
 *      fetched for the release date in that filename, falling back to the URL path.
 *   5. WHAT IS INSIDE THE TARBALL? TarGz takes the first member ending .mmdb; printed is the
 *      member it took, and Reader's view of the result.
 *
 * Questions 2 to 5 cost downloads - the checksum may be one too - and run only with
 * MAXMIND_DOWNLOAD=1; an agent is refused that unless MAXMIND_AGENT_MAY_DOWNLOAD=1 is on its
 * command line. MAXMIND_EDITION picks the edition, GeoLite2-Country by default as the smallest.
 * The database is written to MAXMIND_DOWNLOAD_PATH, or a temporary file that is removed.
 *
 * Needs MAXMIND_ACCOUNT_ID and MAXMIND_LICENSE_KEY - the sandbox has no download service.
 *
 * @var Hampel\Rig\Io $io
 */

use Hampel\MaxMind\Api\Database\Reader;
use Hampel\MaxMind\Api\Exception\ExceptionInterface;

require __DIR__ . '/lib/harness.php';

$io->title('maxmind · downloads');

$download = harness_mode($io, 'MAXMIND_DOWNLOAD', 'MAXMIND_AGENT_MAY_DOWNLOAD', 'the .sha256, the archive and the extraction - questions 2 to 5');
$maxmind = harness_client($io, true);
harness_environment($io, $maxmind);

$edition = getenv('MAXMIND_EDITION') ?: 'GeoLite2-Country';
$uri = $maxmind->config()->downloadUri . '/geoip/databases/' . rawurlencode($edition) . '/download?suffix=tar.gz';

$io->info(sprintf('1 - HEAD %s, raw', $edition));

$head = harness_raw($maxmind, 'HEAD', $uri);

$io->values([
    'answer' => harness_describe($head, 0),
    'Last-Modified' => $head->getHeaderLine('Last-Modified') ?: 'ABSENT',
    'Content-Disposition' => $head->getHeaderLine('Content-Disposition') ?: 'ABSENT',
    'Location' => $head->hasHeader('Location') ? harness_redact($head->getHeaderLine('Location')) : 'ABSENT',
]);

try {
    $io->value('through the package', $maxmind->downloads()->lastModified($edition)?->format(DATE_ATOM) ?? 'null');
} catch (ExceptionInterface $e) {
    $io->error(sprintf('%s: %s', $e::class, $e->getMessage()));
}

if (!$download) {
    $io->line();
    $io->warn('NOT ANSWERED by this run: 2, 3, 4 and 5');

    exit(0);
}

$io->line();
$io->info('2 - the redirect, raw');

$first = harness_raw($maxmind, 'GET', $uri);
$location = $first->getHeaderLine('Location');

$io->values([
    'answer' => harness_describe($first, 200),
    'Location host' => $location === '' ? 'ABSENT' : (string) parse_url($location, PHP_URL_HOST),
    'pinned host' => $maxmind->config()->allowsDownloadRedirect($location) ? 'yes - matches' : 'NO - downloads will be refused',
]);

$path = getenv('MAXMIND_DOWNLOAD_PATH') ?: '';
$temporary = $path === '';
$path = $temporary ? sys_get_temp_dir() . '/maxmind-harness-' . bin2hex(random_bytes(4)) . '.mmdb' : $path;
$failure = null;

try {
    $io->line();
    $io->info('3 - the .sha256');

    $sha = harness_raw($maxmind, 'GET', str_replace('suffix=tar.gz', 'suffix=tar.gz.sha256', $uri));
    $shaLocation = $sha->getHeaderLine('Location');
    $file = $shaLocation !== '' && $maxmind->config()->allowsDownloadRedirect($shaLocation)
        ? $maxmind->connection()->client()->sendRequest($maxmind->connection()->requestFactory()->createRequest('GET', $shaLocation))
        : $sha;

    $io->values([
        'answer' => harness_describe($file, 0),
        'content' => var_export((string) $file->getBody(), true),
    ]);

    $io->line();
    $io->info('4 and 5 - through the package');

    $result = $maxmind->downloads()->database($edition, $path);
    $reader = Reader::open($path);

    $io->values([
        'filename' => $result->filename ?? 'ABSENT - no Content-Disposition, and none in the path',
        'release date' => $result->releaseDate() ?? '?',
        'Last-Modified' => $result->lastModified?->format(DATE_ATOM) ?? 'ABSENT',
        'bytes' => $result->bytes,
        'sha256' => $result->sha256,
        'verified' => $result->verified ? 'yes' : 'no',
        'member' => $result->member ?? '?',
        'database_type' => $reader->databaseType(),
        'built' => $reader->buildDate()->format(DATE_ATOM),
    ]);

    $reader->close();
} catch (ExceptionInterface $e) {
    $failure = $e;
} finally {
    if ($temporary && is_file($path)) {
        unlink($path);
    }
}

if ($failure !== null) {
    $io->error(sprintf('%s: %s', $failure::class, $failure->getMessage()));

    exit(1);
}
