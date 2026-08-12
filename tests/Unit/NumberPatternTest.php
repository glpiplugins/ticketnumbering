<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Tests\Unit;

use GlpiPlugin\Ticketnumbering\Service\InvalidPatternException;
use GlpiPlugin\Ticketnumbering\Service\NumberPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NumberPatternTest extends TestCase
{
    private int $july2026;
    private int $december2026;
    private int $january2027;

    protected function setUp(): void
    {
        $this->july2026     = (int) mktime(9, 0, 0, 7, 15, 2026);
        $this->december2026 = (int) mktime(9, 0, 0, 12, 1, 2026);
        // Friday 2027-01-01 belongs to ISO week 53 of ISO year 2026.
        $this->january2027  = (int) mktime(9, 0, 0, 1, 1, 2027);
    }

    public function testNormalizeUppercasesAndStripsWhitespace(): void
    {
        self::assertSame('{YY}{MM}#####', NumberPattern::normalize(' {yy} {mm} ##### '));
    }

    public function testDefaultPatternReproducesTheLegacyYymmNumbering(): void
    {
        $pattern = '{YY}{MM}#####';

        self::assertSame('2607', NumberPattern::prefixForDate($pattern, $this->july2026));
        self::assertSame(260700000, NumberPattern::blockBase($pattern, $this->july2026));
        self::assertSame([260700001, 260799999], NumberPattern::blockRange($pattern, $this->july2026));
        self::assertSame('260700001', NumberPattern::example($pattern, $this->july2026));
        self::assertSame(5, NumberPattern::counterDigits($pattern));
        self::assertSame(100000, NumberPattern::blockSize($pattern));
    }

    public function testMonthlyBlockHoldsUpToNinetyNineThousandNineHundredNinetyNineTickets(): void
    {
        [$first, $last] = NumberPattern::blockRange('{YY}{MM}#####', $this->july2026);

        self::assertSame(99999, $last - $first + 1);
    }

    public function testDecemberBlockIsAboveTheJulyBlock(): void
    {
        self::assertSame(261200000, NumberPattern::blockBase('{YY}{MM}#####', $this->december2026));
    }

    public function testYearlyPattern(): void
    {
        self::assertSame('202600001', NumberPattern::example('{YYYY}#####', $this->july2026));
        self::assertSame('2600001', NumberPattern::example('{YY}#####', $this->july2026));
    }

    public function testQuarterPattern(): void
    {
        self::assertSame('26300001', NumberPattern::example('{YY}{Q}#####', $this->july2026));
        self::assertSame('26400001', NumberPattern::example('{YY}{Q}#####', $this->december2026));
    }

    public function testDailyPattern(): void
    {
        self::assertSame('26071501', NumberPattern::example('{YY}{MM}{DD}##', $this->july2026));
    }

    public function testFixedPrefixPattern(): void
    {
        self::assertSame('10000001', NumberPattern::example('1#######', $this->july2026));
        self::assertSame([10000001, 19999999], NumberPattern::blockRange('1#######', $this->july2026));
    }

    public function testPlainCounterPatternHasNoBlock(): void
    {
        // Nothing in front of the counter: GLPI's own sequence must be left alone.
        self::assertSame('', NumberPattern::prefixForDate('#########', $this->july2026));
        self::assertSame(0, NumberPattern::blockBase('#########', $this->july2026));
        self::assertSame([0, 0], NumberPattern::blockRange('#########', $this->july2026));
    }

    public function testWeekPatternUsesTheIsoYearSoThePrefixNeverJumpsBackwards(): void
    {
        // 2027-01-01 is ISO week 53 of 2026. Using the calendar year would render
        // "2753", which is *lower* than the "2653" of the days before it.
        self::assertSame('2653', NumberPattern::prefixForDate('{YY}{WW}#####', $this->january2027));
        self::assertSame('2629', NumberPattern::prefixForDate('{YY}{WW}#####', $this->july2026));
    }

    #[DataProvider('validPatterns')]
    public function testValidPatternsAreAccepted(string $pattern): void
    {
        self::assertSame([], NumberPattern::validate($pattern), $pattern);
        self::assertTrue(NumberPattern::isValid($pattern), $pattern);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function validPatterns(): iterable
    {
        yield 'year + month'      => ['{YY}{MM}#####'];
        yield 'four digit year'   => ['{YYYY}#####'];
        yield 'two digit year'    => ['{YY}#####'];
        yield 'quarter'           => ['{YY}{Q}#####'];
        yield 'week'              => ['{YY}{WW}#####'];
        yield 'day'               => ['{YY}{MM}{DD}###'];
        yield 'fixed prefix'      => ['1#######'];
        yield 'plain counter'     => ['#########'];
        yield 'lowercase input'   => ['{yy}{mm}#####'];
        yield 'fixed plus date'   => ['9{YY}{MM}####'];
    }

    #[DataProvider('invalidPatterns')]
    public function testInvalidPatternsAreRejected(string $pattern): void
    {
        self::assertNotSame([], NumberPattern::validate($pattern), $pattern);
        self::assertFalse(NumberPattern::isValid($pattern), $pattern);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'empty'                     => [''];
        yield 'no counter'                => ['{YY}{MM}'];
        yield 'counter not at the end'    => ['{YY}#####{MM}'];
        yield 'two counter blocks'        => ['{YY}###{MM}##'];
        yield 'literal after placeholder' => ['{YY}12{MM}#####'];
        yield 'unknown placeholder'       => ['{YEAR}#####'];
        yield 'unclosed placeholder'      => ['{YY#####'];
        yield 'stray closing brace'       => ['YY}#####'];
        yield 'letters'                   => ['AB-{YY}#####'];
        yield 'separator characters'      => ['{YY}-{MM}-#####'];
        yield 'ascending units'           => ['{MM}{YY}#####'];
        yield 'duplicate placeholder'     => ['{YY}{MM}{MM}###'];
        yield 'month without year'        => ['{MM}#####'];
        yield 'day without month'         => ['{YY}{DD}#####'];
        yield 'too many digits'           => ['{YYYY}{MM}#####'];
        yield 'counter too long'          => ['{YY}##########'];
    }

    public function testValueRangeGuardKeepsTheNumberInsideTheIdColumn(): void
    {
        // {YYYY}{MM} + 5 digits would need 11 digits; the id column holds 10.
        $errors = NumberPattern::validate('{YYYY}{MM}#####');
        self::assertCount(1, $errors);
        self::assertStringContainsString((string) NumberPattern::MAX_ID, $errors[0]);
    }

    public function testTokenizeThrowsOnUnknownPlaceholder(): void
    {
        $this->expectException(InvalidPatternException::class);
        NumberPattern::tokenize('{NOPE}###');
    }

    public function testPresetsAndPlaceholderHelpAreConsistentWithTheValidator(): void
    {
        foreach (array_keys(NumberPattern::presets()) as $preset) {
            self::assertSame([], NumberPattern::validate($preset), $preset);
        }

        self::assertNotSame([], NumberPattern::placeholderHelp());
    }
}
