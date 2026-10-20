<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * The database file cannot answer this lookup - city() on a Country database, anonymousIp() on an
 * ASN one.
 *
 * Raised rather than answered with an empty model, because an empty model is indistinguishable
 * from an address MaxMind knows nothing about. Check Reader::databaseType() or Reader::supports()
 * before asking.
 */
final class DatabaseMismatchException extends MaxMindException
{
    /**
     * @param  list<string>  $accepts
     */
    public static function for(string $method, string $databaseType, array $accepts): self
    {
        return new self(sprintf(
            '%s() cannot read a %s database; it reads %s.',
            $method,
            $databaseType,
            implode(', ', $accepts)
        ));
    }
}
