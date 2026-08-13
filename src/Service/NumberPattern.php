<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Service;

/**
 * Parser, validator and renderer for the configurable ticket-number pattern.
 *
 * A pattern describes how the numeric primary key of a new ticket is composed:
 *
 *     [literal digits][date placeholders][counter]
 *
 * e.g. `{YY}{MM}#####` → 260800001 (August 2026, first ticket of the month).
 *
 * The pattern is split into a *prefix* (everything before the counter) and the
 * *counter* (the `#` run). The prefix is what defines the block a ticket id
 * lands in; the counter digits define how many tickets fit into one block. Every
 * part must render to digits only, because `glpi_tickets.id` is an unsigned
 * integer — a ticket number with letters is not representable.
 *
 * The prefix must grow monotonically over time, because the block is realised by
 * raising the table's AUTO_INCREMENT counter, which MySQL never lowers. The
 * validation rules below exist to guarantee that:
 *
 *  - literal digits only at the very start (they are constant);
 *  - date placeholders in strictly decreasing significance (year → month →day);
 *  - each placeholder at most once;
 *  - `{DD}` only directly after `{MM}` (a day number restarts every month, so it
 *    is only monotonic while a month component precedes it);
 *  - any date placeholder requires a year placeholder (a month/week/day number
 *    restarts every year);
 *  - exactly one counter run, at the end;
 *  - the largest number the pattern can ever produce must fit into the column.
 *
 * This class is pure (no database, no GLPI runtime) so it can be unit-tested.
 */
final class NumberPattern
{
    /** Largest value an item id can hold (MySQL `int unsigned`). */
    public const MAX_ID = 4294967295;

    /** Digits of {@see MAX_ID}: a longer pattern can never fit. */
    public const MAX_DIGITS = 10;

    /** Bounds for the `#` counter run. */
    public const MIN_COUNTER_DIGITS = 1;
    public const MAX_COUNTER_DIGITS = 9;

    /**
     * Horizon for {@see validUntil()}. The two-digit year wraps in 2100, so the
     * arithmetic is only meaningful within this century anyway.
     */
    private const HORIZON_YEAR = 2099;

    /**
     * Supported date placeholders.
     *
     * rank = significance (higher is more significant), len = rendered digits,
     * max  = largest value the placeholder can render.
     *
     * @var array<string,array{rank:int,len:int,max:int}>
     */
    private const PLACEHOLDERS = [
        'YYYY' => ['rank' => 4, 'len' => 4, 'max' => 9999],
        'YY'   => ['rank' => 4, 'len' => 2, 'max' => 99],
        'Q'    => ['rank' => 3, 'len' => 1, 'max' => 4],
        'MM'   => ['rank' => 3, 'len' => 2, 'max' => 12],
        'WW'   => ['rank' => 3, 'len' => 2, 'max' => 53],
        'DD'   => ['rank' => 2, 'len' => 2, 'max' => 31],
    ];

    /** Placeholders that select the year. */
    private const YEAR_PLACEHOLDERS = ['YYYY', 'YY'];

    /**
     * Normalise user input: placeholders are case-insensitive and surrounding or
     * inner whitespace is irrelevant.
     */
    public static function normalize(string $pattern): string
    {
        return strtoupper(preg_replace('/\s+/', '', $pattern) ?? '');
    }

    /**
     * Split a normalised pattern into its tokens.
     *
     * @return list<array{type:string,value?:string,name?:string,digits?:int}>
     *         `literal` (value), `placeholder` (name) or `counter` (digits)
     * @throws InvalidPatternException on a syntactically invalid pattern
     */
    public static function tokenize(string $pattern): array
    {
        $pattern = self::normalize($pattern);
        if ($pattern === '') {
            throw new InvalidPatternException(
                __('The pattern must not be empty.', 'ticketnumbering')
            );
        }

        $tokens = [];
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '{') {
                $end = strpos($pattern, '}', $i);
                if ($end === false) {
                    throw new InvalidPatternException(
                        __('Unclosed placeholder: a "{" has no matching "}".', 'ticketnumbering')
                    );
                }

                $name = substr($pattern, $i + 1, $end - $i - 1);
                if (!isset(self::PLACEHOLDERS[$name])) {
                    throw new InvalidPatternException(sprintf(
                        __('Unknown placeholder "{%s}". Supported: %s.', 'ticketnumbering'),
                        $name,
                        '{' . implode('}, {', array_keys(self::PLACEHOLDERS)) . '}'
                    ));
                }

                $tokens[] = ['type' => 'placeholder', 'name' => $name];
                $i = $end;
                continue;
            }

