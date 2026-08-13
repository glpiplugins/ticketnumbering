# Ticket Numbering (`ticketnumbering`)

*English · [Deutsch](README.md)*

GLPI 11 plugin that makes the **number of new tickets, problems and changes
freely configurable**. Instead of GLPI's continuous sequence, new items get a
number following a pattern you define yourself, e.g. `260800001` for the first
ticket in August 2026.

**Every item type is configured separately** — its own switch, its own pattern,
its own counter.

- **GLPI version:** 11.0.0 – 11.99.99
- **License:** GPL-3.0-or-later
- **Author:** Roy Brannath
- **Repository:** https://github.com/glpiplugins/ticketnumbering

## Supported item types

| Item type | Table            | Default                    |
| --------- | ---------------- | -------------------------- |
| Ticket    | `glpi_tickets`   | **on**, `{YY}{MM}#####`    |
| Problem   | `glpi_problems`  | off                        |
| Change    | `glpi_changes`   | off                        |

Problems and changes are deliberately off until someone turns them on, so an
update never renumbers something that was untouched before. The three tables
have separate counters, so the same pattern can safely be used for all of them.

## How it works

In GLPI the number **is** the primary key (`glpi_tickets.id`,
`glpi_problems.id`, `glpi_changes.id`) — there is no separate number field. The
plugin does not rewrite that id afterwards; instead it sets the
`AUTO_INCREMENT` counter of the respective table to the start of the matching
number block before an item is inserted (`pre_item_add` hook).

A pattern consists of three parts:

```
[fixed digits][date placeholders][counter "#"]
```

Everything to the left of the counter forms the **prefix**; it determines the
block. The counter runs up inside the block and restarts automatically as soon as
the prefix changes — with `{YY}{MM}` that means at every turn of the month.

| Pattern            | First number (August 2026) | Reset     |
| ------------------ | -------------------------- | --------- |
| `{YY}{MM}#####`    | `260800001`                | monthly   |
| `{YYYY}#####`      | `202600001`                | yearly    |
| `{YY}{Q}#####`     | `26300001`                 | quarterly |
| `{YY}{MM}{DD}###`  | `260812001`                | daily     |
| `{YY}{MM}{DD}####` | `2608120001`               | daily     |
| `1#######`         | `10000001`                 | never     |
| `#########`        | GLPI default sequence      | never     |

### Placeholders

| Placeholder | Meaning                             |
| ----------- | ----------------------------------- |
| `{YYYY}`    | four-digit year (2026)              |
| `{YY}`      | two-digit year (26)                 |
| `{Q}`       | quarter (1–4)                       |
| `{MM}`      | two-digit month (08)                |
| `{WW}`      | two-digit ISO calendar week (32)    |
| `{DD}`      | two-digit day of month (12)         |
| `#`         | one digit of the running counter    |

### Rules (enforced when saving)

Because the number is an unsigned integer id and an `AUTO_INCREMENT` value can
**only grow**, the prefix must increase monotonically over time. The plugin
therefore only accepts patterns that guarantee this:

- digits, `#` and the known placeholders only — no letters, no separators such as
  `-` or `/`;
- fixed digits only at the beginning;
- date placeholders from the largest to the smallest unit, each at most once;
- `{DD}` only directly after `{MM}`;
- as soon as a date placeholder is used, `{YY}` or `{YYYY}` must come first;
- exactly one `#` block, at the end (1–9 digits);
- at most **10 digits** in total, and the pattern must not already exceed the
  largest possible id (4294967295) today.

When `{WW}` is used, the plugin renders the year as the **ISO year** (`o`) so the
prefix cannot jump backwards at the turn of the year (2027-01-01 falls into
week 53 of 2026).

### Ten-digit patterns and their expiry date

`int unsigned` holds at most 4294967295 — ten digits, but not every ten-digit
number. A pattern such as `{YY}{MM}{DD}####` therefore works perfectly well but
eventually grows out of range (here on 2043-01-01). Such patterns are **not
rejected**; the configuration page shows the date up to which the pattern fits
instead. From that day on GLPI continues with its own numbering and the plugin
logs it to `files/_log/ticketnumbering.log`. Nine-digit patterns have no such
limit.

## Configuration

**Setup → Plugins → Ticket Numbering → Configure**
(`/plugins/ticketnumbering/config`)

The page shows one card per item type with:

- a switch for whether the plugin numbering applies to that type;
- the pattern with a live preview of the next number;
- clickable template patterns;
- the current state: active pattern, next number, current block, usage inside the
  block and the highest existing id;
- warnings when the block is full, when the pattern has an expiry date, or when
  an already higher id prevents the pattern from taking effect.

Saving is **all or nothing**: if one pattern is invalid, the whole configuration
stays unchanged and the affected card shows the error.

Permission: the core **Setup → Configuration** right (`config`) — `READ` to view,
`UPDATE` to save.

The settings live in `glpi_configs` under the context `plugin:ticketnumbering`,
one pair per item type: `<itemtype>_enabled` and `<itemtype>_pattern` (e.g.
`ticket_pattern`, `problem_enabled`). The plugin creates no tables of its own. A
configuration from version 1.1.0 (keys `enabled`/`pattern`) is migrated to
`ticket_enabled`/`ticket_pattern` automatically on install.

## Important notes

- **Imports are left untouched.** Items created with an explicit `id` (e.g. by
  `zammadsync`) are not renumbered and do not move the counter.
- **A pattern cannot take effect backwards.** If an item with an id higher than
  the block start already exists, MySQL cannot lower the counter; the
  configuration page points this out.
- **Full block.** Once every number of a block is used, GLPI assigns the next
  free id outside the pattern. The plugin logs this to
  `files/_log/ticketnumbering.log`; more `#` digits prevent it.
- Numbers that have already been assigned are **not** changed by editing the
  pattern.

## Installation

```bash
docker exec -u www-data glpi-glpi-1 php bin/console plugin:install ticketnumbering
docker exec -u www-data glpi-glpi-1 php bin/console plugin:activate ticketnumbering
```

Installation is idempotent and does not overwrite an existing configuration.
Uninstalling removes only the plugin's own `glpi_configs` rows; tickets, problems
and changes stay unchanged.

## Development

```bash
# Unit tests (pure pattern logic, no database)
docker exec -u www-data glpi-glpi-1 sh -lc \
  'cd /var/www/glpi/plugins/ticketnumbering && php ../glpi-core/vendor/bin/phpunit -c phpunit.xml.dist'

# Translations
docker exec glpi-glpi-1 sh -lc \
  'cd /var/www/glpi/plugins/ticketnumbering/locales && msgfmt -o de_DE.mo de_DE.po && msgfmt -o en_GB.mo en_GB.po'
```

## Layout

```
setup.php                              lifecycle, hooks, config_page
hook.php                               pre_item_add for Ticket, Problem, Change
src/Config/Settings.php                per-item-type configuration in glpi_configs
src/Controller/ConfigController.php    GLPI 11 controller /plugins/ticketnumbering/config
src/Service/NumberPattern.php          pattern: parser, validation, rendering (database-free)
src/Service/ItemNumberer.php           setting AUTO_INCREMENT, status per item type
templates/pages/config.html.twig       configuration page
public/js/config.js                    live preview, template buttons
tests/Unit/NumberPatternTest.php       unit tests of the pattern logic
tests/Unit/SettingsKeysTest.php        unit tests of the item type keys
locales/                               de_DE, en_GB
```
