# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this
repository.

## What this package is

`hampel/maxmind-api` — a PHP client for MaxMind's GeoIP2 web services, its downloaded GeoIP and
GeoLite databases, and its database download service, usable from any host application rather
than tied to one HTTP library or framework.

**It exists because `geoip2/geoip2` cannot be given an HTTP client.** v3.4.0 sits on
`maxmind/web-service-common` 0.11, which requires `ext-curl`, calls `curl_version()` in its
constructor and accepts only its own request factory. Inside an application that routes every
outbound request through its own stack — proxy settings, SSRF checks, timeouts — that bypasses all
three. `maxmind-db/reader` has no such problem, and this package depends on it rather than
reimplementing the `.mmdb` format.

## Commands

```bash
composer install
composer check                                  # lint, analyse, test - what CI runs
composer test                                   # phpunit
composer analyse                                # phpstan, level 10
composer format                                 # pint, PSR-12
vendor/bin/phpunit --filter test_name           # one test
vendor/bin/rig                                  # the exercises - see The harness
```

## The design, in five decisions

**1. A web service answer and a database answer are the same object.** A consumer that lets an
administrator choose a source writes its rules once. `Database\Reader` returns `Model\Country` and
`Model\City` from a file, adds the `traits.ip_address` and `traits.network` a file does not hold,
and maps the anonymity databases onto the Insights `Record\Anonymizer`. That mapping is clean:
Anonymous Plus differs from Insights only in calling its confidence `anonymizer_confidence`, and
the Residential Proxy database is exactly the Insights `anonymizer.residential` sub-object.
`AddressNotFoundException` is raised by both sources, with `statusCode` 0 from a file.

**2. Every property is nullable and every section is present.** MaxMind omits empty keys and adds
fields without a version bump — `anonymizer` arrived on 2025-11-19, `residential` on 2026-07-17.
Records are built from whatever arrived, `raw` keeps all of it, and an absent section is an empty
record rather than a null, so `$model->city->name()` never needs a guard. `is_*` flags are false
when absent, because MaxMind sends them only when true. The deprecated `traits.is_*` flags are
modelled, marked `@deprecated`, and point at `anonymizer`.

**3. An error is classified by status, then refined by code — never generic for a known status.**
Only `code` is stable, and MaxMind adds codes. `ApiException::classFor()` is the whole mapping. A
404 from a GeoIP web service is always `AddressNotFoundException`, with or without a body, since the
paths are fixed. A body is decoded only when `Content-Type` says JSON: download errors are plain
text and several statuses have no body.

**4. Redirects are the package's, not the client's.** PSR-18 does not say whether a client follows
them, and an SSRF-guarded one usually does not. `Connection::fetch()` follows the download
service's 302 itself, as a fresh request with no `Authorization`, only over https and only to a
host in `Config::DOWNLOAD_REDIRECT_HOSTS` — the exact R2 host MaxMind publishes for firewall
allow-lists, not `*.r2.cloudflarestorage.com`, which would accept anybody's bucket. A client that
followed the redirect itself hands back the final 200, which is accepted; whether it forwarded the
credential is outside what the package can see, and the README says so.

**5. Nothing large is held in a string.** Downloads stream to disk in 1 MB chunks, hashed as they
go, written under a temporary name and renamed into place only once the SHA-256 matches.
`Download\TarGz` reads the archive through `compress.zlib://` — not `PharData`, which needs the
`phar://` wrapper some hosts unregister, and which caches by path for the life of the process, so a
second archive at the same path silently re-extracts the first.

## Open questions from the design brief, and how they were settled

Settled on 2026-10-20, from `maxmind/openapi`, `GeoIP2-php`, `MaxMind-DB` and dev.maxmind.com.

| question | answer |
|---|---|
| `maxmind-db/reader` in `require` or `suggest`? | `require`, at `^1.14`. Reading files into the shared models is half the point, it is pure PHP with no dependencies, and 1.14.0 is the release that bounds decoder resources against a crafted file |
| tarball extraction here or in the consumer? | here, as `Download\TarGz`, so no consumer needs Phar |
| do the anonymity databases map onto `anonymizer`? | yes, all three — see decision 1 |
| the Anonymous Plus and Residential Proxy ids | `database_type` is `GeoIP-Anonymous-Plus` and `GeoIP-Residential-Proxy` — note `GeoIP-`, not `GeoIP2-` — read from MaxMind's test files. **The download edition ids are inferred** from the CSV names by dropping `-CSV`, the convention elsewhere; no page states them, and the download service answers 401 for any id without a key |
| does the sandbox serve minFraud report? | no — openapi gives `/transactions/report` no sandbox server, and the sandbox page lists only score, insights and factors |

## What is not built: minFraud

**Designed for, not built.** `Config` already carries `minfraudUri` — `minfraud.maxmind.com`, or
`sandbox.maxmind.com` — and `AccountKey` is the same credential, so minFraud is an endpoint and its
models, not a refactor. What it will need:

- `POST /minfraud/v2.0/{score|insights|factors}` with one JSON body for all three. Bad input comes
  back as an `INPUT_INVALID` or `INPUT_UNKNOWN` **warning inside a 200**, so warnings belong on the
  result rather than in an exception.
- `POST /minfraud/v2.0/transactions/report`, answering 204. **Live only**, and a write: its harness
  exercise needs the full three-layer guard from the `rig-harness` skill, which none of today's
  exercises does.
- `Json::encode()` and `Connection::streamFactory()` are already here for the request bodies.

## Sources

