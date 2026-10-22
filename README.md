# Ticket Numbering (`ticketnumbering`)

*Deutsch · [English](README.en.md)*

GLPI-12-Plugin, das die **Nummer neuer Tickets, Probleme und Änderungen frei
konfigurierbar** macht. Statt der durchlaufenden GLPI-Zählung erhalten neue
Objekte eine Nummer nach einem selbst definierten Muster, z. B. `260800001` für
das erste Ticket im August 2026.

**Jeder Objekttyp wird getrennt konfiguriert** — eigener Schalter, eigenes
Muster, eigener Zähler.

- **GLPI-Version:** 12.0.0 – 12.0.99 (für GLPI 11 bleibt Version 1.2.0)
- **Lizenz:** GPL-3.0-or-later
- **Autor:** Roy Brannath
- **Repository:** https://github.com/glpiplugins/ticketnumbering

## Unterstützte Objekttypen

| Objekttyp    | Tabelle          | Standard        |
| ------------ | ---------------- | --------------- |
| Ticket       | `glpi_tickets`   | **aktiv**, `{YY}{MM}#####` |
| Problem      | `glpi_problems`  | inaktiv         |
| Änderung     | `glpi_changes`   | inaktiv         |

Probleme und Änderungen sind bewusst ausgeschaltet, bis sie jemand einschaltet —
ein Update numeriert also nichts um, was vorher unangetastet war. Die drei
Tabellen haben getrennte Zähler, dasselbe Muster kann daher problemlos für alle
verwendet werden.

## Wie es funktioniert

Die Nummer in GLPI **ist** der Primärschlüssel (`glpi_tickets.id`,
`glpi_problems.id`, `glpi_changes.id`) — es gibt kein separates Nummernfeld. Das
Plugin ändert diese ID nicht nachträglich, sondern setzt vor dem Anlegen eines
Objekts den `AUTO_INCREMENT`-Zähler der jeweiligen Tabelle auf den Beginn des
passenden Nummernblocks (Hook `pre_item_add`).

Läuft das Anlegen in einer Datenbank-Transaktion, z. B. bei einem Ticket aus
einem GLPI-Formular, lehnt GLPI 12 das `ALTER TABLE` ab, weil es die Transaktion
vorzeitig festschreiben würde. Das erste Objekt eines neuen Blocks bekommt dann
den Blockanfang als feste ID, und MySQL zieht den Zähler selbst nach. Das
Ergebnis ist dasselbe.

Ein Muster besteht aus drei Teilen:

```
[feste Ziffern][Datums-Platzhalter][Zähler "#"]
```

Alles links vom Zähler bildet den **Präfix**; er bestimmt den Block. Der Zähler
läuft innerhalb des Blocks hoch und beginnt automatisch neu, sobald sich der
Präfix ändert — bei `{YY}{MM}` also zu jedem Monatswechsel.

| Muster             | Erste Nummer (August 2026) | Reset         |
| ------------------ | -------------------------- | ------------- |
| `{YY}{MM}#####`    | `260800001`                | monatlich     |
| `{YYYY}#####`      | `202600001`                | jährlich      |
| `{YY}{Q}#####`     | `26300001`                 | quartalsweise |
| `{YY}{MM}{DD}###`  | `260812001`                | täglich       |
| `{YY}{MM}{DD}####` | `2608120001`               | täglich       |
| `1#######`         | `10000001`                 | nie           |
| `#########`        | GLPI-Standardzählung       | nie           |

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
- höchstens **10 Stellen** insgesamt, und das Muster darf nicht schon heute über
  der größtmöglichen ID (4294967295) liegen.

Wird `{WW}` verwendet, rendert das Plugin das Jahr als **ISO-Jahr** (`o`), damit
der Präfix am Jahreswechsel nicht zurückspringt (der 01.01.2027 liegt in
KW 53/2026).

### 10-stellige Muster und ihr Ablaufdatum

`int unsigned` fasst maximal 4294967295 — also 10 Stellen, aber nicht jede
10-stellige Zahl. Ein Muster wie `{YY}{MM}{DD}####` funktioniert deshalb
einwandfrei, wächst aber irgendwann aus dem Wertebereich heraus (hier am
01.01.2043). Solche Muster werden **nicht abgelehnt**; die Konfigurationsseite
zeigt stattdessen an, bis zu welchem Datum das Muster passt. Ab diesem Tag
numeriert GLPI mit seiner eigenen Zählung weiter und das Plugin protokolliert
das in `files/_log/ticketnumbering.log`. 9-stellige Muster haben dieses Limit
nicht.

## Konfiguration

**Setup → Plugins → Ticket Numbering → Konfigurieren**
(`/plugins/ticketnumbering/config`)

Die Seite zeigt pro Objekttyp eine Karte mit:

