# Konzept – Mitgliederverwaltung AFOL.lu

> Stand: 2026-06-17 · Status: **Entwurf zur Abstimmung**

## 1. Ziel

Eine Web-Anwendung zur Verwaltung der Mitglieder von **AFOL.lu** (Adult Fans of
LEGO Luxembourg). Im ersten Schritt steht die **Mitglieder-Stammdatenverwaltung**
im Mittelpunkt (Anlegen, Bearbeiten, Suchen, Auflisten, Löschen/Archivieren).

Die Daten stammen aus **mehreren Quellen**. Die Anwendung soll diese
zusammenführen und über ein **Dashboard** überschaubar machen sowie aktiv
helfen, die **Daten vollständig und konsistent** zu halten.

Spätere Ausbaustufen (Beiträge/Zahlungen, Events, Newsletter) werden im
Datenmodell und in der Architektur bereits berücksichtigt, aber noch nicht gebaut.

### Leitgedanke „eine Wahrheit“
Mitgliederdaten liegen heute verteilt vor (z. B. Tabellen, Anmeldeformulare,
Forum, Zahlungsdienste). Ziel ist ein **zentraler Mitgliederbestand**, in den
diese Quellen einfließen. Das Dashboard zeigt jederzeit, **wie vollständig**
der Bestand ist und **wo nachgepflegt** werden muss.

### Mitglieds-Lebenszyklus (zentrale Geschäftslogik)

```
  Forum-Registrierung (WoltLab)      Antrag                Bestätigung
  ───────────────────────────►  ──────────────────►  ──────────────────────►
  Forum-Nutzer                  Antragsteller         Mitglied (aktiv)
  (kein Mitglied,               (online ODER Papier   (erst nach Zahlungs-
   Daten teils vorhanden)        auf Ausstellung)      bestätigung durch Trésorier)
```

**Regeln:**
1. **Forum-Account ist Pflicht** für jedes Mitglied – die gesamte
   Vereinskommunikation läuft über WoltLab. Ein zukünftiges Mitglied kann sich
   im Forum registrieren, ist damit aber **noch kein Mitglied**; ein Teil der
   Stammdaten ist dann bereits vorhanden und kann übernommen werden.
2. **Antragstellung** auf zwei Wegen:
   - **Online-Antrag** (Formular)
   - **Papier-Antrag**, ausgefüllt auf einer Ausstellung (später erfasst)
3. **Mitglied wird man erst**, wenn der **Trésorier die Bezahlung bestätigt**
   – per **Überweisung** oder **bar**. Vorher gilt der Status „Antrag“.
4. Die eigentliche **Mitgliederverwaltung liegt in einer eigenen Tabelle**
   (`mitglieder`), **getrennt** von den WoltLab-Tabellen. WoltLab bleibt die
   Identitäts-/Login-Quelle, die Mitgliedschaft selbst wird hier geführt.

> Konsequenz fürs Dashboard: Die „Pipeline“ Forum-Nutzer → Antragsteller →
> Mitglied wird sichtbar gemacht, inkl. **offener Anträge, die auf die
> Zahlungsbestätigung warten**.

## 2. Technologie

| Bereich       | Wahl                                              |
|---------------|---------------------------------------------------|
| Sprache       | PHP 7.4+ (vorwärtskompatibel zu PHP 8)            |
| Datenbank     | MySQL 5.7+ / MariaDB (über PDO, prepared stmts)   |
| Frontend      | HTML5 + etwas CSS, serverseitig gerendert (Views) |
| Architektur   | Eigenes, schlankes **MVC** (kein Framework)       |
| Community     | **WoltLab Suite 5.5** (PHP/MySQL) – liefert Login |
| Abhängigkeiten| Composer nur für Autoloading (PSR-4), optional    |

