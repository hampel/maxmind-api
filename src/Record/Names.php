<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Record;

/**
 * A record with a locale map of names - `{"en": "Germany", "de": "Deutschland", "zh-CN": "德国"}`.
 *
 * The map is kept whole, because which language to show is the caller's decision and MaxMind
 * offers a different set of locales for different places. name() picks from it.
 */
trait Names
{
    /**
     * The first name available in the locales asked for, in order, falling back to English.
     *
     * `name(['de', 'fr'])` is the German name, else the French, else the English, else null.
     * English is appended rather than assumed first, so a caller who asks for German gets German
     * whenever MaxMind has it.
     *
     * @param  list<string>  $locales
     */
    public function name(array $locales = ['en']): ?string
    {
        foreach ([...$locales, 'en'] as $locale) {
            if (isset($this->names[$locale])) {
                return $this->names[$locale];
            }
        }

        return null;
    }
}
