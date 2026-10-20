<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * A database file could not be opened or read - absent, unreadable, not a MaxMind DB file, or
 * corrupt.
 *
 * maxmind-db/reader raises a mix of \InvalidArgumentException, \UnexpectedValueException and its
 * own InvalidDatabaseException for these; they arrive here as one class, with the original
 * chained.
 */
final class DatabaseException extends MaxMindException
{
}