**Designprinzipien**
- Saubere Trennung Controller / Model / View.
- Ein **Front Controller** (`public/index.php`) als einziger Einstiegspunkt.
- Alle DB-Zugriffe über **PDO mit Prepared Statements** (SQL-Injection-Schutz).
- Konfiguration getrennt vom Code (`config/`), Secrets nicht im Repo.
- UTF-8 durchgängig.

## 3. Projektstruktur

```
MemberMgt/
├── public/                 # Web-Root (einziges öffentlich erreichbares Verzeichnis)
│   ├── index.php           # Front Controller
│   ├── .htaccess           # Rewrite auf index.php (Apache)
│   └── assets/
│       └── css/style.css
├── app/
│   ├── Core/               # Framework-Kern
│   │   ├── Router.php      # URL -> Controller/Action
│   │   ├── Controller.php  # Basis-Controller (View-Rendering)
│   │   ├── Model.php       # Basis-Model (DB-Helfer)
│   │   └── Database.php    # PDO-Verbindung (Singleton)
│   ├── Controllers/
│   │   └── MitgliederController.php
│   ├── Models/
│   │   └── Mitglied.php
│   └── Views/
│       ├── layout/         # Kopf-/Fußzeile, gemeinsames Layout
│       │   ├── header.php
│       │   └── footer.php
│       └── mitglieder/     # index, show, create, edit (Formular)
├── config/
│   ├── config.php          # App-Einstellungen (Basis-URL etc.)
│   ├── database.php        # DB-Zugangsdaten (aus .env / nicht ins Repo)
│   └── database.example.php
├── sql/
│   └── schema.sql          # Tabellen-Definitionen + Beispieldaten
├── .gitignore
├── README.md
└── KONZEPT.md
```

## 3a. Login über WoltLab Suite 5.5

Es wird **kein eigenes Benutzer-/Passwort-System** gebaut. Stattdessen werden die
bestehenden **WoltLab-Community-Accounts** für Authentifizierung und Berechtigung
genutzt. WoltLab speichert Nutzer u. a. in `wcf1_user`, Sitzungen in
`wcf1_user_session`/`wcf1_session`, Gruppen in `wcf1_user_group(_to_user)`.

**Mögliche Integrationswege (zu entscheiden):**
1. **Session-/Cookie-Abgleich (empfohlen, geringer Aufwand):** Die App liest das
   WoltLab-Login-Cookie und validiert die Session gegen die WoltLab-DB. Wer im
   Forum eingeloggt ist, ist auch hier eingeloggt (SSO-Gefühl). Zugriff auf
   die Verwaltung nur für bestimmte **WoltLab-Benutzergruppen** (z. B. „Vorstand“).
2. **WoltLab-Paket/Plugin:** Die App als WoltLab-Package integrieren. Tiefste
   Integration, aber deutlich höherer Aufwand und an WoltLab-Strukturen gebunden.

> Verknüpfung: Jedes Mitglied wird mit einer **WoltLab-User-ID** (`wcf_user_id`)
> verknüpft → Brücke zwischen Verwaltung und Community. Für **aktive Mitglieder
> ist diese Verknüpfung Pflicht** (Forum-Account verpflichtend), für Antragsteller
> kann sie noch fehlen.

## 4. Datenmodell (Stammdaten)

### Tabelle `mitglieder`

