<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * MaxMind failed - HTTP 5xx. A 503 arrives with no body. Not the caller's mistake, and usually
 * transient.
 */
final class ServerException extends ApiException
{
}
