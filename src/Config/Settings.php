<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Config;

use Config;
use GlpiPlugin\Ticketnumbering\Service\NumberPattern;

/**
 * Configuration store for the plugin, backed by GLPI's `glpi_configs` table
 * under the `plugin:ticketnumbering` context.
 *
 * Every supported itemtype is configured on its own, with the keys
 * `<itemtype>_enabled` and `<itemtype>_pattern` (lower-cased itemtype). Tickets
 * default to `{YY}{MM}#####` — the behaviour the plugin had before the pattern
 * was configurable — while problems and changes are off by default, so enabling
 * the plugin never renumbers something the administrator did not ask for.
 */
final class Settings
{
    public const CONTEXT = 'plugin:ticketnumbering';

    public const DEFAULT_PATTERN = '{YY}{MM}#####';

    /**
     * Itemtypes whose id this plugin can control. Only classes listed here are
     * ever turned into a table name, so an itemtype can never come from input.
     *
     * @var list<string>
     */
    private const ITEMTYPES = ['Ticket', 'Problem', 'Change'];

    /**
     * Keys used before 1.2.0, when only tickets were supported.
     *
     * @var array<string,string> legacy key => current key
     */
    private const LEGACY_KEYS = [
        'enabled' => 'ticket_enabled',
        'pattern' => 'ticket_pattern',
    ];

    /**
     * @return list<string>
     */
    public static function itemtypes(): array
    {
        return self::ITEMTYPES;
    }

    public static function isSupported(string $itemtype): bool
    {
        return in_array($itemtype, self::ITEMTYPES, true);
    }

    /** Config key holding the on/off switch of an itemtype. */
    public static function enabledKey(string $itemtype): string
    {
        return strtolower($itemtype) . '_enabled';
    }

    /** Config key holding the pattern of an itemtype. */
    public static function patternKey(string $itemtype): string
    {
        return strtolower($itemtype) . '_pattern';
    }

    /**
     * @return array<string,int|string>
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::ITEMTYPES as $itemtype) {
            // Only tickets keep the pre-1.2.0 behaviour; the itemtypes added
            // later stay inactive until they are switched on deliberately.
            $defaults[self::enabledKey($itemtype)] = $itemtype === 'Ticket' ? 1 : 0;
            $defaults[self::patternKey($itemtype)] = self::DEFAULT_PATTERN;
        }

        return $defaults;
    }

    /**
     * Stored configuration merged over the defaults — what the config form renders.
     *
     * Values still stored under the pre-1.2.0 keys are honoured here as well, so
     * the plugin behaves correctly even before {@see install()} has migrated them.
     *
     * @return array<string,int|string>
     */
    public static function getRaw(): array
    {
        $stored = Config::getConfigurationValues(self::CONTEXT);
        $stored = is_array($stored) ? $stored : [];

        foreach (self::LEGACY_KEYS as $legacy => $current) {
            if (isset($stored[$legacy]) && !isset($stored[$current])) {
                $stored[$current] = $stored[$legacy];
            }
            unset($stored[$legacy]);
        }

        return array_merge(self::defaults(), $stored);
    }

    public static function isEnabled(string $itemtype): bool
    {
        if (!self::isSupported($itemtype)) {
            return false;
        }

        return (int) (self::getRaw()[self::enabledKey($itemtype)] ?? 0) === 1;
    }

    /**
     * The stored pattern of an itemtype, or the default when the stored value is
     * unusable. A pattern can become invalid over time — a ten-digit pattern
     * eventually outgrows the id column — and that must not break item creation.
     */
    public static function pattern(string $itemtype): string
    {
        $pattern = self::storedPattern($itemtype);

        return NumberPattern::isValid($pattern) ? $pattern : self::DEFAULT_PATTERN;
    }

    /** The pattern exactly as stored, without the fallback — for the config form. */
    public static function storedPattern(string $itemtype): string
    {
        if (!self::isSupported($itemtype)) {
            return self::DEFAULT_PATTERN;
        }

        return NumberPattern::normalize((string) (self::getRaw()[self::patternKey($itemtype)] ?? ''));
    }

    /**
     * Validate and persist the configuration form. Nothing is written unless every
     * submitted pattern is valid, so a rejected form never leaves half of the
     * itemtypes on a new pattern and half on the old one.
     *
     * @param array<string,mixed> $input `<lower itemtype>_enabled` / `_pattern`
     * @return array<string,list<string>> validation errors per itemtype; empty on success
     */
    public static function save(array $input): array
    {
        $errors = [];
        $values = [];

        foreach (self::ITEMTYPES as $itemtype) {
            $enabledKey = self::enabledKey($itemtype);
            $patternKey = self::patternKey($itemtype);

            $pattern = NumberPattern::normalize((string) ($input[$patternKey] ?? ''));
            $patternErrors = NumberPattern::validate($pattern);
            if ($patternErrors !== []) {
                $errors[$itemtype] = $patternErrors;
                continue;
            }

            $values[$enabledKey] = (int) ($input[$enabledKey] ?? 0) === 1 ? 1 : 0;
            $values[$patternKey] = $pattern;
        }

        if ($errors !== []) {
            return $errors;
        }

        Config::setConfigurationValues(self::CONTEXT, $values);

        return [];
    }

    /**
     * Seed the defaults on install without overwriting an existing configuration,
     * and move a pre-1.2.0 ticket configuration onto the per-itemtype keys.
     */
    public static function install(): void
    {
        $stored = Config::getConfigurationValues(self::CONTEXT);
        $stored = is_array($stored) ? $stored : [];

        $migrated = [];
        foreach (self::LEGACY_KEYS as $legacy => $current) {
            if (isset($stored[$legacy]) && !isset($stored[$current])) {
                $migrated[$current] = $stored[$legacy];
            }
        }
        if ($migrated !== []) {
            Config::setConfigurationValues(self::CONTEXT, $migrated);
            $stored += $migrated;
        }

        $missing = array_diff_key(self::defaults(), $stored);
        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }

        $obsolete = array_intersect(array_keys(self::LEGACY_KEYS), array_keys($stored));
        if ($obsolete !== []) {
            Config::deleteConfigurationValues(self::CONTEXT, array_values($obsolete));
        }
    }

    /** Remove the plugin's configuration rows, including the pre-1.2.0 ones. */
    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(
            self::CONTEXT,
            array_merge(array_keys(self::defaults()), array_keys(self::LEGACY_KEYS))
        );
    }
}