| Spalte            | Typ                    | Hinweise                                  |
|-------------------|------------------------|-------------------------------------------|
| id                | INT, PK, AUTO_INCREMENT|                                           |
| mitgliedsnummer   | VARCHAR(20), UNIQUE    | Vereins-Mitgliedsnummer                   |
| vorname           | VARCHAR(100)           | Pflicht                                   |
| nachname          | VARCHAR(100)           | Pflicht                                   |
| email             | VARCHAR(150)           | Pflicht, eindeutig                        |
| telefon           | VARCHAR(40)            | optional                                  |
| strasse           | VARCHAR(150)           | optional                                  |
| plz               | VARCHAR(10)            | optional                                  |
| ort               | VARCHAR(100)           | optional                                  |
| land              | VARCHAR(60)            | Default 'Luxembourg'                      |
| geburtsdatum      | DATE                   | optional                                  |
| status            | ENUM('antrag','aktiv','pausiert','inaktiv','ausgetreten','abgelehnt') | Default 'antrag' |
| antragsart        | ENUM('online','papier')| wie der Antrag gestellt wurde             |
| antragsdatum      | DATE                   | Datum der Antragstellung                  |
| zahlungsart       | ENUM('ueberweisung','bar') NULL | wird bei Bestätigung gesetzt     |
| zahlung_bestaetigt_am | DATE NULL          | **macht zum Mitglied** (durch Trésorier)  |
| bestaetigt_durch  | INT NULL               | `wcf_user_id` des bestätigenden Trésorier |
| beitrittsdatum    | DATE NULL              | gesetzt bei Bestätigung (= Mitglied seit) |
| austrittsdatum    | DATE NULL              | gesetzt bei Austritt                      |
| wcf_user_id       | INT NULL, UNIQUE       | WoltLab-Account – **Pflicht für Mitglieder** |
| forum_name        | VARCHAR(100)           | AFOL-spezifisch (Community-/Forenname)    |
| quelle            | VARCHAR(50)            | Herkunft des Datensatzes (s. Datenquellen)|
| vollstaendigkeit  | TINYINT                | berechneter Vollständigkeits-% (Cache)    |
| bricklink_user    | VARCHAR(100)           | AFOL-spezifisch, optional                 |
| bemerkung         | TEXT                   | freie Notizen / LEGO-Interessen           |
| created_at        | DATETIME               | Default CURRENT_TIMESTAMP                 |
| updated_at        | DATETIME               | aktualisiert bei Änderung                 |

> **Statuslogik:** Ein Datensatz startet als `antrag` (online oder Papier).
> Erst wenn der **Trésorier** `zahlung_bestaetigt_am` + `zahlungsart` setzt,
> wechselt der Status auf `aktiv` und `beitrittsdatum` wird gesetzt → **jetzt
> Mitglied**. `wcf_user_id` ist für aktive Mitglieder verpflichtend.

> **Archivieren statt Löschen:** Austritte werden über `status='ausgetreten'` +
> `austrittsdatum` abgebildet (Daten bleiben erhalten). Hartes Löschen bleibt
> als Admin-Funktion möglich.

### Ausblick (noch nicht gebaut, nur vorgesehen)
- `import_log` (welche Quelle wann importiert, Anzahl, Fehler) – für das Dashboard.
- `beitraege`, `zahlungen` – Beitragsverwaltung.
- `events`, `event_anmeldungen` – Veranstaltungen.

> Login/Rollen kommen aus **WoltLab** (siehe 3a) – keine eigene `benutzer`-Tabelle.

## 4a. Datenquellen & Zusammenführung

Die Mitgliederdaten kommen aus mehreren Quellen. Diese werden in den zentralen
Bestand `mitglieder` zusammengeführt. Geplante/erwartete Quellen (vom Verein
zu liefern – **bitte Beispiele/Exporte bereitstellen**):

| Quelle              | Beispiel-Inhalt                       | Anbindung (Vorschlag)        |
|---------------------|---------------------------------------|------------------------------|
| WoltLab Suite 5.5   | Accounts, E-Mail, Forenname, Gruppen  | direkter DB-Lesezugriff      |
| Tabellen (Excel/CSV)| bestehende Mitgliederlisten           | CSV-Import                   |
| Anmeldeformulare    | Neuzugänge                            | CSV/Formular-Import          |
| Zahlungen (optional)| Beitragszahler                        | CSV-Import (später)          |

**Prinzip:** Jeder importierte Datensatz erhält eine `quelle`. Über ein
**Matching** (z. B. E-Mail oder WoltLab-User-ID) werden Dubletten erkannt und
zusammengeführt, statt Mehrfach-Datensätze anzulegen.

