<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

/**
 * Builds a small `.tar.gz` in memory, so the extractor is tested against archives whose every
 * byte the test chose - long names, pax headers, a missing member - rather than against whatever
 * the local `tar` happens to write.
 */
final class Tar
{
    /** @var list<string> */
    private array $blocks = [];

    public function file(string $name, string $data, string $type = '0', bool $ustarPrefix = false): self
    {
        $prefix = '';

        if ($ustarPrefix && str_contains($name, '/')) {
            $prefix = (string) substr($name, 0, (int) strrpos($name, '/'));
            $name = (string) substr($name, (int) strrpos($name, '/') + 1);
        }

        $header = str_pad(substr($name, 0, 100), 100, "\0")
            . str_pad('0000644', 8, "\0")
            . str_pad('0000000', 8, "\0")
            . str_pad('0000000', 8, "\0")
            . str_pad(sprintf('%011o', strlen($data)), 12, "\0")
            . str_pad(sprintf('%011o', 0), 12, "\0")
            . '        '
            . $type
            . str_repeat("\0", 100)
            . "ustar\0" . '00'
            . str_repeat("\0", 32 + 32 + 8 + 8)
            . str_pad($prefix, 155, "\0")
            . str_repeat("\0", 12);

        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);

        $this->blocks[] = $header;
        $this->blocks[] = $data . str_repeat("\0", (512 - strlen($data) % 512) % 512);

        return $this;
    }

    public function longName(string $name, string $data): self
    {
        return $this->file('././@LongLink', $name . "\0", 'L')->file(substr($name, 0, 99), $data);
    }

    public function paxName(string $name, string $data): self
    {
        $record = ' path=' . $name . "\n";
        $length = strlen($record);
        $length += strlen((string) ($length + strlen((string) $length)));

        return $this->file('PaxHeader/x', $length . $record, 'x')->file('short.bin', $data);
    }

    public function write(string $path): string
    {
        file_put_contents($path, (string) gzencode(implode('', $this->blocks) . str_repeat("\0", 1024)));

        return $path;
    }
}
