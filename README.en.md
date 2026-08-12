# Ticket Numbering (`ticketnumbering`)

*English · [Deutsch](README.md)*

GLPI 11 plugin that makes the **number of new tickets freely configurable**.
Instead of GLPI's continuous sequence, new tickets get a number following a
pattern you define yourself, e.g. `260800001` for the first ticket in
August 2026.

- **GLPI version:** 11.0.0 – 11.99.99
- **License:** GPL-3.0-or-later
- **Author:** Roy Brannath
- **Repository:** https://github.com/glpiplugins/ticketnumbering

## How it works

In GLPI the ticket number **is** the primary key `glpi_tickets.id` — there is no
separate number field. The plugin does not rewrite that id afterwards; instead it
sets the table's `AUTO_INCREMENT` counter to the start of the matching number
block before a ticket is inserted (`pre_item_add` hook).

A pattern consists of three parts:

```
[fixed digits][date placeholders][counter "#"]
```

Everything to the left of the counter forms the **prefix**; it determines the
block. The counter runs up inside the block and restarts automatically as soon as
the prefix changes — with `{YY}{MM}` that means at every turn of the month.

| Pattern           | First number (August 2026) | Reset     |
| ----------------- | -------------------------- | --------- |
| `{YY}{MM}#####`   | `260800001`                | monthly   |
| `{YYYY}#####`     | `202600001`                | yearly    |
| `{YY}{Q}#####`    | `26300001`                 | quarterly |
| `{YY}{MM}{DD}###` | `260812001`                | daily     |
| `1#######`        | `10000001`                 | never     |
| `#########`       | GLPI default sequence      | never     |

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
- the largest possible number must fit into `int unsigned` (≤ 4294967295), so
  e.g. `{YYYY}{MM}#####` (11 digits) is rejected.

When `{WW}` is used, the plugin renders the year as the **ISO year** (`o`) so the
prefix cannot jump backwards at the turn of the year (2027-01-01 falls into
week 53 of 2026).

## Configuration

**Setup → Plugins → Ticket Numbering → Configure**
(`/plugins/ticketnumbering/config`)

The page offers:

- a switch for whether the plugin numbering is applied at all;
- the pattern with a live preview of the next number;
- clickable example patterns;
- the current state: active pattern, next ticket number, current block, usage
  inside the block and the highest existing ticket id;
- warnings when the block is full or when an already higher ticket id prevents
  the pattern from taking effect.

Permission: the core **Setup → Configuration** right (`config`) — `READ` to view,
`UPDATE` to save.

The settings live in `glpi_configs` under the context `plugin:ticketnumbering`
(keys `enabled`, `pattern`). The plugin creates no tables of its own.

## Important notes

- **Imports are left untouched.** Tickets created with an explicit `id` (e.g. by
  `zammadsync`) are not renumbered and do not move the counter.
- **A pattern cannot take effect backwards.** If a ticket with an id higher than
  the block start already exists, MySQL cannot lower the counter; the
  configuration page points this out.
- **Full block.** Once every number of a block is used, GLPI assigns the next
  free id outside the pattern. The plugin logs this to
  `files/_log/ticketnumbering.log`; more `#` digits prevent it.
- Ticket numbers that have already been assigned are **not** changed by editing
  the pattern.

## Installation

```bash
docker exec -u www-data glpi-glpi-1 php bin/console plugin:install ticketnumbering
docker exec -u www-data glpi-glpi-1 php bin/console plugin:activate ticketnumbering
```

Installation is idempotent and does not overwrite an existing configuration.
Uninstalling removes only the plugin's own `glpi_configs` rows; tickets stay
unchanged.

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
hook.php                               pre_item_add for Ticket
src/Config/Settings.php                configuration in glpi_configs
src/Controller/ConfigController.php    GLPI 11 controller /plugins/ticketnumbering/config
src/Service/NumberPattern.php          pattern: parser, validation, rendering (database-free)
src/Service/TicketNumberer.php         setting AUTO_INCREMENT, status readout
templates/pages/config.html.twig       configuration page
public/js/config.js                    live preview, example buttons
tests/Unit/NumberPatternTest.php       unit tests of the pattern logic
locales/                               de_DE, en_GB
```
