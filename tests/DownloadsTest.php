<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use GuzzleHttp\Psr7\Response;
use Hampel\MaxMind\Api\Config;
use Hampel\MaxMind\Api\Database\Reader;
use Hampel\MaxMind\Api\Exception\ChecksumMismatchException;
use Hampel\MaxMind\Api\Exception\InvalidArgumentException;
use Hampel\MaxMind\Api\Exception\LegallyBlockedException;
use Hampel\MaxMind\Api\Exception\NotAuthenticatedException;
use Hampel\MaxMind\Api\Exception\RuntimeException;
use Hampel\MaxMind\Api\Exception\TooManyRequestsException;
use PHPUnit\Framework\Attributes\DataProvider;

final class DownloadsTest extends TestCase
{
    private const FILENAME = 'GeoLite2-Country_20261017.tar.gz';

    private function presigned(string $name = self::FILENAME): string
    {
        return 'https://' . self::R2_HOST . '/geoip-databases/GeoLite2-Country/' . $name . '?X-Amz-Signature=abc123&X-Amz-Credential=secret';
    }

    private function pushRedirectTo(string $location): void
    {
        $this->client->push(new Response(302, ['Location' => $location]));
    }

    private function pushFile(string $body, string $name = self::FILENAME): void
    {
        $this->client->push(new Response(200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename=' . $name,
            'Last-Modified' => 'Sat, 17 Oct 2026 14:05:12 GMT',
        ], $body));
    }

    private function pushChecksum(string $body, string $name = self::FILENAME): void
    {
        $this->pushRedirectTo($this->presigned($name . '.sha256'));
        $this->client->push(new Response(200, ['Content-Type' => 'text/plain'], hash('sha256', $body) . '  ' . $name . "\n"));
    }

    public function testLastModifiedIsAHeadWithTheCredential(): void
    {
        $this->client->push(new Response(200, ['Last-Modified' => 'Sat, 17 Oct 2026 14:05:12 GMT']));

        $modified = $this->maxmind()->downloads()->lastModified('GeoLite2-Country');

        $request = $this->client->lastRequest();
        $this->assertSame('HEAD', $request->getMethod());
        $this->assertSame('https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz', (string) $request->getUri());
        $this->assertSame(self::basicAuth(), $request->getHeaderLine('Authorization'));
        $this->assertSame('2026-10-17T14:05:12+00:00', $modified?->format(DATE_ATOM));
    }

    public function testAWeekdayThatDisagreesWithTheDateDoesNotMoveIt(): void
    {
        $this->client->push(new Response(200, ['Last-Modified' => 'Mon, 17 Oct 2026 14:05:12 GMT']));
        $this->client->push(new Response(200, ['Last-Modified' => 'not a date']));

        $this->assertSame('2026-10-17', $this->maxmind()->downloads()->lastModified('GeoLite2-Country')?->format('Y-m-d'));
        $this->assertNull($this->maxmind()->downloads()->lastModified('GeoLite2-Country'));
    }

    public function testADateSelectsARelease(): void
    {
        $this->client->push(new Response(200));
        $this->client->push(new Response(200));

        $this->maxmind()->downloads()->lastModified('GeoIP2-City', '2026-10-14');
        $this->assertStringContainsString('date=20261014', (string) $this->client->lastRequest()->getUri());

        $this->maxmind()->downloads()->lastModified('GeoIP2-City', new \DateTimeImmutable('2026-10-17'));
        $this->assertStringContainsString('date=20261017', (string) $this->client->lastRequest()->getUri());
    }

    /**
     * The whole point of following the redirect here rather than in the client.
     */
    public function testTheRedirectIsFollowedWithoutTheCredential(): void
    {
        $directory = $this->temporaryDirectory();
        $body = random_bytes(5000);

        $this->pushRedirectTo($this->presigned());
        $this->pushFile($body);
        $this->pushChecksum($body);

        $download = $this->maxmind()->downloads()->download('GeoLite2-Country', $directory . '/out.tar.gz');

        [$first, $second, $third, $fourth] = $this->client->requests;

        $this->assertSame('download.maxmind.com', $first->getUri()->getHost());
        $this->assertSame(self::basicAuth(), $first->getHeaderLine('Authorization'));

        $this->assertSame(self::R2_HOST, $second->getUri()->getHost());
        $this->assertFalse($second->hasHeader('Authorization'), 'the credential must not reach the presigned URL');
        $this->assertSame($this->presigned(), (string) $second->getUri());

        $this->assertStringContainsString('suffix=tar.gz.sha256', (string) $third->getUri());
        $this->assertStringContainsString('date=20261017', (string) $third->getUri(), 'the checksum is for the release received');
        $this->assertFalse($fourth->hasHeader('Authorization'));

        $this->assertSame($body, file_get_contents($directory . '/out.tar.gz'));
        $this->assertTrue($download->verified);
        $this->assertSame(hash('sha256', $body), $download->sha256);
        $this->assertSame(5000, $download->bytes);
        $this->assertSame(self::FILENAME, $download->filename);
        $this->assertSame('20261017', $download->releaseDate());
        $this->assertSame('2026-10-17', $download->lastModified?->format('Y-m-d'));
        $this->assertSame([], glob($directory . '/*.part'));
    }

    /**
     * A client that followed the redirect itself - Guzzle with allow_redirects on - hands back the
     * final 200, and that is a success too.
     */
    public function testAClientThatFollowedTheRedirectItselfIsAcceptedToo(): void
    {
        $directory = $this->temporaryDirectory();
        $body = 'already followed';

        $this->pushFile($body);
        $this->client->push(new Response(200, ['Content-Type' => 'text/plain'], hash('sha256', $body) . '  ' . self::FILENAME));

        $download = $this->maxmind()->downloads()->download('GeoLite2-Country', $directory . '/out.tar.gz');

        $this->assertTrue($download->verified);
        $this->assertCount(2, $this->client->requests);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedRedirects(): iterable
    {
        yield 'another host' => ['https://attacker.example.com/file.tar.gz'];
        yield 'another R2 bucket' => ['https://someone-else.r2.cloudflarestorage.com/file.tar.gz'];
        yield 'a lookalike' => ['https://' . self::R2_HOST . '.attacker.example/file.tar.gz'];
        yield 'plain http' => ['http://' . self::R2_HOST . '/file.tar.gz'];
        yield 'userinfo' => ['https://user:pass@' . self::R2_HOST . '/file.tar.gz'];
        yield 'no location' => [''];
    }

    #[DataProvider('refusedRedirects')]
    public function testARedirectAnywhereElseIsNotFollowed(string $location): void
    {
        $this->client->push(new Response(302, $location === '' ? [] : ['Location' => $location]));

        try {
            $this->maxmind()->downloads()->checksum('GeoLite2-Country');
            $this->fail('should raise');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not followed', $e->getMessage());
            $this->assertCount(1, $this->client->requests);
        }
    }

    public function testTheSignatureIsKeptOutOfMessages(): void
    {
        $this->pushRedirectTo($this->presigned());
        $this->client->push(new Response(403, ['Content-Type' => 'application/xml'], '<Error>SignatureDoesNotMatch</Error>'));

        try {
            $this->maxmind()->downloads()->checksum('GeoLite2-Country');
            $this->fail('should raise');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString('abc123', $e->getMessage());
            $this->assertStringContainsString('[signature withheld]', $e->getMessage());
        }
    }

    public function testAMismatchRemovesTheFileAndRaises(): void
    {
        $directory = $this->temporaryDirectory();

        $this->pushRedirectTo($this->presigned());
        $this->pushFile('truncated');
        $this->pushChecksum('the real file');

        try {
            $this->maxmind()->downloads()->download('GeoLite2-Country', $directory . '/out.tar.gz');
            $this->fail('should raise');
        } catch (ChecksumMismatchException $e) {
            $this->assertStringContainsString(hash('sha256', 'truncated'), $e->getMessage());
            $this->assertSame([], glob($directory . '/*'), 'nothing is left behind');
        }
    }

    public function testAnExistingFileIsReplacedOnlyOnceVerified(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory . '/out.tar.gz', 'the previous release');

        $this->pushRedirectTo($this->presigned());
        $this->pushFile('corrupt');
        $this->pushChecksum('something else');

        try {
            $this->maxmind()->downloads()->download('GeoLite2-Country', $directory . '/out.tar.gz');
        } catch (ChecksumMismatchException) {
        }

        $this->assertSame('the previous release', file_get_contents($directory . '/out.tar.gz'));
    }

    public function testItCanWriteToAStream(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertNotFalse($stream);

        $this->pushRedirectTo($this->presigned());
        $this->pushFile('streamed');

        $download = $this->maxmind()->downloads()->download('GeoLite2-Country', $stream, verify: false);

        rewind($stream);
        $this->assertSame('streamed', stream_get_contents($stream));
        $this->assertFalse($download->verified);
        $this->assertNull($download->path);
    }

    /**
     * Download, verify and extract - the call a scheduled job makes.
     */
    public function testDatabaseFetchesVerifiesAndExtractsTheMmdb(): void
    {
        $directory = $this->temporaryDirectory();
        $mmdb = (string) file_get_contents(self::database('GeoLite2-Country'));
        $archive = (string) file_get_contents((new Tar())
            ->file('GeoLite2-Country_20261017/LICENSE.txt', 'licence')
            ->file('GeoLite2-Country_20261017/GeoLite2-Country.mmdb', $mmdb)
            ->write($directory . '/build.tar.gz'));
        unlink($directory . '/build.tar.gz');

        $this->pushRedirectTo($this->presigned());
        $this->pushFile($archive);
        $this->pushChecksum($archive);

        $download = $this->maxmind()->downloads()->database('GeoLite2-Country', $directory . '/GeoLite2-Country.mmdb', workDirectory: $directory);

        $this->assertSame('GeoLite2-Country_20261017/GeoLite2-Country.mmdb', $download->member);
        $this->assertSame($directory . '/GeoLite2-Country.mmdb', $download->path);
        $this->assertSame(['GeoLite2-Country.mmdb'], array_map('basename', glob($directory . '/*') ?: []), 'the archive is removed');
        $this->assertSame('GB', Reader::open($directory . '/GeoLite2-Country.mmdb')->country('81.2.69.160')->country->isoCode);
    }

    /**
     * @return iterable<string, array{int, string, class-string<\Throwable>}>
     */
    public static function failures(): iterable
    {
        yield '401' => [401, 'Unauthorized', NotAuthenticatedException::class];
        yield '429, the daily limit' => [429, 'Daily download limit reached', TooManyRequestsException::class];
        yield '451, no retry will help' => [451, 'Unavailable For Legal Reasons', LegallyBlockedException::class];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[DataProvider('failures')]
    public function testFailuresArePlainTextAndTyped(int $status, string $text, string $expected): void
    {
        $this->client->pushRaw($status, $text, ['Content-Type' => 'text/plain; charset=utf-8']);

        $this->expectException($expected);
        $this->expectExceptionMessage($text);

        $this->maxmind()->downloads()->download('GeoLite2-Country', $this->temporaryDirectory() . '/x');
    }

    public function testTheSandboxHasNoDownloadService(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sandbox has none');

        $this->maxmind(Config::sandbox())->downloads()->lastModified('GeoLite2-City');
    }

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function badArguments(): iterable
    {
        yield 'an edition with a slash' => ['GeoLite2-City/../x', 'tar.gz', null];
        yield 'an empty edition' => ['', 'tar.gz', null];
        yield 'an unknown suffix' => ['GeoLite2-City', 'tar.bz2', null];
        yield 'a date that is not one' => ['GeoLite2-City', 'tar.gz', '20261340'];
        yield 'a day-month date' => ['GeoLite2-City', 'tar.gz', '17/10/2026'];
    }

    #[DataProvider('badArguments')]
    public function testBadArgumentsAreRefusedBeforeSending(string $edition, string $suffix, ?string $date): void
    {
        try {
            $this->maxmind()->downloads()->checksum($edition, $suffix, $date);
            $this->fail('should raise');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $this->client->requests);
        }
    }
}
