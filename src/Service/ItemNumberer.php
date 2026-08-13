<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Service;

use CommonDBTM;
use GlpiPlugin\Ticketnumbering\Config\Settings;
use Toolbox;

/**
 * Parks the AUTO_INCREMENT counter of a supported table in the block described by
 * the itemtype's pattern, so a new item gets a date-encoded id such as 260800001.
 *
 * Tickets, problems and changes have their own table and therefore their own
 * counter; they are configured and handled completely independently.
 *
 * Imported items carry an explicit id and are left untouched, which keeps the
 * counter below the date range and prevents it from being dragged upwards.
 *
 * All pattern arithmetic lives in {@see NumberPattern}; this class only holds the
 * database side effects (raising the counter, reading the current state).
 */
final class ItemNumberer
{
    /**
     * Ensure the next organically created item falls into the current block.
     *
     * @param array<string,mixed> $input the item input about to be inserted
     */
    public static function ensureBlockForNewItem(string $itemtype, array $input): void
    {
        global $DB;

        // Forced-id path (Zammad import etc.): never touch the counter.
        if (isset($input['id']) && (int) $input['id'] > 0) {
            return;
        }

        if (!Settings::isSupported($itemtype) || !Settings::isEnabled($itemtype)) {
            return;
        }

        $table = self::tableFor($itemtype);
        if ($table === null) {
            return;
        }

        [$first, $last] = NumberPattern::blockRange(Settings::pattern($itemtype));
        if ($first <= 0) {
            // Plain counter pattern — GLPI's own numbering is what was asked for.
            return;
        }

        if ($last > NumberPattern::MAX_ID) {
            // The pattern has outgrown the id column (a ten-digit pattern does so
            // eventually). Leave the counter alone rather than write an id the
            // column cannot store.
            Toolbox::logInFile(
                'ticketnumbering',
                sprintf('%s number block %d..%d exceeds the maximum id %d.', $itemtype, $first, $last, NumberPattern::MAX_ID) . "\n"
            );

            return;
        }

        $maxInBlock = self::maxIdInRange($table, $first, $last);

        if ($maxInBlock >= $last) {
            // Block exhausted: the next id would spill into the following block.
            // Surface it, but let GLPI proceed.
            Toolbox::logInFile(
                'ticketnumbering',
                sprintf('%s number block %d..%d is exhausted.', $itemtype, $first, $last) . "\n"
            );

            return;
        }

        if ($maxInBlock >= $first) {
            // Block already open — the counter is positioned correctly.
            return;
        }

        // No item in this block yet: park the counter at the block start. This only
        // ever raises it (the prefix grows monotonically over time), so it is safe;
        // MySQL clamps to max(id)+1 if that were already higher.
        $DB->doQuery(
            sprintf('ALTER TABLE `%s` AUTO_INCREMENT = %d', $table, $first)
        );
    }

    /**
     * Current state of the numbering of one itemtype, for the configuration page.
     *
     * @return array{
     *     itemtype:string,
     *     label:string,
     *     enabled:bool,
     *     pattern:string,
     *     errors:list<string>,
     *     prefix:string,
     *     counter_digits:int,
     *     first:int,
     *     last:int,
     *     block_size:int,
     *     max_in_block:int,
     *     used_in_block:int,
     *     next:int,
     *     global_max:int,
     *     exhausted:bool,
     *     blocked_by_higher_id:bool,
     *     out_of_range:bool
     * }
     */
    public static function status(string $itemtype, ?int $timestamp = null): array
    {
        $pattern = Settings::storedPattern($itemtype);
        $errors = NumberPattern::validate($pattern);
        $table = self::tableFor($itemtype);

        $status = [
            'itemtype'             => $itemtype,
            'label'                => self::labelFor($itemtype),
            'enabled'              => Settings::isEnabled($itemtype),
            'pattern'              => $pattern,
            'errors'               => $errors,
            'prefix'               => '',
            'counter_digits'       => 0,
            'first'                => 0,
            'last'                 => 0,
            'block_size'           => 0,
            'max_in_block'         => 0,
            'used_in_block'        => 0,
            'next'                 => 0,
            'global_max'           => $table === null ? 0 : self::maxId($table),
            'exhausted'            => false,
            'blocked_by_higher_id' => false,
            'out_of_range'         => false,
        ];

        if ($errors !== [] || $table === null) {
            return $status;
        }

        $status['prefix'] = NumberPattern::prefixForDate($pattern, $timestamp);
        $status['counter_digits'] = NumberPattern::counterDigits($pattern);
        $status['block_size'] = NumberPattern::blockSize($pattern);

        [$first, $last] = NumberPattern::blockRange($pattern, $timestamp);
        $status['first'] = $first;
        $status['last'] = $last;

        if ($first <= 0) {
            // Plain counter: GLPI keeps its own sequence.
            $status['next'] = $status['global_max'] + 1;

            return $status;
        }

        if ($last > NumberPattern::MAX_ID) {
            $status['out_of_range'] = true;
            $status['next'] = $status['global_max'] + 1;

            return $status;
        }

        $maxInBlock = self::maxIdInRange($table, $first, $last);
        $status['max_in_block'] = $maxInBlock;
        $status['used_in_block'] = $maxInBlock >= $first ? $maxInBlock - $first + 1 : 0;
        $status['exhausted'] = $maxInBlock >= $last;

        if ($maxInBlock >= $first) {
            $status['next'] = $maxInBlock + 1;
        } else {
            // The block has not been opened yet. AUTO_INCREMENT can only be raised,
            // so an already higher id elsewhere in the table wins over the pattern.
            $status['blocked_by_higher_id'] = $status['global_max'] >= $first;
            $status['next'] = max($first, $status['global_max'] + 1);
        }

        return $status;
    }

    /** Translated itemtype name, taken from GLPI core. */
    public static function labelFor(string $itemtype): string
    {
        if (!Settings::isSupported($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return $itemtype;
        }

        return $itemtype::getTypeName(2);
    }

    /** Table of a supported itemtype, or null when the class is not available. */
    private static function tableFor(string $itemtype): ?string
    {
        if (!Settings::isSupported($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            return null;
        }

        $table = $itemtype::getTable();

        return $table !== '' ? $table : null;
    }

    private static function maxIdInRange(string $table, int $first, int $last): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'id AS maxid'],
            'FROM'   => $table,
            'WHERE'  => ['AND' => [['id' => ['>=', $first]], ['id' => ['<=', $last]]]],
        ])->current();

        return (int) ($row['maxid'] ?? 0);
    }

    private static function maxId(string $table): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'id AS maxid'],
            'FROM'   => $table,
        ])->current();

        return (int) ($row['maxid'] ?? 0);
    }
}
