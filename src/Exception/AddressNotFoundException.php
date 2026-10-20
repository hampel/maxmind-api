<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * MaxMind has no data for this address - `IP_ADDRESS_NOT_FOUND` from a web service, a 404 with no
 * body from one, or no record in a database file.
 *
 * RAISED FROM BOTH SOURCES ON PURPOSE, so a consumer that reads either a web service or a
 * downloaded file catches one class. `statusCode` says which: an HTTP status, or 0 for a file.
 *
 * ON THE SANDBOX THIS IS THE ORDINARY ANSWER. It holds fixed data for MaxMind's own test addresses
 * and answers this for everything else, so a sandbox that says "not found" to a real address is
 * working.
 *
 * Not a failure in itself: an address MaxMind knows nothing about is an answer. Whether to allow,
 * deny or ask again is the caller's decision.
 */
class AddressNotFoundException extends NotFoundException
{
    public static function inDatabase(string $ipAddress, string $databaseType): self
    {
        return new self(
            sprintf('The %s database has no record for %s.', $databaseType, $ipAddress),
            0,
            'IP_ADDRESS_NOT_FOUND'
        );
    }
}
