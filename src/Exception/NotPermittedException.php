<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * The credential is valid and may not use this service - HTTP 403, `PERMISSION_REQUIRED`.
 *
 * Usually an account that has bought one GeoIP service asking for another: a Country account
 * asking for Insights.
 */
final class NotPermittedException extends ApiException
{
}
