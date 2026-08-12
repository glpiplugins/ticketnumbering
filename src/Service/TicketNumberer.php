<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Service;

use GlpiPlugin\Ticketnumbering\Config\Settings;
use Ticket;
use Toolbox;

/**
 * Parks the `glpi_tickets` AUTO_INCREMENT counter in the block described by the
 * configured pattern, so a new ticket gets a date-encoded id such as 260800001.
 *
 * Imported tickets carry an explicit id and are left untouched, which keeps the
 * counter below the date range and prevents it from being dragged upwards.
 *
 * All pattern arithmetic lives in {@see NumberPattern}; this class only holds the
 * database side effects (raising the counter, reading the current state).
 */
final class TicketNumberer
{
    /**
     * Ensure the next organic ticket falls into the current block.
     *
     * @param array<string,mixed> $input the Ticket input about to be inserted
     */
    public static function ensureBlockForNewTicket(array $input): void
    {
        global $DB;

        // Forced-id path (Zammad import etc.): never touch the counter.
        if (isset($input['id']) && (int) $input['id'] > 0) {
            return;
        }

        if (!Settings::isEnabled()) {
            return;
        }

        [$first, $last] = NumberPattern::blockRange(Settings::pattern());
        if ($first <= 0) {
            // Plain counter pattern — GLPI's own numbering is what was asked for.
            return;
        }

        $maxInBlock = self::maxIdInRange($first, $last);

        if ($maxInBlock >= $last) {
            // Block exhausted: the next id would spill into the following block.
            // Surface it, but let GLPI proceed.
            Toolbox::logInFile(
                'ticketnumbering',
                sprintf('Ticket number block %d..%d is exhausted.', $first, $last) . "\n"
            );

            return;
        }

        if ($maxInBlock >= $first) {
            // Block already open — the counter is positioned correctly.
            return;
        }

        // No ticket in this block yet: park the counter at the block start. This
        // only ever raises it (the prefix grows monotonically over time), so it is
        // safe; MySQL clamps to max(id)+1 if that were already higher.
        $DB->doQuery(
            sprintf('ALTER TABLE `%s` AUTO_INCREMENT = %d', Ticket::getTable(), $first)
        );
    }

    /**
     * Current state of the numbering, for the configuration page.
     *
     * @return array{
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
     *     blocked_by_higher_id:bool
     * }
     */
    public static function status(?int $timestamp = null): array
    {
        $pattern = Settings::storedPattern();
        $errors = NumberPattern::validate($pattern);

        $status = [
            'enabled'              => Settings::isEnabled(),
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
            'global_max'           => self::maxTicketId(),
            'exhausted'            => false,
            'blocked_by_higher_id' => false,
        ];

        if ($errors !== []) {
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

        $maxInBlock = self::maxIdInRange($first, $last);
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

    private static function maxIdInRange(int $first, int $last): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'id AS maxid'],
            'FROM'   => Ticket::getTable(),
            'WHERE'  => ['AND' => [['id' => ['>=', $first]], ['id' => ['<=', $last]]]],
        ])->current();

        return (int) ($row['maxid'] ?? 0);
    }

    private static function maxTicketId(): int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['MAX' => 'id AS maxid'],
            'FROM'   => Ticket::getTable(),
        ])->current();

        return (int) ($row['maxid'] ?? 0);
    }
}
