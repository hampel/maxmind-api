<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * HTTP 429, often with no body.
 *
 * For the download service this is the daily download limit - 1,000 in 24 hours, 30 for a
 * GeoLite account. Downloads::lastModified() is a HEAD, which MaxMind does not count, and is how
 * to avoid spending one on a file that has not changed.
 */
final class TooManyRequestsException extends ApiException
{
}
