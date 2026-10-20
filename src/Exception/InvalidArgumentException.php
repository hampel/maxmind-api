<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * The caller asked for something that cannot be sent - an address that is not one, an account id
 * that is not a positive integer, an edition id with a slash in it.
 *
 * Raised before a request is made, which is the point of it.
 */
class InvalidArgumentException extends \InvalidArgumentException implements ExceptionInterface
{
}
