<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Download\TarGz;
use Hampel\MaxMind\Api\Exception\ArchiveException;

final class TarGzTest extends TestCase
{
    public function testItExtractsTheFirstMmdbAndSkipsTheRest(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = (new Tar())
            ->file('GeoLite2-City_20261017/COPYRIGHT.txt', str_repeat('c', 700))
            ->file('GeoLite2-City_20261017/GeoLite2-City.mmdb', 'database bytes')
            ->file('GeoLite2-City_20261017/second.mmdb', 'not this one')
            ->write($directory . '/a.tar.gz');

        $member = TarGz::extract($archive, $directory . '/out.mmdb');

        $this->assertSame('GeoLite2-City_20261017/GeoLite2-City.mmdb', $member);
        $this->assertSame('database bytes', file_get_contents($directory . '/out.mmdb'));
    }

    public function testAUstarPrefixIsPartOfTheName(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = (new Tar())->file('some/directory/x.mmdb', 'data', ustarPrefix: true)->write($directory . '/a.tar.gz');

        $this->assertSame('some/directory/x.mmdb', TarGz::extract($archive, $directory . '/out.mmdb'));
    }

    public function testAGnuLongNameIsRead(): void
    {
        $directory = $this->temporaryDirectory();
        $long = str_repeat('d', 120) . '/GeoIP2-City.mmdb';
        $archive = (new Tar())->longName($long, 'long')->write($directory . '/a.tar.gz');

        $this->assertSame($long, TarGz::extract($archive, $directory . '/out.mmdb'));
        $this->assertSame('long', file_get_contents($directory . '/out.mmdb'));
    }

    public function testAPaxPathIsRead(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = (new Tar())->paxName('pax/GeoIP2-City.mmdb', 'pax')->write($directory . '/a.tar.gz');

        $this->assertSame('pax/GeoIP2-City.mmdb', TarGz::extract($archive, $directory . '/out.mmdb'));
        $this->assertSame('pax', file_get_contents($directory . '/out.mmdb'));
    }

    /**
     * The member's name never becomes a path.
     */
    public function testATraversingNameWritesOnlyToTheTarget(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = (new Tar())->file('../../../tmp/evil.mmdb', 'x')->write($directory . '/a.tar.gz');

        TarGz::extract($archive, $directory . '/out.mmdb');

        $this->assertSame(['a.tar.gz', 'out.mmdb'], array_map('basename', glob($directory . '/*') ?: []));
    }

    public function testAnExistingTargetIsReplaced(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory . '/out.mmdb', 'old');
        $archive = (new Tar())->file('x.mmdb', 'new')->write($directory . '/a.tar.gz');

        TarGz::extract($archive, $directory . '/out.mmdb');

        $this->assertSame('new', file_get_contents($directory . '/out.mmdb'));
    }

    public function testNoMmdbIsAnArchiveException(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = (new Tar())->file('README.txt', 'nothing here')->write($directory . '/a.tar.gz');

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('.mmdb');

        TarGz::extract($archive, $directory . '/out.mmdb');
    }

    public function testATruncatedArchiveLeavesNothingBehind(): void
    {
        $directory = $this->temporaryDirectory();
        $tar = (string) gzdecode((string) file_get_contents((new Tar())->file('x.mmdb', str_repeat('z', 4000))->write($directory . '/full.tar.gz')));
        file_put_contents($directory . '/cut.tar.gz', (string) gzencode(substr($tar, 0, 2048)));

        try {
            TarGz::extract($directory . '/cut.tar.gz', $directory . '/out.mmdb');
            $this->fail('should raise');
        } catch (ArchiveException $e) {
            $this->assertStringContainsString('ends', $e->getMessage());
        }

        $this->assertSame(['cut.tar.gz', 'full.tar.gz'], array_map('basename', glob($directory . '/*') ?: []));
    }

    public function testSomethingThatIsNotATarIsRefused(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory . '/a.tar.gz', (string) gzencode(str_repeat('not a tar header ', 64)));

        $this->expectException(ArchiveException::class);

        TarGz::extract($directory . '/a.tar.gz', $directory . '/out.mmdb');
    }
}
