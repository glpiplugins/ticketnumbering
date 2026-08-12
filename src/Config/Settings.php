<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Config;

use Config;
use GlpiPlugin\Ticketnumbering\Service\NumberPattern;

/**
 * Configuration store for the plugin, backed by GLPI's `glpi_configs` table
 * under the `plugin:ticketnumbering` context.
 *
 * The defaults reproduce the behaviour the plugin had before the pattern was
 * configurable (`{YY}{MM}#####` → YYMM#####), so an existing installation keeps
 * numbering tickets exactly as before until an administrator changes it.
 */
final class Settings
{
    public const CONTEXT = 'plugin:ticketnumbering';

    public const DEFAULT_PATTERN = '{YY}{MM}#####';

    /**
     * @return array<string,int|string>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => 1,
            'pattern' => self::DEFAULT_PATTERN,
        ];
    }

    /**
     * Stored configuration merged over the defaults — what the config form renders.
     *
     * @return array<string,int|string>
     */
    public static function getRaw(): array
    {
        $stored = Config::getConfigurationValues(self::CONTEXT);

        return array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function isEnabled(): bool
    {
        return (int) self::getRaw()['enabled'] === 1;
    }

    /**
     * The stored pattern, or the default when the stored value is unusable (which
     * should not happen — {@see save()} rejects invalid input — but a hand-edited
     * config row must not break ticket creation).
     */
    public static function pattern(): string
    {
        $pattern = NumberPattern::normalize((string) self::getRaw()['pattern']);

        return NumberPattern::isValid($pattern) ? $pattern : self::DEFAULT_PATTERN;
    }

    /** The pattern exactly as stored, without the fallback — for the config form. */
    public static function storedPattern(): string
    {
        return NumberPattern::normalize((string) self::getRaw()['pattern']);
    }

    /**
     * Validate and persist the configuration form.
     *
     * @param array<string,mixed> $input
     * @return list<string> validation errors; nothing is written when non-empty
     */
    public static function save(array $input): array
    {
        $pattern = NumberPattern::normalize((string) ($input['pattern'] ?? ''));

        $errors = NumberPattern::validate($pattern);
        if ($errors !== []) {
            return $errors;
        }

        Config::setConfigurationValues(self::CONTEXT, [
            'enabled' => (int) ($input['enabled'] ?? 0) === 1 ? 1 : 0,
            'pattern' => $pattern,
        ]);

        return [];
    }

    /** Seed the defaults on install without overwriting an existing configuration. */
    public static function install(): void
    {
        $stored = Config::getConfigurationValues(self::CONTEXT);
        $missing = array_diff_key(self::defaults(), is_array($stored) ? $stored : []);

        if ($missing !== []) {
            Config::setConfigurationValues(self::CONTEXT, $missing);
        }
    }

    /** Remove the plugin's configuration rows. */
    public static function uninstall(): void
    {
        Config::deleteConfigurationValues(self::CONTEXT, array_keys(self::defaults()));
    }
}
