<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Support;

/**
 * Reading a value out of a MaxMind record without trusting it.
 *
 * Every one of these returns null - or an empty collection - rather than throwing when the value
 * is absent or the wrong shape. MaxMind omits a key rather than send it empty, and adds keys
 * without a version bump, so a reader that treated a missing one as an error would break on the
 * ordinary case.
 *
 * Shared by the web service and the database reader. A record decoded from an `.mmdb` file holds
 * native integers, floats and booleans where the JSON does, and the occasional 128-bit integer as
 * a numeric string, which int() refuses rather than truncates.
 */
final class Cast
{
    public static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_int($value) || is_float($value) ? (string) $value : null);
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : null;
    }

    public static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    public static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * A flag MaxMind sends only when it is true. Absent means false, as MaxMind documents for
     * every `is_*` key - a record does not carry `"is_anonymous": false`.
     */
    public static function flag(mixed $value): bool
    {
        return $value === true;
    }

    /**
     * The string-keyed part of a value that should have been an object.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    /**
     * A locale map - `{"en": "Germany", "de": "Deutschland"}` - with anything that is not a
     * string dropped.
     *
     * @return array<string, string>
     */
    public static function names(mixed $value): array
    {
        $names = [];

        foreach (self::object($value) as $locale => $name) {
            if (is_string($name) && $name !== '') {
                $names[$locale] = $name;
            }
        }

        return $names;
    }

    /**
     * A list of nested objects mapped into records. Anything that is not an object is skipped
     * rather than mapped, so one malformed element does not take the others with it.
     *
     * @template T
     * @param  callable(array<string, mixed>): T  $map
     * @return list<T>
     */
    public static function objects(mixed $value, callable $map): array
    {
        if (!is_array($value)) {
            return [];
        }

        $mapped = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $mapped[] = $map(self::object($item));
            }
        }

        return $mapped;
    }

    /**
     * A calendar date - `2026-10-14` - as midnight UTC, or null.
     *
     * MaxMind writes `network_last_seen` as a date with no time, so there is no instant to
     * preserve; UTC is chosen so that format('Y-m-d') gives back exactly what was sent.
     */
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
