<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Support;

use Hampel\MaxMind\Api\Exception\InvalidArgumentException;

/**
 * An IP address checked before it goes anywhere.
 *
 * Refused here rather than sent: a malformed address costs a round trip on the web service - and,
 * on a registration path, the time somebody is waiting - only to come back as a 400. What is
 * left to MaxMind is whether a well-formed address is one it will look up: a private or reserved
 * address is valid here and `IP_ADDRESS_RESERVED` there, because its list is the authoritative
 * one.
 */
final class Address
{
    /**
     * The literal the web services accept in place of an address: "whoever is asking".
     */
    public const ME = 'me';

    /**
     * An IPv4 or IPv6 address, trimmed. With $allowMe, also the literal `me`.
     *
     * AN IPv6 ZONE ID IS REFUSED - `fe80::1%eth0`. It names an interface on the machine that saw
     * the address, MaxMind rejects it, and a link-local address has no location anyway.
     */
    public static function check(string $address, bool $allowMe = false): string
    {
        $address = trim($address);

        if ($allowMe && $address === self::ME) {
            return $address;
        }

        if (str_contains($address, '%')) {
            throw new InvalidArgumentException(sprintf('"%s" carries an IPv6 zone id, which MaxMind does not accept; look up the address without it.', $address));
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not an IPv4 or IPv6 address.', $address));
        }

        return $address;
    }

    /**
     * The network an address belongs to, as CIDR, from the prefix length a database lookup
     * answers with - `81.2.69.160` and 27 is `81.2.69.160/27`.
     *
     * The web services send `traits.network` themselves; a database file gives only the prefix
     * length, so this is what makes the two sources hold the same field.
     */
    public static function network(string $address, int $prefixLength): ?string
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;

        if ($prefixLength < 0 || $prefixLength > $bits) {
            return null;
        }

        $masked = '';

        for ($i = 0, $length = strlen($packed); $i < $length; $i++) {
            $keep = max(0, min(8, $prefixLength - $i * 8));
            $masked .= chr(ord($packed[$i]) & (0xFF << (8 - $keep)) & 0xFF);
        }

        $network = inet_ntop($masked);

        return $network === false ? null : $network . '/' . $prefixLength;
    }
}