- **`maxmind/openapi`**, OpenAPI 3.1.2, spec version 0.1.0 — read as a reference to hand-write
  from, not generated from: it uses nested `allOf`, vendor media types and body-less errors.
- **`GeoIP2-php`**, for how MaxMind's own reader matches `database_type` (by substring, which is
  what lets `-Shield` and `Precision-Enterprise` variants through) and which keys each model reads.
- **`MaxMind-DB`**, for the test databases and their source records.

`tests/fixtures/README.md` records the commits the fixtures were copied from.

## The PSR-18 seam

`Connection` is the only file that touches HTTP.

- **A PSR-18 client does not throw on an HTTP status**, only when the request never completed.
  That keeps `RequestException` (never reached MaxMind) apart from `ApiException` (MaxMind
  answered).
- **The catch is `ClientExceptionInterface`, not `\Throwable`.** Laravel's `StrayRequestException`
  is a plain `RuntimeException` and must reach a consumer's test untouched.
- **Do not add `php-http/discovery`.** `Support\Psr17Discovery` is copied verbatim from the other
  API packages and finds the common factories by class name.

## Credentials must not leak

The credential is HTTP basic authentication, so `Authorization` is the licence key in base64.
`AccountKey` withholds the key from `__toString()`, `__debugInfo()` and `json_encode()`;
`Connection` logs the method and URI and no header; `Connection::request()` refuses any origin but
the three configured services; and a presigned URL's query string — its signature — is withheld
from log lines and exception messages. **What this package cannot reach is a caught PSR-18
exception's request**, which a consumer's logger may dump.

## Dependency constraints are load-bearing

`psr/log` is `^1.1|^2.0|^3.0` and `psr/http-message` is `^1.1|^2.0` **on purpose**: XenForo
bundles `psr/log` 1.1.4, and a narrower constraint puts a second, signature-incompatible interface
on the autoloader. `tests/RecordingLogger.php` is untyped for the same reason.

`ext-filter` is required for address validation and `ext-zlib` for `compress.zlib://`, which no
symbol reveals to `composer-require-checker`.

## Structure

```text
src/
  Client.php              entry point; withAccountKey(); geoip(), downloads(), endpoint()
  Connection.php          the only file that touches HTTP; follows the download redirect
  Config.php              the three service hosts, live or sandbox, and the redirect allow-list
  Authentication/         AccountKey, behind an interface
  Endpoint/               GeoIp, Downloads; Endpoint is the extension point
  Model/                  Country < City < Insights; the single-purpose database models
  Record/                 the sections of an answer, each keeping `raw`
  Database/               Reader, over maxmind-db/reader
  Download/               Download, the result; TarGz, the extractor
  Exception/              by what a caller can do about it
  Support/                Address, Cast, Json, Psr17Discovery
```

## Testing

`tests/StubClient.php` is a PSR-18 client answering from a queue — the seam consumers use is the
seam the suite drives. `tests/Tar.php` builds tarballs byte by byte, so the extractor is tested
against long names, pax headers and truncation rather than whatever the local `tar` writes.

Two tests exist to catch a class of rot rather than a bug:

- `ModelTest::testEveryFieldInMaxMindsExamplesArrivesWithAValue()` walks each openapi example and
  checks every key with a value lands in the camelCase property of the same name, with a value.
  Verified by misspelling `ip_risk_snapshot` on purpose. Refresh the fixtures and it reports what
  MaxMind added.
- `ReadmeTest` follows every property and method chain the README uses through the declared types.

**A green suite cannot tell you MaxMind behaves this way.** The web fixtures are documented
examples, and every error case is constructed. That is what the harness is for.

## The harness

`vendor/bin/rig` runs the exercises in `harness/`. They are not tests: they make real calls and
print what happened for a person to read. Each carries the questions it was written to settle.

Nothing here writes anything at MaxMind, but two things cost, and each is opt-in and refused under
an agent:

| exercise | free by default | opt-in, lives in `.env` | agent override, never does |
|---|---|---|---|
| `database` | reads a local file only | — | — |
| `geoip` | the sandbox | `MAXMIND_GEOIP_LIVE=1` | `MAXMIND_AGENT_MAY_QUERY_LIVE=1` |
| `downloads` | a HEAD | `MAXMIND_DOWNLOAD=1` | `MAXMIND_AGENT_MAY_DOWNLOAD=1` |

**The sandbox and live accounts are separate**, so `.env` holds two credential pairs —
`MAXMIND_SANDBOX_*` and `MAXMIND_*`. The harness's Guzzle has `allow_redirects` off, as an
SSRF-guarded host client would, so the package's own redirect handling is what runs.

**If an exercise fails for want of a credential, that is the guard working.** Do not go looking for
the credentials, do not edit `.env`, and do not pass an override to get past the error. Ask.

### Measured

- **Lookup cost, 2026-10-20**, `database` on the City test file, pure PHP decoder, PHP 8.3: about
  3,000 lookups a second through `maxmind-db/reader` alone and about 2,750 through
  `Reader::city()`, so wrapping costs roughly a tenth. With Xdebug loaded both fall to about a
  third of that, which is why the exercise warns when it is.

## Version support

PHP 8.3, 8.4 and 8.5. PHPStan runs at level 10 with `phpVersion` spanning the range; keep
`phpstan.neon.dist` in step with the `php` constraint in `composer.json`. CI tests three corners —
the floor with `--prefer-lowest`, the floor, the ceiling — and runs `composer-require-checker` and
a dev-free PHPStan pass as separate checks, because they catch different things.

## Git

Commit freely; never push.
