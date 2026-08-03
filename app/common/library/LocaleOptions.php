<?php
declare(strict_types=1);

namespace App_skeleton;

/**
 * Backs the Timezone/Locale dropdowns on My Profile and the admin
 * profile editor (REQ-016). Both lists come straight from PHP's core
 * `date`/`intl` extensions rather than a bundled static list, so they
 * stay in sync with whatever tzdata/ICU version ships with the deployed
 * PHP automatically — see Requirements-List.md's REQ-016 entry for why
 * the two externally-proposed sources (pecl/timezonedb,
 * umpirsky/locale-list) were passed over in favor of this.
 */
class LocaleOptions
{
    /** @return string[] IANA timezone identifiers, e.g. "Australia/Perth" */
    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /**
     * Region-qualified locales only (e.g. "en-AU", not the bare "en") —
     * a locale used for date/number/currency formatting needs a country,
     * and that's the only kind this project has ever stored. Value is
     * BCP-47-hyphenated to match the existing 'en-AU' default and web
     * conventions (HTML lang=, Accept-Language); ICU's own listing uses
     * underscores, so this converts.
     *
     * @return array<string, string> locale code => display name, sorted by name
     */
    public static function locales(): array
    {
        $options = [];

        foreach (\ResourceBundle::getLocales('') as $icuLocale) {
            if (!str_contains($icuLocale, '_')) {
                continue;
            }

            $code = str_replace('_', '-', $icuLocale);
            $name = \Locale::getDisplayName($icuLocale, 'en');

            $options[$code] = $name !== null && $name !== '' ? $name : $code;
        }

        asort($options);

        return $options;
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, self::timezones(), true);
    }

    public static function isValidLocale(string $locale): bool
    {
        return array_key_exists($locale, self::locales());
    }
}
