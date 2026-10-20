<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

use Hampel\MaxMind\Api\Record\Anonymizer;

/**
 * An answer from one of the anonymity databases - Anonymous IP, Anonymous Plus or Residential
 * Proxy - as the same Anonymizer record Insights answers with.
 *
 * So a rule that reads `$answer->anonymizer` runs unchanged whichever source an administrator
 * chose. What differs is how much is filled: Anonymous IP has the flags only, Anonymous Plus adds
 * confidence, provider and last-seen date, and Residential Proxy fills only
 * `anonymizer->residential`.
 */
final class AnonymousIp extends Network
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly Anonymizer $anonymizer = new Anonymizer(),
        ?string $ipAddress = null,
        ?string $network = null,
        array $raw = [],
    ) {
        parent::__construct($ipAddress, $network, $raw);
    }

    public static function fromDatabase(array $record, string $ipAddress, ?string $network): static
    {
        return new self(Anonymizer::fromDatabase($record), $ipAddress, $network, $record);
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public static function fromResidentialDatabase(array $record, string $ipAddress, ?string $network): self
    {
        return new self(Anonymizer::fromResidentialDatabase($record), $ipAddress, $network, $record);
    }
}
