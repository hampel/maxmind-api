<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * MaxMind refused the address - HTTP 400 with `IP_ADDRESS_INVALID`, `IP_ADDRESS_RESERVED` or
 * `IP_ADDRESS_REQUIRED`, or any other `IP_ADDRESS_*` code it adds later.
 *
 * `IP_ADDRESS_RESERVED` is the one that reaches a working integration: a private or loopback
 * address - a request that came through a proxy whose forwarded header was not read - is a valid
 * address MaxMind will not look up. The package refuses a malformed address itself, before
 * sending, with InvalidArgumentException; a reserved one it leaves to MaxMind, whose list is the
 * authoritative one.
 */
final class InvalidAddressException extends ApiException
{
}
