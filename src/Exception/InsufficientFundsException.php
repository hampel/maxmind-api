<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * The account has run out of queries - HTTP 402, `INSUFFICIENT_FUNDS`.
 *
 * Its own class because an administrator has to act on it and nothing else will: every lookup
 * fails the same way until the account is topped up. `maxmind.queries_remaining` on each
 * successful answer is how to see it coming.
 */
final class InsufficientFundsException extends ApiException
{
}
