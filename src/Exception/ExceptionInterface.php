<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * Every exception this package throws implements this, so a consumer can catch "something went
 * wrong in the MaxMind client" without naming a hierarchy.
 *
 * That is the catch a lookup on a request path wants. A registration form that asks where an
 * address is from, and treats any failure as "allow", should not have to know which of a dozen
 * things went wrong - only that the answer did not arrive.
 */
interface ExceptionInterface extends \Throwable
{
}
