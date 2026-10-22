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
 *
 * When the block cannot be opened by moving the table counter (inside a
 * transaction), the first item of the block gets its start as explicit id.
 */
function plugin_ticketnumbering_pre_item_add(CommonDBTM $item): void
{
    plugin_ticketnumbering_register_autoloader();

    if (!is_array($item->input)) {
        return;
    }

    $id = ItemNumberer::ensureBlockForNewItem($item->getType(), $item->input);
    if ($id !== null) {
        $item->input['id'] = $id;
    }
}
