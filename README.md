# MaxMind GeoIP2 client for PHP

[![Tests](https://github.com/hampel/maxmind-api/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/maxmind-api/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/maxmind-api.svg?style=flat-square)](https://packagist.org/packages/hampel/maxmind-api)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/maxmind-api.svg?style=flat-square)](https://packagist.org/packages/hampel/maxmind-api)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/maxmind-api.svg?style=flat-square)](https://github.com/hampel/maxmind-api/issues)
[![License](https://img.shields.io/packagist/l/hampel/maxmind-api.svg?style=flat-square)](https://packagist.org/packages/hampel/maxmind-api)

By [Simon Hampel](mailto:simon@hampelgroup.com)

A PHP client for [MaxMind](https://www.maxmind.com/)'s GeoIP2 web services and databases, built on
**PSR-18**. It looks addresses up with the Country, City Plus and Insights web services, reads
downloaded GeoIP and GeoLite `.mmdb` files into **the same models**, and downloads and verifies
those files.

**Why not `geoip2/geoip2`?** MaxMind's own client requires `ext-curl` and its own request factory,
so inside an application with its own HTTP stack — proxy settings, outbound SSRF checks, timeouts
— it bypasses all three. This package sends every request through whichever PSR-18 client you
give it. It reads `.mmdb` files with MaxMind's own `maxmind-db/reader`, which has no such
requirement.

## Installation

```bash
composer require hampel/maxmind-api
```

You also need a PSR-18 client and a PSR-17 factory for the web services and downloads. Guzzle
provides both, 7 or 8:

```bash
composer require guzzlehttp/guzzle
```

Reading a downloaded database needs neither.

## Web services

```php
use GuzzleHttp\Client as Guzzle;
use Hampel\MaxMind\Api\Client;

$maxmind = Client::withAccountKey(123456, $licenseKey, new Guzzle(['timeout' => 2]));

$country = $maxmind->geoip()->country('203.0.113.7');
$city = $maxmind->geoip()->city('203.0.113.7');
$insights = $maxmind->geoip()->insights('203.0.113.7');

echo $country->country->isoCode;                   // "AU"
echo $city->city->name(['de', 'fr']);              // German, else French, else English
echo $insights->maxmind->queriesRemaining;
```

The account id and licence key come from the MaxMind account portal. `me` in place of an address
looks up whoever is asking, and is the default. A malformed address, or an IPv6 address with a
zone id, is refused with `InvalidArgumentException` before anything is sent.

The long form names everything:

```php
use Hampel\MaxMind\Api\Authentication\AccountKey;
use Hampel\MaxMind\Api\Config;

$maxmind = new Client(
    Config::live(),
    AccountKey::fromStrings($accountId, $licenseKey),   // or new AccountKey(123456, $key)
    new Guzzle(),
    logger: $logger,                                     // PSR-3, optional
);
```

PSR-17 factories are found automatically when Guzzle's, Nyholm's or Diactoros' are installed;
pass them to the constructor to choose.

### Three tiers, one model each

`Model\Insights` extends `Model\City`, which extends `Model\Country`, so code that needs only a
country takes a `Country` and accepts all three.

| service | adds |
|---|---|
| Country | `continent`, `country`, `registeredCountry`, `representedCountry`, `maxmind`, and `traits->network` |
| City Plus | `city`, `location`, `postal`, `subdivisions`, and the network owner in `traits` |
| Insights | `confidence` on the place records, `anonymizer`, income and density, the risk fields |

**Every section is always present and every property nullable.** MaxMind omits a key rather than
send it empty, so a Country answer still has a `city` record, with every property null:

```php
$country->country->name();                 // English, or null
$city->mostSpecificSubdivision()->isoCode; // "NSW" - the county rather than the state, when both
$city->location->accuracyRadius;           // km - the coordinates are an area, not a point
$country->traits->network;                 // "203.0.113.0/24" - every address in it answers alike
```

Every record and model keeps `raw`, the section as MaxMind sent it, so a field MaxMind adds before
this package models it is still readable.

### Anonymity

**The `anonymizer` section is Insights only, and the reason most integrations pay for it.**

```php
$anonymizer = $insights->anonymizer;

$anonymizer->isAnonymousVpn;               // true, false, or null - see below
$anonymizer->isTorExitNode;
$anonymizer->confidence;                   // 1-99 - compare with a threshold, never test a value
$anonymizer->providerName;                 // "nordvpn"
$anonymizer->residential?->providerName;   // the residential proxy feed
$anonymizer->isFlagged();                  // any flag true, or the residential feed has a record
$anonymizer->isComplete();                 // every flag and the feed answered - Insights only
```

**A flag is `false` when the source checked and `null` when it cannot say.** MaxMind sends a flag
only when it is true, so from Insights an absent flag is `false`. A database that does not cover a
flag answers `null` for it, and `residential` is `null` from a source without the residential
feed — see [Databases](#databases). The `residential` record can be filled when no flag is set,
which is why `isFlagged()` looks at it too. The `is_*` flags under `traits` are deprecated by MaxMind and modelled for completeness; read
`anonymizer`.

## Sandbox

**The sandbox is a separate MaxMind account, with its own id and key.** It answers with fixed data
for MaxMind's test addresses and `IP_ADDRESS_NOT_FOUND` for everything else:

```php
$sandbox = Client::withAccountKey($sandboxId, $sandboxKey, new Guzzle(), Config::sandbox());

$sandbox->config()->isSandbox();       // true
$maxmind->withConfig(Config::sandbox()); // the same transport, pointed at the sandbox
```

The sandbox has no download service.

## Databases

`Database\Reader` opens a downloaded `.mmdb` file and answers with the same models as the web
services, so the code that reads an answer does not care which source it came from:

```php
use Hampel\MaxMind\Api\Database\Reader;

$reader = Reader::open('/path/to/GeoLite2-City.mmdb');

$reader->city('203.0.113.7')->city->name();
$reader->country('203.0.113.7')->country->isoCode;  // a City or Enterprise file answers this too
```

| method | reads | answers with |
|---|---|---|
| `country()` | Country, City, Enterprise | `Model\Country` |
| `city()` | City, Enterprise | `Model\City` |
| `anonymizer()` | Anonymous IP, Anonymous Plus, Residential Proxy | `Model\AnonymousIp`, holding the Insights `Anonymizer` |
| `asn()` | GeoLite2 ASN, ISP | `Model\Asn` |
| `isp()`, `connectionType()`, `domain()` | the database of that name | a model of that name |

**`anonymizer()` answers with the same record as Insights**, so a rule written against
`$anonymizer->isAnonymousVpn` runs unchanged on a file. How much is filled depends on the file,
and what a file does not cover is `null`, never `false`:

| file | flags | confidence, provider, last seen | `residential` |
|---|---|---|---|
| Anonymous IP | `true` or `false` | `null` | `null` |
| Anonymous Plus | `true` or `false` | filled | `null` |
| Residential Proxy | `null` | `null` | filled |

The file's own `database_type` decides what it can answer. A lookup it cannot answer raises
`DatabaseMismatchException` rather than returning an empty model; ask first with
`$reader->supports('city')`. An address the file has no record for raises
`AddressNotFoundException` — the same class the web services raise.

**Open a file once and keep the `Reader`.** Opening reads the file's metadata; a lookup is cheap
after that. `$reader->buildDate()` is when MaxMind built the file. maxmind-db/reader uses the
`maxminddb` C extension automatically when it is loaded, which is several times faster.

## Downloads

```php
$downloads = $maxmind->downloads();

$downloads->lastModified('GeoLite2-City');                          // a HEAD - free
$download = $downloads->database('GeoLite2-City', '/data/GeoLite2-City.mmdb');

$download->lastModified;   // keep it, and compare before the next download
$download->verified;       // true - checked against MaxMind's published SHA-256
```

**A download counts against a daily limit** — 1,000, or 30 for a GeoLite account — and a HEAD
does not, so compare `lastModified()` with what you kept before downloading again.

`database()` downloads the `.tar.gz`, verifies it, and extracts the `.mmdb` inside it over the
target path atomically, so a `Reader` with the old file open is not disturbed. It reads the
tarball without Phar, so nothing needs a `phar://` stream wrapper restored first. `download()`
fetches an archive to a path or an open stream without extracting it, and `checksum()` fetches
the published SHA-256.

**The download service redirects to a presigned Cloudflare R2 URL**, and the package follows that
redirect itself, without the credential, and only to the host MaxMind publishes for firewall
allow-lists. A client that follows redirects itself also works, provided it drops the
`Authorization` header on a change of host, as Guzzle does.

## Errors

Every exception implements `ExceptionInterface`, so a lookup on a request path that should never
fail the request catches one thing:

```php
use Hampel\MaxMind\Api\Exception\ExceptionInterface;

try {
    $country = $maxmind->geoip()->country($ip);
} catch (ExceptionInterface $e) {
    $country = null;   // no answer - allow, and log it
}
```

| exception | when |
|---|---|
| `RequestException` | no answer at all: DNS, TLS, a timeout |
| `AddressNotFoundException` | MaxMind, or the file, has no data for the address |
| `InvalidAddressException` | MaxMind refused the address — `IP_ADDRESS_RESERVED` for a private one |
| `NotAuthenticatedException` | a wrong id or key, or a key for the other environment |
| `InsufficientFundsException` | the account is out of queries |
| `NotPermittedException` | the account may not use this service |
| `TooManyRequestsException` | a rate limit, or the daily download limit |
| `LegallyBlockedException` | HTTP 451 — a legal block on a download, which no retry will fix |
| `ServerException` | MaxMind failed |
| `ChecksumMismatchException` | a download did not match its published SHA-256 |
| `DatabaseException` | a file could not be opened or read |

Each `ApiException` has `$e->statusCode`, MaxMind's `$e->errorCode` and the raw `$e->body`. **Only
the code is stable** — MaxMind may reword a message — and an error code this package has not seen
lands in its status's class, so a new code does not change which `catch` runs.

**Set a short timeout on the PSR-18 client for any lookup a person is waiting on.** Every failure
here is an exception you can treat as "no answer", but only the client can stop the wait.

## Logging

Pass a PSR-3 logger and each request is logged at `debug`, with its method and URI. Nothing else
is logged: no header, so never the licence key, and no presigned signature. Failures are thrown,
never logged, so whether one is worth recording is your decision.

## Testing

```bash
composer check      # lint, analyse, test
```

## Licence

MIT. The test fixtures in `tests/fixtures/` are MaxMind's, used under the MIT option of their
Apache-2.0 OR MIT licence; see `tests/fixtures/README.md`.