> Damit der Import passgenau wird, brauche ich von dir **je Quelle ein
> Beispiel/Export** (Spaltennamen, Format). Dann definiere ich das Mapping.

## 4b. Dashboard

Das Dashboard ist die Startseite und gibt einen schnellen Überblick:

- **Pipeline / Lebenszyklus:** Forum-Nutzer → Antragsteller → Mitglieder.
  Besonders hervorgehoben: **offene Anträge, die auf die Zahlungsbestätigung
  des Trésorier warten** (Aufgabenliste „zu bestätigen“), getrennt nach
  Online- und Papier-Antrag.
- **Kennzahlen:** Mitglieder gesamt, aktiv/pausiert/inaktiv/ausgetreten,
  Anträge offen, Neuzugänge (Zeitraum), mit/ohne WoltLab-Verknüpfung.
- **Datenqualität:** Anteil vollständiger Datensätze, Top-Lücken
  (z. B. „12 ohne E-Mail“, „5 aktive Mitglieder ohne Forum-Account“),
  Dubletten-Verdacht.
- **Quellenübersicht:** Datensätze je Quelle, letzter Import, Differenzen
  zwischen Quellen (z. B. „im Forum, aber kein Antrag/Mitglied“).
- **Direkte Sprünge:** Klick auf eine Lücke/Aufgabe → gefilterte Liste zum
  Nachpflegen bzw. Bestätigen.

## 4c. Datenvollständigkeit

- Pro Mitglied wird ein **Vollständigkeitswert** aus definierten Pflicht-/
  Wunschfeldern berechnet (Feld `vollstaendigkeit`).
- **Regeln/Checks**, z. B.: E-Mail vorhanden & gültig, Beitrittsdatum gesetzt,
  Adresse vollständig, **WoltLab-Verknüpfung vorhanden (für aktive Mitglieder
  Pflicht)**, Zahlungsart/-bestätigung dokumentiert.
- Das Dashboard und farbliche Markierungen in der Liste lenken die Pflege
  gezielt auf unvollständige Datensätze.

## 4d. Quelle im Detail: WoltLab-Formular „Mitgliedsantrag" (formID 3)

