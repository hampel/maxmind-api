<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * HTTP 451 - MaxMind may not serve this download to this requester, for a legal reason: an
 * embargo, or the United States rules on bulk data transfers.
 *
 * ITS OWN CLASS BECAUSE NO RETRY WILL HELP, and a scheduled job that treats it as transient
 * retries for ever.
 */
final class LegallyBlockedException extends ApiException
{
}
