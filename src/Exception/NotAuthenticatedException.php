<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * MaxMind did not accept the account id and licence key - HTTP 401, with
 * `AUTHORIZATION_INVALID`, `ACCOUNT_ID_REQUIRED` or `LICENSE_KEY_REQUIRED`.
 *
 * The commonest cause is not a wrong key but a RIGHT ONE FOR THE OTHER ENVIRONMENT: the sandbox
 * is a separate account with its own id and key, so a production key sent to the sandbox is
 * refused exactly like a typo. Check which Config the client was built with.
 */
final class NotAuthenticatedException extends ApiException
{
}
