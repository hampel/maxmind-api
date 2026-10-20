<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * A download did not match the SHA-256 MaxMind published for it - truncated, corrupted in transit,
 * or not the file that was asked for.
 *
 * Whatever was written is removed before this is raised when the package wrote to a path; a
 * caller that passed its own stream is responsible for discarding what is in it.
 */
final class ChecksumMismatchException extends MaxMindException
{
}
