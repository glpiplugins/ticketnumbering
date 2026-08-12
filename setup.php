<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Ticketnumbering\Config\Settings;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/hook.php';

define('PLUGIN_TICKETNUMBERING_VERSION', '1.1.0');

function plugin_version_ticketnumbering(): array
{
    return [
        'name'         => __('Ticket Numbering', 'ticketnumbering'),
        'version'      => PLUGIN_TICKETNUMBERING_VERSION,
        'author'       => 'Roy Brannath',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/glpiplugins/ticketnumbering',
        'requirements' => [
            'glpi' => [
                'min' => '11.0.0',
                'max' => '11.99.99',
            ],
        ],
    ];
}

function plugin_init_ticketnumbering(): void
{
    global $PLUGIN_HOOKS;

    plugin_ticketnumbering_register_autoloader();

    $PLUGIN_HOOKS['csrf_compliant']['ticketnumbering'] = true;

    // Setup > Plugins > "Configure". Resolved to {root_doc}/plugins/ticketnumbering/config,
    // which is the route of GlpiPlugin\Ticketnumbering\Controller\ConfigController.
    $PLUGIN_HOOKS['config_page']['ticketnumbering'] = 'config';

    // Before a new ticket is inserted, park the AUTO_INCREMENT counter in the
    // block described by the configured pattern so organic tickets get a
    // date-encoded id. Imports that carry an explicit id are left untouched
    // (see the hook).
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['ticketnumbering'] = [
        'Ticket' => 'plugin_ticketnumbering_pre_item_add',
    ];
}

function plugin_ticketnumbering_check_prerequisites(): bool
{
    return true;
}

function plugin_ticketnumbering_check_config(): bool
{
    return true;
}

function plugin_ticketnumbering_install(): bool
{
    plugin_ticketnumbering_register_autoloader();

    // No schema of its own — the plugin manipulates the AUTO_INCREMENT counter of
    // glpi_tickets at runtime and stores its settings in glpi_configs. Seeding is
    // idempotent and keeps an existing configuration on reinstall.
    Settings::install();

    return true;
}

function plugin_ticketnumbering_uninstall(): bool
{
    plugin_ticketnumbering_register_autoloader();

    Settings::uninstall();

    return true;
}

function plugin_ticketnumbering_register_autoloader(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    spl_autoload_register(static function (string $class): void {
        $prefix = 'GlpiPlugin\\Ticketnumbering\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require_once $path;
        }
    });

    $registered = true;
}
