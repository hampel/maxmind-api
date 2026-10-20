<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * HTTP 404 that is not about an address - a download edition or date that does not exist, or one
 * the account has no access to.
 *
 * A 404 from a GeoIP web service is always an AddressNotFoundException, a subclass of this, even
 * with no body: the paths this package sends are fixed, so the only thing that can be missing is
 * the address.
 */
class NotFoundException extends ApiException
{
}
