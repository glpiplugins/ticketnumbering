# Ticket Numbering (`ticketnumbering`)

*Deutsch · [English](README.en.md)*

GLPI-11-Plugin, das die **Nummer neuer Tickets frei konfigurierbar** macht.
Statt der durchlaufenden GLPI-Zählung erhalten neue Tickets eine Nummer nach
einem selbst definierten Muster, z. B. `260800001` für das erste Ticket im
August 2026.

- **GLPI-Version:** 11.0.0 – 11.99.99
- **Lizenz:** GPL-3.0-or-later
- **Autor:** Roy Brannath
- **Repository:** https://github.com/glpiplugins/ticketnumbering

## Wie es funktioniert

Die Ticketnummer in GLPI **ist** der Primärschlüssel `glpi_tickets.id` — es gibt
kein separates Nummernfeld. Das Plugin ändert diese ID nicht nachträglich,
sondern setzt vor dem Anlegen eines Tickets den `AUTO_INCREMENT`-Zähler der
Tabelle auf den Beginn des passenden Nummernblocks (Hook `pre_item_add`).

Ein Muster besteht aus drei Teilen:

```
[feste Ziffern][Datums-Platzhalter][Zähler "#"]
```

Alles links vom Zähler bildet den **Präfix**; er bestimmt den Block. Der Zähler
läuft innerhalb des Blocks hoch und beginnt automatisch neu, sobald sich der
Präfix ändert — bei `{YY}{MM}` also zu jedem Monatswechsel.

| Muster            | Erste Nummer (August 2026) | Reset       |
| ----------------- | -------------------------- | ----------- |
| `{YY}{MM}#####`   | `260800001`                | monatlich   |
| `{YYYY}#####`     | `202600001`                | jährlich    |
| `{YY}{Q}#####`    | `26300001`                 | quartalsweise |
| `{YY}{MM}{DD}###` | `260812001`                | täglich     |
| `1#######`        | `10000001`                 | nie         |
| `#########`       | GLPI-Standardzählung       | nie         |

### Platzhalter

| Platzhalter | Bedeutung                            |
| ----------- | ------------------------------------ |
| `{YYYY}`    | vierstelliges Jahr (2026)            |
| `{YY}`      | zweistelliges Jahr (26)              |
| `{Q}`       | Quartal (1–4)                        |
| `{MM}`      | zweistelliger Monat (08)             |
| `{WW}`      | zweistellige ISO-Kalenderwoche (32)  |
| `{DD}`      | zweistelliger Tag des Monats (12)    |
| `#`         | eine Stelle des laufenden Zählers    |

### Regeln (werden beim Speichern geprüft)

Weil die Nummer eine vorzeichenlose Integer-ID ist und ein `AUTO_INCREMENT`-Wert
**nur wachsen** kann, muss der Präfix über die Zeit monoton steigen. Das Plugin
lässt daher nur Muster zu, die das garantieren:

- nur Ziffern, `#` und die bekannten Platzhalter — keine Buchstaben, keine
  Trennzeichen wie `-` oder `/`;
- feste Ziffern nur am Anfang;
- Datums-Platzhalter von der größten zur kleinsten Einheit, jeder höchstens
  einmal;
- `{DD}` nur direkt nach `{MM}`;
- sobald ein Datums-Platzhalter verwendet wird, muss `{YY}` oder `{YYYY}` am
  Anfang stehen;
- genau ein `#`-Block, und zwar am Ende (1–9 Stellen);
- die größtmögliche Nummer muss in `int unsigned` passen (≤ 4294967295), daher
  ist z. B. `{YYYY}{MM}#####` (11 Stellen) nicht erlaubt.

Wird `{WW}` verwendet, rendert das Plugin das Jahr als **ISO-Jahr** (`o`), damit
der Präfix am Jahreswechsel nicht zurückspringt (der 01.01.2027 liegt in
KW 53/2026).

## Konfiguration

**Setup → Plugins → Ticket Numbering → Konfigurieren**
(`/plugins/ticketnumbering/config`)

Die Seite bietet:

- Schalter, ob die Plugin-Nummerierung überhaupt angewendet wird;
- das Muster mit Live-Vorschau der nächsten Nummer;
- anklickbare Beispielmuster;
- den aktuellen Stand: aktives Muster, nächste Ticketnummer, aktueller Block,
  Verbrauch im Block und die höchste vorhandene Ticket-ID;
- Warnungen, wenn der Block voll ist oder eine bereits höhere Ticket-ID
  verhindert, dass das Muster greifen kann.

Berechtigung: das Kernrecht **Setup → Konfiguration** (`config`) — `READ` zum
Ansehen, `UPDATE` zum Speichern.

Die Einstellungen liegen in `glpi_configs` im Kontext `plugin:ticketnumbering`
(Schlüssel `enabled`, `pattern`). Eigene Tabellen legt das Plugin nicht an.

## Wichtige Hinweise

- **Importe bleiben unangetastet.** Tickets, die mit einer expliziten `id`
  angelegt werden (z. B. durch `zammadsync`), werden nicht umnumeriert und
  verschieben den Zähler nicht.
- **Ein Muster kann nicht rückwärts greifen.** Existiert schon ein Ticket mit
  einer höheren ID als der Blockanfang, kann MySQL den Zähler nicht senken; die
  Konfigurationsseite weist darauf hin.
- **Voller Block.** Sind alle Nummern eines Blocks verbraucht, vergibt GLPI die
  nächste freie ID außerhalb des Musters. Das Plugin protokolliert das in
  `files/_log/ticketnumbering.log`; mehr `#`-Stellen verhindern es.
- Bereits vergebene Ticketnummern werden durch eine Änderung des Musters **nicht**
  verändert.

## Installation

```bash
docker exec -u www-data glpi-glpi-1 php bin/console plugin:install ticketnumbering
docker exec -u www-data glpi-glpi-1 php bin/console plugin:activate ticketnumbering
```

Die Installation ist idempotent und überschreibt eine vorhandene Konfiguration
nicht. Beim Deinstallieren werden nur die eigenen `glpi_configs`-Zeilen entfernt;
Tickets bleiben unverändert.

## Entwicklung

```bash
# Unit-Tests (reine Musterlogik, ohne DB)
docker exec -u www-data glpi-glpi-1 sh -lc \
  'cd /var/www/glpi/plugins/ticketnumbering && php ../glpi-core/vendor/bin/phpunit -c phpunit.xml.dist'

# Übersetzungen
docker exec glpi-glpi-1 sh -lc \
  'cd /var/www/glpi/plugins/ticketnumbering/locales && msgfmt -o de_DE.mo de_DE.po && msgfmt -o en_GB.mo en_GB.po'
```

## Aufbau

```
setup.php                              Lifecycle, Hooks, config_page
hook.php                               pre_item_add für Ticket
src/Config/Settings.php                Konfiguration in glpi_configs
src/Controller/ConfigController.php    GLPI-11-Controller /plugins/ticketnumbering/config
src/Service/NumberPattern.php          Muster: Parser, Validierung, Rendering (DB-frei)
src/Service/TicketNumberer.php         AUTO_INCREMENT setzen, Statusabfrage
templates/pages/config.html.twig       Konfigurationsseite
public/js/config.js                    Live-Vorschau, Beispiel-Buttons
tests/Unit/NumberPatternTest.php       Unit-Tests der Musterlogik
locales/                               de_DE, en_GB
```