- Schalter, ob die Plugin-Nummerierung für diesen Typ angewendet wird;
- dem Muster mit Live-Vorschau der nächsten Nummer;
- anklickbaren Vorlagen;
- dem aktuellen Stand: aktives Muster, nächste Nummer, aktueller Block,
  Verbrauch im Block und die höchste vorhandene ID;
- Warnungen, wenn der Block voll ist, das Muster ein Ablaufdatum hat oder eine
  bereits höhere ID verhindert, dass das Muster greifen kann.

Gespeichert wird **alles oder nichts**: ist ein Muster ungültig, bleibt die
gesamte Konfiguration unverändert und die betroffene Karte zeigt den Fehler.

Berechtigung: das Kernrecht **Setup → Konfiguration** (`config`) — `READ` zum
Ansehen, `UPDATE` zum Speichern.

Die Einstellungen liegen in `glpi_configs` im Kontext `plugin:ticketnumbering`,
je Objekttyp unter `<objekttyp>_enabled` und `<objekttyp>_pattern` (z. B.
`ticket_pattern`, `problem_enabled`). Eigene Tabellen legt das Plugin nicht an.
Eine Konfiguration aus Version 1.1.0 (Schlüssel `enabled`/`pattern`) wird bei der
Installation automatisch nach `ticket_enabled`/`ticket_pattern` übernommen.

## Wichtige Hinweise

- **Importe bleiben unangetastet.** Objekte, die mit einer expliziten `id`
  angelegt werden (z. B. durch `zammadsync`), werden nicht umnumeriert und
  verschieben den Zähler nicht.
- **Ein Muster kann nicht rückwärts greifen.** Existiert schon ein Objekt mit
  einer höheren ID als der Blockanfang, kann MySQL den Zähler nicht senken; die
  Konfigurationsseite weist darauf hin.
- **Gleichzeitiger Blockbeginn in Transaktionen.** Legen zwei Formulare im
  selben Moment das erste Objekt eines neuen Blocks an, kann eines davon an der
  doppelten ID scheitern und muss erneut abgeschickt werden. Außerhalb von
  Transaktionen (Oberfläche, E-Mail-Abholung) kann das nicht passieren.
- **Voller Block.** Sind alle Nummern eines Blocks verbraucht, vergibt GLPI die
  nächste freie ID außerhalb des Musters. Das Plugin protokolliert das in
  `files/_log/ticketnumbering.log`; mehr `#`-Stellen verhindern es.
- Bereits vergebene Nummern werden durch eine Änderung des Musters **nicht**
  verändert.

## Installation

```bash
docker exec -u www-data glpi-app php bin/console plugin:install ticketnumbering
docker exec -u www-data glpi-app php bin/console plugin:activate ticketnumbering
```

Aktivieren und Deaktivieren leeren die GLPI-Caches, damit die Route der
Konfigurationsseite sofort erreichbar ist. Die Installation ist idempotent und überschreibt eine vorhandene Konfiguration
nicht. Beim Deinstallieren werden nur die eigenen `glpi_configs`-Zeilen entfernt;
Tickets, Probleme und Änderungen bleiben unverändert.

## Entwicklung

```bash
# Unit-Tests (reine Musterlogik, ohne DB) unter PHP 8.5 im GLPI-12-Image;
# PHPUnit kommt aus einem GLPI-Checkout mit Dev-Abhängigkeiten (GLPI_CORE_DIR)
docker run --rm --entrypoint php -u "$(id -u):$(id -g)" \
  -v "$GLPI_CORE_DIR":/plugins/glpi-core:ro -v "$PWD":/plugins/ticketnumbering:ro \
  -w /plugins/ticketnumbering glpi/glpi:12.0.0 \
  /plugins/glpi-core/vendor/bin/phpunit -c phpunit.xml.dist --do-not-cache-result

# Übersetzungen (braucht gettext)
cd locales && msgfmt -o de_DE.mo de_DE.po && msgfmt -o en_GB.mo en_GB.po

# Release-Archiv build/ticketnumbering-<Version>.tar.gz samt Prüfsumme
tools/build-release.sh
```

## Aufbau

```
setup.php                              Lifecycle, Hooks, config_page
hook.php                               pre_item_add für Ticket, Problem, Change
src/Config/Settings.php                Konfiguration je Objekttyp in glpi_configs
src/Controller/ConfigController.php    GLPI-12-Controller /plugins/ticketnumbering/config
src/Service/NumberPattern.php          Muster: Parser, Validierung, Rendering (DB-frei)
src/Service/ItemNumberer.php           Block öffnen (AUTO_INCREMENT/feste ID), Status je Objekttyp
templates/pages/config.html.twig       Konfigurationsseite
public/js/config.js                    Live-Vorschau, Vorlagen-Buttons
tests/Unit/NumberPatternTest.php       Unit-Tests der Musterlogik
tests/Unit/SettingsKeysTest.php        Unit-Tests der Objekttyp-Schlüssel
tools/build-release.sh                 Release-Archiv bauen
locales/                               de_DE, en_GB
```
