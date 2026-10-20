<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Exception;

/**
 * A downloaded archive could not be read, or held no `.mmdb` file.
 */
final class ArchiveException extends MaxMindException
{
}
