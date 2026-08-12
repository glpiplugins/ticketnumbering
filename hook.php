<?php

declare(strict_types=1);

use GlpiPlugin\Ticketnumbering\Service\TicketNumberer;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * pre_item_add hook for Ticket: ensure a new organic ticket lands in the
 * current YYMM##### id block. The Ticket object is passed by GLPI's item-hook
 * dispatcher (Plugin::doHook).
 */
function plugin_ticketnumbering_pre_item_add(CommonDBTM $item): void
{
    plugin_ticketnumbering_register_autoloader();

    $input = is_array($item->input) ? $item->input : [];
    TicketNumberer::ensureBlockForNewTicket($input);
}
