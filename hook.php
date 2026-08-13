<?php

declare(strict_types=1);

use GlpiPlugin\Ticketnumbering\Service\ItemNumberer;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * pre_item_add hook for every supported itemtype (Ticket, Problem, Change):
 * ensure a new organic item lands in the block of its own configured pattern.
 * The item is passed by GLPI's item-hook dispatcher (Plugin::doHook), and each
 * itemtype is looked up separately in the configuration.
 */
function plugin_ticketnumbering_pre_item_add(CommonDBTM $item): void
{
    plugin_ticketnumbering_register_autoloader();

    $input = is_array($item->input) ? $item->input : [];
    ItemNumberer::ensureBlockForNewItem($item->getType(), $input);
}