            if ($char === '}') {
                throw new InvalidPatternException(
                    __('Unexpected "}" without a matching "{".', 'ticketnumbering')
                );
            }

            if ($char === '#') {
                $digits = 0;
                while ($i < $length && $pattern[$i] === '#') {
                    $digits++;
                    $i++;
                }
                $i--;
                $tokens[] = ['type' => 'counter', 'digits' => $digits];
                continue;
            }

            if ($char >= '0' && $char <= '9') {
                $value = '';
                while ($i < $length && $pattern[$i] >= '0' && $pattern[$i] <= '9') {
                    $value .= $pattern[$i];
                    $i++;
                }
                $i--;
                $tokens[] = ['type' => 'literal', 'value' => $value];
                continue;
            }

            throw new InvalidPatternException(sprintf(
                __('Unsupported character "%s" in the pattern.', 'ticketnumbering'),
                $char
            ));
        }

        return $tokens;
    }

    /**
     * Validate a pattern.
     *
     * @return list<string> human-readable errors; empty when the pattern is usable
     */
    public static function validate(string $pattern): array
    {
        try {
            $tokens = self::tokenize($pattern);
        } catch (InvalidPatternException $exception) {
            return [$exception->getMessage()];
        }

        $errors = [];

        $counters = array_values(array_filter($tokens, static fn (array $t): bool => $t['type'] === 'counter'));
        if ($counters === []) {
            $errors[] = __('The pattern must contain a counter, written as one or more "#".', 'ticketnumbering');
        } elseif (count($counters) > 1) {
            $errors[] = __('The pattern must contain exactly one "#" counter block.', 'ticketnumbering');
        } else {
            $digits = (int) $counters[0]['digits'];
            if ($digits < self::MIN_COUNTER_DIGITS || $digits > self::MAX_COUNTER_DIGITS) {
                $errors[] = sprintf(
                    __('The counter must be between %1$d and %2$d "#" long.', 'ticketnumbering'),
                    self::MIN_COUNTER_DIGITS,
                    self::MAX_COUNTER_DIGITS
                );
            }
            if (end($tokens)['type'] !== 'counter') {
                $errors[] = __('The "#" counter must be at the end of the pattern.', 'ticketnumbering');
            }
        }

        $seen = [];
        $previousRank = PHP_INT_MAX;
        $previousPlaceholder = null;
        $placeholderSeen = false;

        foreach ($tokens as $token) {
            if ($token['type'] === 'literal') {
                if ($placeholderSeen) {
                    $errors[] = __('Fixed digits are only allowed at the beginning of the pattern.', 'ticketnumbering');
                }
                continue;
            }

            if ($token['type'] === 'counter') {
                continue;
            }

            $name = (string) $token['name'];
            $placeholderSeen = true;

            if (isset($seen[$name])) {
                $errors[] = sprintf(
                    __('Placeholder "{%s}" is used more than once.', 'ticketnumbering'),
                    $name
                );
                continue;
            }
            $seen[$name] = true;

            $rank = self::PLACEHOLDERS[$name]['rank'];
            if ($rank >= $previousRank) {
                $errors[] = __(
                    'Date placeholders must be ordered from the largest to the smallest unit, e.g. {YY}{MM}{DD}.',
                    'ticketnumbering'
                );
            }

            if ($name === 'DD' && $previousPlaceholder !== 'MM') {
                $errors[] = __('"{DD}" may only be used directly after "{MM}".', 'ticketnumbering');
            }

            $previousRank = $rank;
            $previousPlaceholder = $name;
        }

        if ($seen !== [] && array_intersect(self::YEAR_PLACEHOLDERS, array_keys($seen)) === []) {
            $errors[] = __(
                'A pattern using a date placeholder must start with "{YY}" or "{YYYY}", otherwise the numbers would repeat every year.',
                'ticketnumbering'
            );
        }

        if ($errors === []) {
            // An id is at most MAX_DIGITS long, so a longer pattern can never fit.
            $length = self::renderedLength($tokens);
            if ($length > self::MAX_DIGITS) {
                $errors[] = sprintf(
                    __('The pattern produces %1$d digits, but an id holds at most %2$d. Use fewer digits.', 'ticketnumbering'),
                    $length,
                    self::MAX_DIGITS
                );
            } elseif (self::blockRange($pattern)[1] > self::MAX_ID) {
                // A pattern of legal length can still start above the column
                // maximum, e.g. because of a large fixed prefix.
                $errors[] = sprintf(
                    __('The pattern already produces numbers above the largest possible id (%s). Use fewer digits or a smaller fixed prefix.', 'ticketnumbering'),
                    (string) self::MAX_ID
                );
            }
        }

        return array_values(array_unique($errors));
    }

    public static function isValid(string $pattern): bool
    {
        return self::validate($pattern) === [];
    }

    /** Number of digits reserved for the running counter. */
    public static function counterDigits(string $pattern): int
    {
        foreach (self::tokenize($pattern) as $token) {
            if ($token['type'] === 'counter') {
                return (int) $token['digits'];
            }
        }

        return 0;
    }

    /** Number of tickets that fit into one block. */
    public static function blockSize(string $pattern): int
    {
        $digits = self::counterDigits($pattern);

        return $digits > 0 ? 10 ** $digits : 0;
    }

    /**
     * Render the prefix (everything left of the counter) for a point in time.
     * Returns an empty string for a pattern without any prefix — such a pattern
     * is a plain, never-resetting sequence and needs no block handling.
     */
    public static function prefixForDate(string $pattern, ?int $timestamp = null): string
    {
        $tokens = self::tokenize($pattern);
        $timestamp ??= time();

        // ISO week numbers belong to the ISO-8601 year, which may differ from the
        // calendar year in the last/first days of a year. Pairing {WW} with the
        // calendar year would make the prefix jump backwards there, so the year is
        // rendered as the ISO year whenever {WW} is part of the pattern.
        $usesIsoWeek = false;
        foreach ($tokens as $token) {
            if ($token['type'] === 'placeholder' && $token['name'] === 'WW') {
                $usesIsoWeek = true;
                break;
            }
        }
        $yearFormat = $usesIsoWeek ? 'o' : 'Y';

        $prefix = '';
        foreach ($tokens as $token) {
            switch ($token['type']) {
                case 'literal':
                    $prefix .= (string) $token['value'];
                    break;

                case 'placeholder':
                    $prefix .= match ((string) $token['name']) {
                        'YYYY' => date($yearFormat, $timestamp),
                        'YY'   => substr(date($yearFormat, $timestamp), -2),
                        'Q'    => (string) (intdiv((int) date('n', $timestamp) - 1, 3) + 1),
                        'MM'   => date('m', $timestamp),
                        'WW'   => date('W', $timestamp),
                        'DD'   => date('d', $timestamp),
                        default => '',
                    };
                    break;

                case 'counter':
                    break 2;
            }
        }

        return $prefix;
    }

    /** Numeric base of a block, e.g. prefix "2608" with 5 counter digits → 260800000. */
    public static function blockBase(string $pattern, ?int $timestamp = null): int
    {
        $prefix = self::prefixForDate($pattern, $timestamp);
        if ($prefix === '') {
            return 0;
        }

        $size = self::blockSize($pattern);
        if ($size <= 0) {
            return 0;
        }

        return (int) $prefix * $size;
    }

    /**
     * First and last valid id of the block a ticket created at $timestamp belongs
     * to. `[0, 0]` means "no block" — the pattern is a plain sequence and the
     * AUTO_INCREMENT counter must be left alone.
     *
     * @return array{0:int,1:int} [firstId, lastId]
     */
    public static function blockRange(string $pattern, ?int $timestamp = null): array
    {
        $base = self::blockBase($pattern, $timestamp);
        if ($base <= 0) {
            return [0, 0];
        }

        return [$base + 1, $base + self::blockSize($pattern) - 1];
    }

    /** The first ticket number the pattern produces for $timestamp, as a string. */
    public static function example(string $pattern, ?int $timestamp = null): string
    {
        $prefix = self::prefixForDate($pattern, $timestamp);
        $digits = self::counterDigits($pattern);

        return $prefix . str_pad('1', $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Number of digits the pattern renders. Every part has a fixed width, so this
     * is the same for every point in time.
     *
     * @param list<array{type:string,value?:string,name?:string,digits?:int}> $tokens
     */
    private static function renderedLength(array $tokens): int
    {
        $length = 0;
        foreach ($tokens as $token) {
            $length += match ($token['type']) {
                'literal'     => strlen((string) $token['value']),
                'placeholder' => self::PLACEHOLDERS[(string) $token['name']]['len'],
                'counter'     => (int) $token['digits'],
                default       => 0,
            };
        }

        return $length;
    }

    /** Does the whole block of $timestamp still fit into the id column? */
    private static function fitsAt(string $pattern, int $timestamp): bool
    {
        return self::blockRange($pattern, $timestamp)[1] <= self::MAX_ID;
    }

    /**
     * Last day on which the pattern still fits into the id column, or null when
     * it fits for the whole supported horizon (or has no date component at all).
     *
     * A ten-digit pattern such as `{YY}{MM}{DD}####` is perfectly usable today but
     * outgrows `int unsigned` at some point in the future; the configuration page
     * shows that date instead of rejecting the pattern outright.
     *
     * @return int|null timestamp inside the last valid day
     */
    public static function validUntil(string $pattern, ?int $from = null): ?int
    {
        if (!self::isValid($pattern)) {
            return null;
        }

        $from ??= time();
        if (self::blockRange($pattern, $from)[0] <= 0) {
            // Plain counter: no date component, so nothing can grow out of range.
            return null;
        }

        $horizon = (int) mktime(12, 0, 0, 12, 31, self::HORIZON_YEAR);
        if ($horizon <= $from || self::fitsAt($pattern, $horizon)) {
            return null;
        }

        // The prefix never decreases over time, so "fits" is true up to a single
        // switch-over day: binary search for it.
        $fitting = $from;
        $failing = $horizon;
        while ($failing - $fitting > 86400) {
            $middle = $fitting + intdiv($failing - $fitting, 2);
            if (self::fitsAt($pattern, $middle)) {
                $fitting = $middle;
            } else {
                $failing = $middle;
            }
        }

        return $fitting;
    }

    /**
     * The supported placeholders with a translated description, for the config UI.
     *
     * @return array<string,string> placeholder → description
     */
    public static function placeholderHelp(): array
    {
        return [
            '{YYYY}' => __('Four-digit year (2026)', 'ticketnumbering'),
            '{YY}'   => __('Two-digit year (26)', 'ticketnumbering'),
            '{Q}'    => __('Quarter (1-4)', 'ticketnumbering'),
            '{MM}'   => __('Two-digit month (08)', 'ticketnumbering'),
            '{WW}'   => __('Two-digit ISO calendar week (32)', 'ticketnumbering'),
            '{DD}'   => __('Two-digit day of month (12)', 'ticketnumbering'),
            '#'      => __('One digit of the running counter', 'ticketnumbering'),
        ];
    }

    /**
     * Ready-made patterns offered on the config page.
     *
     * @return array<string,string> pattern → translated description
     */
    public static function presets(): array
    {
        return [
            '{YY}{MM}#####'    => __('Year + month, 99999 items per month (default)', 'ticketnumbering'),
            '{YYYY}#####'      => __('Four-digit year, 99999 items per year', 'ticketnumbering'),
            '{YY}{Q}#####'     => __('Year + quarter', 'ticketnumbering'),
            '{YY}{MM}{DD}###'  => __('Year + month + day, 999 items per day', 'ticketnumbering'),
            '{YY}{MM}{DD}####' => __('Year + month + day, 9999 items per day (10 digits)', 'ticketnumbering'),
            '#########'        => __('Plain continuous counter (GLPI default behaviour)', 'ticketnumbering'),
        ];
    }
}
