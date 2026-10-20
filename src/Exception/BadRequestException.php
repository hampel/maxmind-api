<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * HTTP 400 that is not about the address - `SERVICE_INVALID`, or a code MaxMind adds later.
 *
 * Not one a caller can fix by changing its input: the service names in this package's paths are
 * fixed, so this means MaxMind and the package disagree.
 */
final class BadRequestException extends ApiException
{
}