Der Online-Antrag ist ein WoltLab-Formular (Plugin „Forms"). Relevante Tabellen:

| Tabelle              | Rolle                                                        |
|----------------------|-------------------------------------------------------------|
| `wcf1_form`          | Formular-Definition; **`formID = 3`** = Mitgliedsantrag      |
| `wcf1_form_field`    | Feld-Definitionen (Label `title`, `optionType`, `required`, `selectOptions`, `showOrder`) |
| `wcf1_form_response` | Abgeschickte Anträge (eine Zeile = ein Antrag)              |
| `wcf1_form_action`   | Automatik-Aktionen beim Absenden (E-Mail/Gruppe – später)   |

**Wichtige `wcf1_form_response`-Spalten:**
- `userID` → WoltLab-Account → unser **`wcf_user_id`** (NULL bei Gast)
- `username` → Forenname → **`forum_name`**
- `time` (Unix-Timestamp) → **`antragsdatum`**
- `fields` (longtext) → die eingegebenen **Werte je `fieldID`** (gegen
  `wcf1_form_field` aufzulösen)
- `isDone`, `status('accepted','declined')` → **WoltLab-eigener** Bearbeitungs-
  status; **nicht** mit unserer Zahlungsbestätigung verwechseln

**Import-/Sync-Logik (Vorschlag):**
1. Felddefinitionen aus `wcf1_form_field WHERE formID=3` laden (fieldID → Bedeutung).
2. Anträge aus `wcf1_form_response WHERE formID=3` lesen, `fields` parsen.
3. Pro Antrag einen `mitglieder`-Datensatz mit `status='antrag'`,
   `antragsart='online'`, `quelle='woltlab_form'` anlegen/aktualisieren
   (Matching über `wcf_user_id`/E-Mail, keine Dubletten).
4. Mitglied wird daraus erst durch die **Trésorier-Zahlungsbestätigung** (separat).

> Offen für exaktes Mapping: die **Feldzeilen** (`formID=3`) und **eine
> Beispiel-`fields`-Zelle** einer Antwort (Format der gespeicherten Werte).

## 5. Funktionsumfang Stufe 1 (Stammdaten)

| Route (Beispiel)                  | Aktion        | Beschreibung                          |
|-----------------------------------|---------------|---------------------------------------|
| `GET /dashboard`                  | dashboard     | Überblick + offene Aufgaben           |
| `GET /mitglieder`                 | index         | Liste + Suche/Filter (nach Status)    |
| `GET /mitglieder/show/{id}`       | show          | Detailansicht                         |
| `GET /mitglieder/create`          | create        | Formular „Neuer Antrag/Mitglied“      |
| `POST /mitglieder`                | store         | Speichern (mit Validierung)           |
| `GET /mitglieder/edit/{id}`       | edit          | Formular „Bearbeiten“                 |
| `POST /mitglieder/update/{id}`    | update        | Änderungen speichern                  |
| `POST /mitglieder/bestaetigen/{id}` | confirm     | **Trésorier**: Zahlung bestätigen → Mitglied |
| `POST /mitglieder/delete/{id}`    | destroy       | Löschen/Archivieren                   |

**Validierung (serverseitig):** Pflichtfelder, gültige E-Mail, eindeutige
E-Mail/Mitgliedsnummer, plausible Datumswerte. **Statuswechsel auf `aktiv`** nur
über die Bestätigungs-Aktion (setzt `zahlungsart`, `zahlung_bestaetigt_am`,
`bestaetigt_durch`, `beitrittsdatum`) – idealerweise nur für die
Trésorier-Berechtigung freigegeben.

## 6. Sicherheit (von Anfang an)
- PDO Prepared Statements gegen SQL-Injection.
- Ausgabe-Escaping (`htmlspecialchars`) in allen Views gegen XSS.
- CSRF-Token in allen Formularen.
- DB-Zugangsdaten außerhalb des Web-Roots und nicht im Repo (`.gitignore`).
- **Login/Zugriffsschutz über WoltLab** (siehe 3a); Bestätigen von Zahlungen nur
  für die Trésorier-/Vorstands-Gruppe.

## 7. Vorgeschlagene Umsetzungsreihenfolge
1. Projektgerüst + MVC-Kern (Router, Database, Basis-Controller/-Model).
2. DB-Schema `mitglieder` (inkl. Status/Antrag/Zahlung) + Beispieldaten.
3. Mitglieder-/Antrags-Liste mit Suche & Status-Filter.
4. Anlegen (Antrag) + Bearbeiten + Validierung.
5. **Bestätigungs-Workflow** (Trésorier: Zahlung → Mitglied).
6. Detailansicht + Archivieren (Austritt).
7. Dashboard (Pipeline, offene Anträge, Datenqualität).
8. WoltLab-Login-Anbindung + Datenquellen-Import.

## 8. Offene Fragen
1. **Datenquellen:** Bitte je Quelle ein Beispiel/Export (Spaltennamen, Format)
   bereitstellen, damit ich das Import-Mapping definieren kann.
2. **WoltLab-Login:** Session-/Cookie-Abgleich (empfohlen) oder WoltLab-Paket?
   Läuft die Verwaltung auf demselben Server/derselben DB wie WoltLab?
3. **Berechtigung:** Welche WoltLab-Benutzergruppe(n) dürfen die Verwaltung sehen
   bzw. bearbeiten (z. B. „Vorstand“)?
4. **Mitgliedsnummer:** automatisch fortlaufend oder manuell?
5. **Sprache der Oberfläche:** Deutsch, Französisch, Englisch oder mehrsprachig?
