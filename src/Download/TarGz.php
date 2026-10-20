<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Download;

use Hampel\MaxMind\Api\Exception\ArchiveException;

/**
 * Reading the `.mmdb` file out of a downloaded `.tar.gz`, without Phar.
 *
 * WHY NOT PharData. It needs the `phar://` stream wrapper, which XenForo - among others -
 * unregisters, so a consumer there must restore it first; and PHP caches a Phar by path for the
 * life of the process, so a second archive downloaded to the same path re-extracts the first one,
 * silently. A tar file is a run of 512-byte headers each followed by its data, and reading one
 * member out of it is short enough to own.
 *
 * Reads through `compress.zlib://`, so the archive is decompressed as it streams and never held
 * whole in memory. Understands plain, ustar and GNU names, GNU long names (`L`) and pax `path`
 * records (`x`) - every way a tool can write a long member name.
 *
 * THE MEMBER'S NAME NEVER BECOMES A PATH. The file is written to the target the caller gives,
 * so a crafted name like `../../etc/x.mmdb` has nowhere to go.
 */
final class TarGz
{
    private const BLOCK = 512;

    private const CHUNK = 1048576;

    /**
     * Write the first member whose name ends in $suffix to $target, replacing it atomically, and
     * answer the member's name - `GeoLite2-City_20261017/GeoLite2-City.mmdb`.
     *
     * The data is written beside $target under a temporary name and renamed over it only when
     * complete, so a Reader with the old file open, or a crash half way, never sees half a file.
     */
    public static function extract(string $archive, string $target, string $suffix = '.mmdb'): string
    {
        $in = @fopen('compress.zlib://' . $archive, 'rb');

        if ($in === false) {
            throw new ArchiveException(sprintf('Could not open "%s" as a gzip file.', $archive));
        }

        try {
            $longName = null;

            while (true) {
                $header = self::readExactly($in, self::BLOCK);

                if ($header === null || trim($header, "\0") === '') {
                    break;
                }

                $type = $header[156];
                $size = self::size($header);
                $name = $longName ?? self::name($header);
                $longName = null;

                if ($type === 'L' || $type === 'x') {
                    $data = self::readExactly($in, $size) ?? throw new ArchiveException(sprintf('"%s" ends inside a header record.', $archive));
                    self::skip($in, self::padding($size));

                    $longName = $type === 'L' ? rtrim($data, "\0") : self::paxPath($data);

                    continue;
                }

                $isFile = $type === '0' || $type === "\0";

                if ($isFile && str_ends_with(strtolower($name), strtolower($suffix))) {
                    self::copyTo($in, $size, $target, $archive);

                    return $name;
                }

                self::skip($in, $size + self::padding($size));
            }
        } finally {
            fclose($in);
        }

        throw new ArchiveException(sprintf('"%s" holds no file ending in %s.', $archive, $suffix));
    }

    /**
     * @param  resource  $in
     */
    private static function copyTo($in, int $size, string $target, string $archive): void
    {
        $temporary = sprintf('%s.%s.part', $target, bin2hex(random_bytes(6)));
        $out = @fopen($temporary, 'xb');

        if ($out === false) {
            throw new ArchiveException(sprintf('Could not create "%s" to extract into.', $temporary));
        }

        try {
            $remaining = $size;

            while ($remaining > 0) {
                $chunk = fread($in, min(self::CHUNK, $remaining));

                if ($chunk === false || $chunk === '') {
                    throw new ArchiveException(sprintf('"%s" ends %d bytes before the end of its database.', $archive, $remaining));
                }

                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new ArchiveException(sprintf('Could not write to "%s" - is the disk full?', $temporary));
                }

                $remaining -= strlen($chunk);
            }

            fclose($out);
            $out = null;

            if (!@rename($temporary, $target)) {
                throw new ArchiveException(sprintf('Could not move the extracted database into place at "%s".', $target));
            }
        } finally {
            if ($out !== null) {
                fclose($out);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function name(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");

        if (substr($header, 257, 5) === 'ustar') {
            $prefix = rtrim(substr($header, 345, 155), "\0");

            if ($prefix !== '') {
                $name = $prefix . '/' . $name;
            }
        }

        return $name;
    }

    /**
     * The size field: octal text, or - for a member over 8 GB, which GNU tar writes - big-endian
     * binary flagged by the high bit of the first byte.
     */
    private static function size(string $header): int
    {
        $field = substr($header, 124, 12);

        if ((ord($field[0]) & 0x80) !== 0) {
            $size = ord($field[0]) & 0x7F;

            for ($i = 1; $i < 12; $i++) {
                $size = ($size << 8) | ord($field[$i]);
            }

            return $size;
        }

        $octal = trim($field, " \0");

        if ($octal === '' || preg_match('/^[0-7]+$/', $octal) !== 1) {
            throw new ArchiveException('A tar header has a size field that is not a number; the archive is corrupt or not a tar file.');
        }

        return (int) octdec($octal);
    }

    /**
     * The `path` from a pax extended header - records of `<length> <key>=<value>\n`.
     */
    private static function paxPath(string $data): ?string
    {
        foreach (explode("\n", $data) as $record) {
            if (preg_match('/^\d+ path=(.*)$/s', $record, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    private static function padding(int $size): int
    {
        return (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
    }

    /**
     * @param  resource  $in
     */
    private static function readExactly($in, int $length): ?string
    {
        if ($length === 0) {
            return '';
        }

        $data = '';

        while (($remaining = $length - strlen($data)) > 0) {
            $chunk = fread($in, $remaining);

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param  resource  $in
     */
    private static function skip($in, int $length): void
    {
        while ($length > 0) {
            $chunk = fread($in, min(self::CHUNK, $length));

            if ($chunk === false || $chunk === '') {
                return;
            }

            $length -= strlen($chunk);
        }
    }
}
