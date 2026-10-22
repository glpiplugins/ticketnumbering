# Changelog

All notable changes to the Ticket Numbering plugin. Format based on
[Keep a Changelog](https://keepachangelog.com/); versions follow the plugin version in `setup.php`.

## [2.0.0] – 2026-10-22

First release for GLPI 12.0.x only (`12.0.0` – `12.0.99`). GLPI 11 stays on 1.2.0 (branch `glpi11`, tag
`v1.2.0`). The settings are unchanged, so an upgrade keeps the configuration.

### Changed
- Port to GLPI 12: the configuration form has no CSRF token field and the plugin no `csrf_compliant` hook any
  more (GLPI 12 checks the browser's `Sec-Fetch-Site`/`Origin` headers instead), and `config.js` is loaded without
  the deprecated `/public` URL prefix.
- Items created inside a database transaction — in GLPI 12 every ticket, problem or change created from a form
  answer — no longer fail as the first item of a new number block. GLPI 12 refuses the
  `ALTER TABLE … AUTO_INCREMENT` that opens a block while a transaction is open; the first item of the block then
  gets the block start as explicit id, and InnoDB moves the counter behind it. Outside transactions the counter is
  raised as before.
- Activating and deactivating the plugin reset GLPI's caches, so the configuration page is never answered with
  404 from a route cache that was built while the plugin was inactive.

### Known limitations
- If two form submissions create the first item of a new block at the same moment, one of them can fail on the
  duplicate id and has to be submitted again. Items created outside a transaction (user interface, mail
  collector) are not affected.

## [1.2.0] – 2026-08-13

### Added
- Problems and changes can be numbered too, each with its own switch, pattern and counter. Both are off by
  default, so an upgrade does not renumber anything that was untouched before.
- The configuration page shows one card per item type with its own preview, templates and block state.

### Changed
- Settings are stored per item type (`<itemtype>_enabled`, `<itemtype>_pattern`); a 1.1.0 configuration
  (`enabled`, `pattern`) is moved to the ticket keys on install.
- Ten-digit patterns such as `{YY}{MM}{DD}####` are accepted as long as they fit into the id column today; the
  page shows the date on which a pattern outgrows it.

## [1.1.0] – 2026-08-12

### Added
- Freely configurable number pattern: fixed digits, the placeholders `{YYYY}`, `{YY}`, `{Q}`, `{MM}`, `{WW}`,
  `{DD}` and a `#` counter. The default `{YY}{MM}#####` keeps the previous fixed `YYMM#####` numbering.
- Configuration page under *Setup > Plugins* with live preview, example patterns and the current block state,
  guarded by the core `config` right.
- English README.
