<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Tests\Unit;

use GlpiPlugin\Ticketnumbering\Config\Settings;
use GlpiPlugin\Ticketnumbering\Service\NumberPattern;
use PHPUnit\Framework\TestCase;

/**
 * Covers the parts of the settings store that do not touch the database: the
 * supported itemtypes, their config keys and the defaults derived from them.
 */
final class SettingsKeysTest extends TestCase
{
    public function testTicketProblemAndChangeAreSupported(): void
    {
        self::assertSame(['Ticket', 'Problem', 'Change'], Settings::itemtypes());

        foreach (Settings::itemtypes() as $itemtype) {
            self::assertTrue(Settings::isSupported($itemtype), $itemtype);
        }
    }

    public function testUnknownItemtypesAreRejected(): void
    {
        self::assertFalse(Settings::isSupported('Computer'));
        self::assertFalse(Settings::isSupported('ticket'));
        self::assertFalse(Settings::isSupported(''));
    }

    public function testEachItemtypeHasItsOwnKeys(): void
    {
        self::assertSame('ticket_enabled', Settings::enabledKey('Ticket'));
        self::assertSame('ticket_pattern', Settings::patternKey('Ticket'));
        self::assertSame('problem_enabled', Settings::enabledKey('Problem'));
        self::assertSame('problem_pattern', Settings::patternKey('Problem'));
        self::assertSame('change_enabled', Settings::enabledKey('Change'));
        self::assertSame('change_pattern', Settings::patternKey('Change'));

        $keys = [];
        foreach (Settings::itemtypes() as $itemtype) {
            $keys[] = Settings::enabledKey($itemtype);
            $keys[] = Settings::patternKey($itemtype);
        }
        self::assertSame($keys, array_unique($keys), 'config keys must not collide');
    }

    public function testOnlyTicketsAreEnabledByDefault(): void
    {
        $defaults = Settings::defaults();

        self::assertSame(1, $defaults['ticket_enabled']);
        self::assertSame(0, $defaults['problem_enabled']);
        self::assertSame(0, $defaults['change_enabled']);
    }

    public function testEveryDefaultPatternIsValid(): void
    {
        foreach (Settings::defaults() as $key => $value) {
            if (!str_ends_with($key, '_pattern')) {
                continue;
            }

            self::assertSame([], NumberPattern::validate((string) $value), $key);
        }
    }

    public function testDefaultsCoverEveryItemtype(): void
    {
        self::assertCount(2 * count(Settings::itemtypes()), Settings::defaults());
    }
}
