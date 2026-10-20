<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Model;

/**
 * The base of the answers from single-purpose databases - ASN, ISP, Connection Type, Domain and
 * the anonymity databases - which are flat records with the address and network beside them.
 */
abstract class Network extends Model
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $ipAddress = null,
        public readonly ?string $network = null,
        array $raw = [],
    ) {
        parent::__construct($raw);
    }

    /**
     * @param  array<string, mixed>  $record  as the file holds it
     */
    abstract public static function fromDatabase(array $record, string $ipAddress, ?string $network): static;
}
