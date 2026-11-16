# De Honingboekhouding

Import, export en rapportage van honingoogsten voor imkersvereniging De Zoemende Zode.

Leden leveren hun oogsten aan als CSV-bestanden in zes verschillende templates, met elk hun eigen kolomnamen, scheidingstekens, eenheden, datumnotaties en schrijfwijzen. Deze applicatie leest die bestanden in, normaliseert de gegevens en slaat ze op in één SQLite-database als betrouwbare bron van waarheid.

## Randvoorwaarden

De opdracht stelt drie randvoorwaarden. Zo zijn ze ingevuld:

**Taal: PHP 8.2 of hoger.** Er is **bewust gekozen voor PHP 8.4**, de actuele versie. Die keuze staat vast in `composer.json` (`"php": "^8.4"`).
- Composer controleert die afspraak bij `composer install`: op een oudere PHP-versie stopt het met een melding dat de versie niet past. Dat is geen fout in Composer of in de code, maar een gevolg van de gekozen minimumversie.
- De code zelf gebruikt een paar onderdelen die sinds PHP 8.3 bestaan: getypeerde klasseconstanten (`private const array UNITS`) en `mb_str_pad()` voor de uitlijning van de terminaltabellen. PHPUnit 12 vraagt ook minimaal 8.3.
- Moet het project op een oudere versie draaien, dan kan dat:
  - **8.3:** alleen de minimumversie in `composer.json` aanpassen naar `^8.3`; de code hoeft niet te veranderen.
  - **8.2:** daarnaast het type bij de constanten weghalen, `mb_str_pad()` vervangen, en PHPUnit 11 gebruiken.

**Opslag: SQLite.** Eén databasebestand, `var/honing.sqlite`, via PDO en de extensie `pdo_sqlite`.
- Het schema wordt automatisch aangemaakt. Er is geen aparte installatiestap of databaseserver nodig.
- Alle query's met invoer gebruiken prepared statements. Zie [Database](#database) voor de tabellen.

**Frameworks en packages: vrij te kiezen, met onderbouwing.** Er is **geen framework** gebruikt. De enige package is **PHPUnit**, en alleen als ontwikkelafhankelijkheid (`require-dev`) voor de tests.
- De opdracht is klein genoeg voor PHP zonder framework: drie commando's, één JSON-endpoint en één pagina. Een framework zou meer code en configuratie toevoegen dan de opdracht zelf vraagt.
- Alles wat nodig is, zit in PHP zelf: PDO voor SQLite, `str_getcsv`/`fputcsv` voor CSV, en `php -S` als webserver.
- De volledige onderbouwing staat bij [Technische keuzes](#technische-keuzes).

## Snel starten

```bash
composer install
```

```bash
php bin/honing.php import dataset
```

```bash
php bin/honing.php report
```

```bash
php bin/honing.php export
```

```bash
php -S localhost:8000 -t public
```

## Open vragen voor de penningmeester

De applicatie werkt volledig. Een paar keuzes kan alleen de vereniging maken. Tot die tijd geldt een onderbouwde voorlopige keuze:

| # | Vraag | Voorlopige keuze | Gevolg | Details |
|---|---|---|---|---|
| 1 | Hoort `Klaasveld` bij een bestaande bijenstand? (`Kerkhof`, `Molenpad` en `Molenweide` zijn bevestigd) | niet toegewezen | de oogsten met deze naam staan in `import_log` en tellen niet mee | [Bijenstanden](#bijenstanden) |
| 2 | Wat is één bijenvolk: lid + code, of alleen de code? | lid + code | bepaalt de top 5 | [Volken](#volken) |
| 3 | Is `v167` (formaat 03) hetzelfde volk als `V-167` (formaat 04)? | ja | volken uit formaat 03 en 04 met hetzelfde nummer worden één volk | [Volken](#volken) |
| 4 | Zijn de oogsten onder 2 kg in formaat 06 echt? | ja, letterlijk overgenomen | deze lichte oogsten tellen mee in de opbrengst | [Gewichten](#gewichten) |
| 5 | Is er een ledenregister? | nee: "alle leden" = alle leden die ooit iets aanleverden | een nieuw lid zonder oogst ontbreekt in "nog niets aangeleverd" | [Leden](#leden) |

---

## Vereisten en installatie

- **PHP 8.4** of hoger: de gekozen minimumversie (zie [Randvoorwaarden](#randvoorwaarden)).
- PHP-extensies **`pdo_sqlite`** en **`sqlite3`**
- **Composer**

Controleer of de extensies aanstaan:

```bash
php -m
```

In de lijst moeten `pdo_sqlite` en `sqlite3` staan. Zo niet, haal dan in `php.ini` de `;` weg voor `extension=pdo_sqlite` en `extension=sqlite3`. Zet op Windows `extension_dir` bij voorkeur op een absoluut pad, zoals `C:\php\ext`.

Installeren gaat met `composer install` (zie [Snel starten](#snel-starten)). De database (`var/honing.sqlite`) wordt automatisch aangemaakt zodra een commando hem nodig heeft (`import`, `export` of `report`).

## Gebruik

```bash
php bin/honing.php
```

Zonder commando toont dit de helptekst.

### `import`

```bash
php bin/honing.php import <bestand|map|patroon> [...] [--fresh]
```

| Voorbeeld | Wat er gebeurt |
|---|---|
| `php bin/honing.php import dataset` | alle `*.csv` in de map (niet in submappen) |
| `php bin/honing.php import dataset/01_standaard_export_01.csv` | één bestand |
| `php bin/honing.php import a.csv b.csv` | meerdere bestanden, in deze volgorde |
| `php bin/honing.php import "dataset/04_*.csv"` | jokerteken; werkt ook in PowerShell |
| `php bin/honing.php import dataset --fresh` | eerst de database leegmaken, dan importeren |

Voorbeelduitvoer:

```
Start Import
36 CSV-bestand(en) gevonden
  01_standaard_export_01.csv    539 ingelezen,    0 duplicaat,    0 overgeslagen
  02_oude_stempel_01.csv        514 ingelezen,    0 duplicaat,   25 overgeslagen
  ...

Samenvatting
  Bestanden:         36 verwerkt, 0 mislukt
  Ingelezen:      18989 nieuwe oogsten
  Duplicaat:          2 (stonden er al in)
  Overgeslagen:     413 (zie import_log)

Redenen overgeslagen
     413  Onbekende bijenstand: 'Klaasveld'
```

- **Opnieuw importeren is veilig.** Een oogst die er al in staat, telt als duplicaat en wordt niet dubbel opgeslagen.
- **`--fresh`** maakt de database pas leeg nadat alle opgegeven bestanden gevonden zijn. Bij een tikfout in een pad blijft de bestaande data dus staan.
- **Exitcode** is `0` als alle bestanden verwerkt zijn, en `1` bij een fout pad, een onbekende optie (bijvoorbeeld `--frsh`) of een mislukt bestand.

### `export`

```bash
php bin/honing.php export [uitvoer.csv]
```

Schrijft alle oogsten uit de database naar één schoon CSV-bestand. Zonder pad wordt dat `var/export.csv`.

```
datum,jaar,lid,bijenstand,volk,gewicht_kg,bronbestand,bronregel
2025-04-01,2025,"Amal Hassan",Molenstraat,BIJEN-65,6.400,06_kladblok_mess_03.csv,31
2025-04-01,2025,"Anita Bakker",Zonneweide,KONINGIN-T,9.507,02_oude_stempel_01.csv,11
```

| Kolom | Inhoud |
|---|---|
| `datum` | `JJJJ-MM-DD` |
| `jaar` | het seizoen, handig om in een spreadsheet op te filteren |
| `lid`, `bijenstand`, `volk` | de genormaliseerde namen |
| `gewicht_kg` | kilogram met 3 decimalen en een punt als decimaalteken, dus precies tot op de gram (`0.605`) |
| `bronbestand`, `bronregel` | waar de oogst oorspronkelijk vandaan komt |

**Keuze:** het formaat volgt de CSV-standaard (RFC 4180): komma als scheidingsteken, UTF-8 zonder BOM, en een aanhalingsteken binnen een waarde wordt verdubbeld. De regels zijn gesorteerd op datum, lid en volk.

**Onderliggende argumenten:**
1. *Eén vaste vorm:* de penningmeester wilde juist af van de wildgroei aan formaten. Dit is het ene formaat dat elke tool begrijpt.
2. *Exact:* gewicht met 3 decimalen verliest geen gram ten opzichte van de database. Het totaal in de export is gelijk aan dat in de database.
3. *Herleidbaar:* `bronbestand` en `bronregel` maken elke regel controleerbaar.
4. *Opnieuw te importeren:* `gewicht_kg` staat ook in `config/kolommen.php`. Importeer je de export opnieuw, dan wordt alles als duplicaat herkend (getest op de volledige dataset: 0 nieuw, alles duplicaat).

**Keuze:** standaard naar een bestand schrijven, niet naar het scherm.

**Onderliggend argument:**
1. *Windows PowerShell 5.1* schrijft bij `> export.csv` UTF-16. Dat is geen schone CSV meer.

Het bestand wordt eerst als `.tmp` geschreven en daarna hernoemd. Gaat er halverwege iets mis, dan blijft er nooit een half of half overschreven bestand achter.

- **Exitcode** is `0` als de export gelukt is. Bij `1` gaat het om een van deze fouten:

  | Situatie | Melding |
  |---|---|
  | map van het uitvoerbestand bestaat niet | `Fout: Map bestaat niet: '...'` |
  | meer dan één uitvoerbestand opgegeven | `Fout: Geef hooguit één uitvoerbestand op` |
  | een optie opgegeven (bijv. `--year=2025`) | `Fout: Onbekende optie: --year=2025` |
  | bestand kan niet geschreven worden (bijv. geopend in Excel) | `Fout: Kan '...' niet schrijven` |

- **Lege database:** de export bevat dan alleen de header, met een waarschuwing om eerst te importeren. Dit is geen fout (exitcode `0`).

> **Excel:** een Nederlandstalige Excel verwacht vaak `;` als scheidingsteken. Open het bestand dan via *Gegevens › Uit tekst/CSV* en kies *komma*.

**Keuze:** bescherming tegen **CSV-injectie**. Begint een tekstwaarde uit de ledenbestanden (naam, bijenstand, volkcode, bronbestand) met `=`, `+`, `-`, `@`, een tab of een carriage return, dan zet de export er een `'` voor.

**Onderliggende argumenten:**
1. *De bestanden komen van buiten:* een lid kan, per ongeluk of expres, een naam als `=HYPERLINK("http://...";"klik")` aanleveren. Excel en LibreOffice voeren zo'n waarde bij het openen van de export uit als **formule**. Dat kan een link naar een verkeerde site zijn, of gegevens uit andere cellen versturen.
2. *Het apostrof-voorvoegsel is de gangbare oplossing* (aanbevolen door OWASP): de spreadsheet toont de waarde als gewone tekst, en de `'` zelf is in de cel niet zichtbaar.
3. *Raakt de echte data niet:* in de dataset begint geen enkele waarde met zo'n teken. De export van de volledige dataset is byte voor byte gelijk aan die van vóór deze aanpassing. Namen met een streepje in het midden (`El-Mansoor`) blijven ongewijzigd.
4. *Alleen tekst van buiten:* datums en gewichten maakt de applicatie zelf, en die beginnen nooit met zo'n teken.

**Gevolg:** een geneutraliseerde waarde komt bij opnieuw importeren van de export terug mét `'` ervoor, en telt dan niet als duplicaat. Dat kan alleen gebeuren bij een naam die met `=`, `+`, `-` of `@` begint, en die komt in de dataset niet voor.

### `report`

```bash
php bin/honing.php report [--year=JJJJ]
```

Toont de drie rapportages in de terminal (zie ook [Rapportages](#rapportages)):

1. totale opbrengst per bijenstand per jaar, met een totaal per jaar (altijd alle jaren);
2. de top 5 best presterende volken van het gekozen jaar;
3. de leden die in het gekozen jaar nog niets hebben aangeleverd, met hun laatste aanlevering.

```
$ php bin/honing.php report
Totale opbrengst per bijenstand per jaar
========================================
  Jaar   Bijenstand       Oogsten      Opbrengst
  ----   --------------   -------   ------------
  2025   Achter de Kerk     3.207    46.959,6 kg
         Bosrand            3.283    48.088,2 kg
         ...
         Totaal            18.989   278.511,7 kg

Top 5 best presterende volken in 2025
=====================================
   #   Lid                Volk         Oogsten   Opbrengst   Bijenstand(en)
  --   ----------------   ----------   -------   ---------   ----------------------------
  1.   Sara van Leeuwen   KONINGIN-E         9    165,9 kg   Achter de Kerk, Bosrand, ...
  ...

Leden die in 2025 nog niets hebben aangeleverd
==============================================
  Alle bekende leden hebben aangeleverd.
```

**Keuze:** zonder `--year` gebruikt het rapport het **laatste jaar waarvan oogsten zijn aangeleverd**, nu 2025. Een ander jaar kies je met `--year=JJJJ`.

**Onderliggende argumenten:**
1. *De datasets zijn per jaar:* leden leveren hun CSV-bestand aan het eind van het seizoen aan bij de penningmeester. Het lopende jaar kan dus nog niet volledig zijn aangeleverd.
2. *Geen misleidende cijfers:* met het lopende jaar als standaard zou de top 5 leeg zijn, en zouden alle leden onterecht bij "nog niets aangeleverd" staan.
3. *Past bij de jaarvergadering:* daar wordt het afgesloten seizoen besproken.

Het standaardjaar staat niet vast in de code: het is het hoogste jaar in de database (bij een lege database het vorige kalenderjaar). Zodra er data over een nieuw seizoen is geïmporteerd, schuift het rapport vanzelf door, ook als nog niet alle leden hebben aangeleverd. Dat is juist wat "nog niets aangeleverd" dan laat zien.

- **Weergave:** getallen in Nederlandse notatie (`46.959,6 kg`), afgerond op 1 decimaal voor de leesbaarheid. Exacte waarden staan in de database en in de export. Datums in de vorm `DD-MM-JJJJ`.
- **Exitcode** is `0`, ook als een rapport leeg is. Bij `1` gaat het om een van deze fouten:

  | Situatie | Melding |
  |---|---|
  | jaar niet in 4 cijfers (`--year=25`, `--year`) | `Fout: Ongeldig jaar: '--year=25'. Gebruik bijvoorbeeld --year=2025` |
  | andere optie of los argument (`--top=3`, `2025`) | `Fout: Onbekende optie: ...` |

### Rapportage in de browser

```bash
php -S localhost:8000 -t public
```

Open daarna <http://localhost:8000>. De pagina haalt de rapportages asynchroon op met `fetch()` en toont ze als drie tabellen. Met de jaarkeuze bovenaan laad je een ander jaar, zonder dat de pagina herlaadt. Stoppen doe je met `Ctrl+C`.

**JSON-endpoint:**

| Verzoek | Antwoord |
|---|---|
| `GET /api/report` | alle drie de rapportages voor het standaardjaar (laatste jaar met data) |
| `GET /api/report?year=2025` | idem, voor een gekozen jaar |
| `GET /api/report?type=top&year=2025` | alleen één rapportage: `opbrengst`, `top` of `leden` |

```json
{
    "jaar": 2025,
    "beschikbare_jaren": [2025],
    "opbrengst_per_bijenstand_per_jaar": [
        { "jaar": 2025, "bijenstand": "Achter de Kerk", "aantal_oogsten": 3207, "totaal_gram": 46959555, "totaal_kg": 46959.555 }
    ],
    "top_volken": [
        { "positie": 1, "lid": "Sara van Leeuwen", "volk": "KONINGIN-E",
          "bijenstanden": ["Achter de Kerk", "Bosrand", "Molenstraat", "Zonneweide"],
          "aantal_oogsten": 9, "totaal_gram": 165942, "totaal_kg": 165.942 }
    ],
    "leden_zonder_aanlevering": []
}
```

- **Getallen zijn echte JSON-getallen**, geen opgemaakte tekst. De pagina zet ze zelf om naar Nederlandse notatie (`46.959,6 kg`).
- `beschikbare_jaren` vult de jaarkeuze op de pagina.

**Foutafhandeling:** elke fout komt terug als JSON met een sleutel `fout` en de juiste HTTP-status:

| Situatie | Status | Voorbeeld |
|---|---|---|
| jaar niet in 4 cijfers | 400 | `{"fout": "Ongeldig jaar, gebruik 4 cijfers, bv. ?year=2025"}` |
| onbekend `type` | 400 | `{"fout": "Ongeldig type, kies uit: opbrengst, top, leden"}` |
| onbekende parameter (bijv. `?jaar=`) | 400 | `{"fout": "Onbekende parameter: jaar"}` |
| onbekend pad | 404 | `{"fout": "Onbekend endpoint: /api/onbekend. Gebruik /api/report"}` |
| andere methode dan GET | 405 | `{"fout": "Methode POST niet toegestaan, gebruik GET"}` |
| onverwachte fout (bijv. database niet te openen) | 500 | `{"fout": "Er ging iets mis op de server. Probeer het later opnieuw."}` |

**Keuze:** de logica van het endpoint zit in `src/Web/ApiController.php`. `public/index.php` doet alleen het versturen.

**Onderliggende argumenten:**
1. *Testbaar zonder webserver:* de controller kent geen `$_GET`, `header()` of `echo`. Hij krijgt methode, pad en parameters binnen en geeft een status en gegevens terug.
2. *Dezelfde cijfers als de terminal:* de controller gebruikt dezelfde `ReportService` als het `report`-commando, inclusief hetzelfde standaardjaar.

**Keuze:** kale HTML met een klein stuk JavaScript, zonder framework of build-stap.

**Onderliggende argumenten:**
1. *De opdracht:* "kaal HTML is prima".
2. *Niets te installeren:* `php -S` en een browser zijn genoeg.
3. *Veilig:* namen uit de data worden met `textContent` in de pagina gezet en nooit als HTML uitgevoerd.

**Keuze:** een eenvoudige styling met één `<style>`-blok in `templates/report.html`, zonder CSS-framework of externe bestanden.

**Onderliggende argumenten:**
1. *Leesbaarheid:* tabellen met een duidelijke kop, afwisselende regelkleur en rechts uitgelijnde getallen met gelijke cijferbreedte (`tabular-nums`), zodat kilo's onder elkaar staan.
2. *Werkt offline:* geen CDN of extra bestanden. `php -S` serveert alles.
3. *Ook op telefoon:* brede tabellen scrollen binnen hun eigen vak, en de pagina zelf schuift niet opzij. Namen en codes breken niet halverwege af.
4. *Eén accentkleur (honing):* CSS-variabelen bovenaan (`--honey`, `--border`, ...) maken de kleuren op één plek aanpasbaar.

**Keuze:** een donkere modus die standaard de instelling van de computer volgt, met een knop rechtsboven om te wisselen: *Thema: systeem → licht → donker*.

**Onderliggende argumenten:**
1. *Volgt de gebruiker:* staat de computer op donker, dan is de pagina vanzelf donker (`prefers-color-scheme`). Er is geen instelling nodig.
2. *Toch zelf te kiezen:* bijvoorbeeld licht op een beamer bij de jaarvergadering, terwijl de laptop zelf op donker staat. De keuze wordt per browser onthouden (`localStorage`). Werkt opslaan niet, zoals in een privévenster, dan volgt de pagina gewoon het systeem.
3. *Weinig code:* alle kleuren waren al variabelen. De donkere modus is één blok andere waarden, en geen tweede stylesheet.
4. *Geen flits:* een klein script in `<head>` zet het gekozen thema vóórdat de pagina getekend wordt, zodat een donkere pagina niet eerst wit oplicht.
5. *Ook formulierelementen:* `color-scheme` zorgt dat de jaarkeuze en de scrollbalken meekleuren.

#### Beveiligingsheaders

Elk antwoord van de webserver, ook een foutmelding, krijgt beveiligingsheaders mee. Ze worden gezet in `src/Web/SecurityHeaders.php`.

| Header | Waarde | Waartegen |
|---|---|---|
| `Content-Security-Policy` | pagina: alleen de eigen inline code (via hashes), `fetch` alleen naar de eigen server; JSON: `default-src 'none'` | uitvoeren van geïnjecteerde scripts en stijlen (XSS) |
| `X-Content-Type-Options` | `nosniff` | de browser die JSON of tekst toch als HTML of script gaat lezen |
| `X-Frame-Options` + CSP `frame-ancestors` | `DENY` / `'none'` | de pagina onzichtbaar in een frame op een andere site laden (clickjacking) |
| `Referrer-Policy` | `no-referrer` | het adres van de pagina doorgeven aan andere sites |
| `Permissions-Policy` | camera, microfoon, locatie, ... uit | misbruik van browserfuncties die de pagina niet gebruikt |
| `Cross-Origin-Opener-Policy` / `-Resource-Policy` | `same-origin` | andere sites die bij dit venster of deze gegevens kunnen |
| `Cache-Control` (alleen JSON) | `no-store` | ledennamen (persoonsgegevens) die in de cache van de browser of een proxy blijven staan |

**Keuze:** een strikte Content-Security-Policy met **hashes** van de inline code, in plaats van `'unsafe-inline'`.

**Onderliggende argumenten:**
1. *Echte bescherming:* met `'unsafe-inline'` zou een geïnjecteerd `<script>` gewoon draaien, en dan doet de CSP niets. Met hashes mag alleen exact de code uit de pagina zelf draaien.
2. *Geen onderhoud:* de hashes worden bij elk verzoek berekend uit de HTML. Pas je de pagina aan, dan klopt de CSP automatisch. Er staat nooit een verouderde hash in de code.
3. *Eén bestand blijft:* CSS en JavaScript hoeven niet naar losse bestanden.

**Keuze:** de pagina staat in `templates/report.html`, buiten de webroot `public/`.

**Onderliggend argument:**
1. *Geen omweg zonder headers:* `php -S` serveert een bestand dat in `public/` staat rechtstreeks, zonder `index.php` en dus zonder headers. Nu loopt elk verzoek via `index.php`.

**Niet toegevoegd:** `Strict-Transport-Security` (HSTS). Die werkt alleen via HTTPS, en `php -S` draait op HTTP. Komt de pagina ooit achter een echte webserver met HTTPS, dan hoort HSTS daar in de configuratie van die server.

> **Alleen lokaal bedoeld.** `php -S` is een ontwikkelserver en er is geen login. Start hem met `localhost`, zoals hierboven, zodat hij alleen op je eigen computer bereikbaar is. Met `0.0.0.0` zou iedereen in het netwerk de namen en opbrengsten van de leden kunnen zien.

## Tests

```bash
vendor/bin/phpunit
```

Alle tests zijn groen, zonder waarschuwingen of deprecations.

| Testbestand | Wat er getest wordt |
|---|---|
| `tests/Csv/` | scheidingsteken herkennen; kolomnamen koppelen; foutmelding bij een ontbrekende kolom |
| `tests/Normalizer/` | elke normalizer met de echte randgevallen uit de dataset, plus ongeldige invoer |
| `tests/Import/FileCollectorTest` | bestanden, mappen, jokertekens, dubbelen, lock- en verborgen bestanden (in een tijdelijke map) |
| `tests/Import/ImporterTest` | opslaan, duplicaten, `import_log`, en **terugdraaien**: een databasefout halverwege een bestand wordt nagebootst met een SQLite-trigger |
| `tests/Export/` | CSV-formaat, sortering, aanhalingstekens, geen half bestand, export → opnieuw importeren, en het neutraliseren van formules (CSV-injectie) |
| `tests/Report/` | de drie rapportages, gedeelde plaatsen, het standaardjaar en de terminal-opmaak |
| `tests/Web/` | het JSON-endpoint, inclusief alle foutstatussen (400/404/405); de beveiligingsheaders, waaronder dat de CSP precies de inline code van de echte pagina toestaat en nooit `unsafe-inline`; een serverfout (500) toont geen paden of SQL, maar logt de details wel |
| `tests/Cli/` | **end-to-end:** het echte commando `php bin/honing.php` als los proces; uitvoer en exitcodes van import → export → report en van fout gebruik |

- De meeste tests gebruiken een **database in het geheugen** (`':memory:'`), zodat elke test leeg begint.
- De end-to-end tests draaien op een **tijdelijke database** via de omgevingsvariabele `HONING_DB`. Je eigen `var/honing.sqlite` wordt dus nooit aangeraakt.

---

## Projectstructuur

```
bin/honing.php              CLI-entrypoint (switch op het commando)
config/
  kolommen.php              kolomnamen per veld (aliassen)
  bijenstanden.php          schrijfwijzen per bijenstand
src/
  Database.php              PDO-verbinding, schema, transacties
  Csv/
    CsvReader.php           scheidingsteken herkennen
    ColumnMapper.php        kolomnamen koppelen aan velden
  Normalizer/
    WeightParser.php        gewicht -> grammen
    DateParser.php          datum -> JJJJ-MM-DD
    BeehouseResolver.php    schrijfwijze -> officiële bijenstand
    MemberResolver.php      naam -> lid
    ColonyResolver.php      volkcode -> vaste notatie
  Import/
    FileCollector.php       bestanden, mappen en jokertekens -> lijst CSV's
    Importer.php            één bestand inlezen en opslaan (transactie)
  Export/
    Exporter.php            database -> één schone CSV
  Report/
    ReportService.php       de drie rapportages (voor terminal en browser)
    ReportPrinter.php       rapportages -> tekst voor de terminal
  Web/
    ApiController.php       JSON-endpoint /api/report
    SecurityHeaders.php     beveiligingsheaders, CSP met hashes van de inline code
public/
  index.php                 router voor php -S: / -> rapportagepagina, /api/report -> JSON, overal headers
templates/
  report.html               rapportagepagina (fetch, styling, donkere modus); bewust buiten public/
tests/                      zelfde indeling als src/, plus Cli/ (end-to-end)
var/honing.sqlite           de database
var/export.csv              standaard uitvoer van export
dataset/                    de aangeleverde CSV-bestanden
```

## Dataset

36 CSV-bestanden: 6 templates × 6 bestanden, elk 539 oogsten, samen 19.404 regels, allemaal uit seizoen 2025 en van 42 leden.

| Template | Scheiding | Gewicht | Bijzonderheden |
|---|---|---|---|
| `01_standaard_export` | `,` | `Oogst_KG` in kg | lidnummer wisselt per regel |
| `02_oude_stempel` | `;` | `Gewicht` + `Eenheid` (`gram`) | veel schrijfwijzen van bijenstanden |
| `03_chaoot_oogst` | `,` | `opbrengst`, geen eenheid (kg) | 5 datumnotaties door elkaar |
| `04_export_app` | `\|` | `YieldAmount` + `Unit` (`kg`) | Engelse kolomnamen |
| `05_backup_tab` | tab | `Kilo` | |
| `06_kladblok_mess` | `,` | vrije tekst (`21700g`, `"14,4"`, `"13 kg"`) | 5 datumnotaties door elkaar; lage gewichten (zie [Gewichten](#gewichten)) |

De notatie van de volkcodes per template staat onder [Volken](#volken), voorbeelden van datums en gewichten onder [Normalisatie per veld](#normalisatie-per-veld).

De **nette** templates (01, 04, 05) gebruiken alleen de 6 officiële namen van bijenstanden. De **rommelige** templates (02, 03, 06) gebruiken ±30 schrijfwijzen.

**Resultaat van de import:**

| | Aantal |
|---|---|
| Regels in de bestanden | 19.404 |
| Opgeslagen oogsten | 18.989 |
| Duplicaten (dezelfde oogst in formaat 01 én 05) | 2 |
| Overgeslagen, in `import_log` (twijfelnaam `Klaasveld`) | 413 |
| Leden / bijenstanden / volken (lid + code) | 42 / 6 / 9.323 |
| Totale opbrengst | 278.511,659 kg |

## Database

| Tabel | Inhoud |
|---|---|
| `members` | leden (`naam`, uniek) |
| `beehouses` | bijenstanden (`naam`, uniek) |
| `harvests` | oogsten: lid, bijenstand, volkcode, datum (ISO), gewicht in **grammen**, bronbestand en regelnummer |
| `import_log` | overgeslagen regels: bronbestand, regelnummer, reden en de ruwe regel |

- **Gewicht als geheel getal in grammen** voorkomt afrondingsfouten met kommagetallen.
- **Elke oogst houdt `source_file` en `source_line` bij.** Zo is elke waarde terug te voeren op de originele regel bij het lid.
- **Een oogst is uniek op lid + volk + datum + gewicht.** Dezelfde oogst in twee bestanden, ook in een ander formaat, wordt één keer opgeslagen.

## Hoe de import werkt

```
paden ─▶ FileCollector ─▶ per bestand, in één transactie:
                           header ─▶ CsvReader (scheidingsteken)
                                 ─▶ ColumnMapper (welke kolom is welk veld)
                           per regel ─▶ WeightParser, DateParser,
                                        BeehouseResolver, MemberResolver, ColonyResolver
                                     ─▶ INSERT OR IGNORE in harvests
                                        of, bij een fout, naar import_log
```

**Twee soorten fouten, bewust verschillend behandeld:**

| Soort fout | Voorbeeld | Gevolg |
|---|---|---|
| Fout in één regel | onbekende bijenstand, onleesbare datum, gewicht 0, verkeerd aantal kolommen | alleen die regel gaat naar `import_log`, de rest van het bestand gaat door |
| Fout in het hele bestand | onbekende kolommen, databasefout | het **hele bestand** wordt teruggedraaid, het volgende bestand gaat door |

Er komt dus nooit een half bestand in de database.

**`import_log`:**
- De ruwe regel wordt exact opgeslagen zoals hij in het bestand staat, inclusief aanhalingstekens.
- Opnieuw importeren vervangt de log van dat bestand. De log groeit dus niet bij elke run, maar laat altijd de laatste import zien.
- Een mislukt bestand krijgt één log-regel met `source_line = 0` en als reden `Bestand teruggedraaid: ...`.
- Duplicaten zijn geen fout en komen niet in de log.

## Rapportages

`src/Report/ReportService.php` berekent de drie rapportages. De service geeft alleen gegevens terug (arrays) en doet geen opmaak.

**Keuze:** één service voor terminal én browser.

**Onderliggende argumenten:**
1. *Eén plek voor de berekening:* het `report`-commando en het JSON-endpoint tonen gegarandeerd dezelfde cijfers.
2. *Los te testen:* de rapportages worden getest zonder terminal of webserver.
3. *JSON-klaar:* de arrays zijn direct met `json_encode` om te zetten.

Gewichten komen terug in **grammen** (exact) én in **kg** (afgerond op 3 decimalen). De weergave kiest zelf welke ze toont.

| Methode | Rapport | Uitkomst op de dataset |
|---|---|---|
| `yieldPerBeehousePerYear()` | totale opbrengst per bijenstand per jaar | 6 standen in 2025; het totaal is gelijk aan dat in de database |
| `topColonies($jaar, $aantal = 5)` | best presterende volken van een jaar, op totale opbrengst | zie [Volken](#volken) |
| `membersWithoutSubmission($seizoen)` | leden die in dat seizoen nog niets hebben aangeleverd, met hun laatste eerdere aanlevering | 2025: niemand, 2026: alle 42 leden |
| `availableYears()` | jaren waarin geoogst is, voor keuzelijsten | `[2025]` |
| `defaultYear($huidigJaar)` | het jaar dat de rapporten standaard tonen: laatste jaar met data | `2025` |

In de opbrengst per bijenstand is Klaproos lager dan de andere standen, doordat de twijfelnaam [`Klaasveld`](#bijenstanden) nog niet is toegewezen.

**Keuzes in de berekening:**
- **Top 5, gelijke opbrengst:** gelijke volken delen een plaats, zoals bij een sportuitslag. Na een gedeelde plaats wordt het volgende nummer overgeslagen: 1, 2, 2, 4, 5.
  - Delen volken de **laatste** plaats, dan komen ze er allemaal in. De "top 5" kan dan uit 6 of meer regels bestaan. Een volk weglaten dat precies evenveel heeft opgebracht als een volk dat wel in de lijst staat, zou willekeurig zijn.
  - Binnen een gedeelde plaats staan de volken op naam van het lid en daarna op volkcode, zodat de volgorde altijd hetzelfde is.
  - In de dataset hebben veel volken precies hetzelfde totaal (honderden groepen volken met precies hetzelfde totaal), dus dit komt echt voor. In de top 5 van 2025 toevallig niet.
- **Verhuisd volk:** dat toont alle bijenstanden waar het geoogst is.
- **Leden zonder aanlevering:** omdat er geen ledenregister is, gelden als "alle leden" de leden die ooit een oogst hebben aangeleverd. Een nieuw lid dat nog nooit iets heeft aangeleverd, is niet bekend en verschijnt dus niet in deze lijst (zie [Leden](#leden)).
- **Een jaar** buiten 1900–2999 of een aantal kleiner dan 1 geeft een foutmelding.

## Normalisatie per veld

| Veld | In de bronbestanden | Na normalisatie |
|---|---|---|
| Scheidingsteken | `,` `;` `\|` tab | automatisch herkend: het teken dat de headerregel in de meeste kolommen splitst |
| Kolomnamen | bijv. veld `weight`: `Oogst_KG`, `Gewicht`, `opbrengst`, `YieldAmount`, `Kilo`, `gewicht` | vaste velden via `config/kolommen.php`, ongevoelig voor hoofdletters |
| Gewicht | `21.9`, `19509` + kolom `gram`, `21700g`, `"14,4"`, `"13 kg"`, `"605 g"` | grammen (geheel getal) |
| Datum | `2025-05-28`, `2025/07/05`, `02-07-2025`, `01/09/2025`, `26.07.2025`, `14 september 2025`, `7 jul 2025` | `JJJJ-MM-DD` |
| Bijenstand | ±30 schrijfwijzen, zoals `AdK`, `achter d kerk`, `HV`, `ZW-1`, `Zonne weide` | 6 officiële namen via `config/bijenstanden.php` |
| Lid | `Rik Bos`, `  rik   bos ` | `Rik Bos` |
| Volk | `VOLK-12`, `Koningin M`, `v167`, `V-100`, `Bijen 48` | `VOLK-12`, `KONINGIN-M`, `V-167`, `V-100`, `BIJEN-48` |

**Gewicht, volgorde van de eenheid:**
1. de eenheid in de waarde zelf (`21700g`);
2. anders de kolom `Eenheid`/`Unit`;
3. anders kg.

Kolommen die niet geïmporteerd worden, zoals dracht en kwaliteit, worden genegeerd. Zie [Aannames en open vragen](#aannames-en-open-vragen).

---

## Technische keuzes

**Keuze:** plain PHP 8.4 met PDO, zonder framework. Alleen PHPUnit is toegevoegd, als ontwikkelafhankelijkheid.

**Onderliggende argumenten:**
1. *De opdracht is klein genoeg voor PHP zonder framework:* drie CLI-commando's, één JSON-endpoint en één pagina. Een framework als Laravel of Symfony brengt routing, een service container, configuratiebestanden en tientallen packages mee. Die lossen problemen op die deze opdracht niet heeft, terwijl de code die het werk doet (CSV inlezen, normaliseren, opslaan, rapporteren) even groot blijft.
2. *Alles wat nodig is, zit in PHP zelf:* `PDO` voor SQLite, `str_getcsv` voor CSV, `php -S` als webserver.
3. *Overdraagbaar:* `composer install` en je kunt beginnen. Er zijn geen externe services of extra installatiestappen.
4. *Toetsbaar:* de kern bestaat uit kleine klassen met één taak. Die krijgen hun afhankelijkheden via de constructor (dependency injection) en zijn daardoor los te testen.

**Keuze:** gegevens die kunnen veranderen staan in `config/`, niet in de code.

**Onderliggende argumenten:**
1. *Nieuwe variant, geen nieuwe code:* gebruikt een lid een nieuwe kolomnaam of schrijfwijze, dan is dat één regel in `config/kolommen.php` of `config/bijenstanden.php`.
2. *Duidelijke scheiding:* de config bevat wat de vereniging beslist, de code bevat hoe het verwerkt wordt.

**Keuze:** kolommen worden herkend aan hun **naam** en niet aan het bestandsformaat of de bestandsnaam.

**Onderliggende argumenten:**
1. *Robuust:* een lid kan een bestand elke naam geven, en kolommen mogen in elke volgorde staan.
2. *Duidelijke foutmelding:* ontbreekt een verplichte kolom, dan meldt de import welke. Er komt dan geen lege data in de database.

**Naamgeving:**
- **PSR-4:** namespaces en mappen in PascalCase en gelijk aan elkaar (`App\Csv\CsvReader` in `src/Csv/CsvReader.php`, tests in `App\Tests\Csv`). Daardoor werkt de autoloader ook op hoofdlettergevoelige systemen zoals Linux.
- **Code in het Engels:** klassen, methodes en testnamen zijn Engels (`Importer::importFile()`, `ReportService::topColonies()`, `testThrowsOnUnknownBeehouse`), zoals gebruikelijk in PHP-projecten.
- **Variabelen en commentaar** zijn ook Engels, net als de veldnamen in `config/kolommen.php` (`member`, `beehouse`, `colony`, `date`, `weight`, `unit`) en de databasetabellen (`members`, `beehouses`, `harvests`).
- **Gebruikerstekst in het Nederlands:** alles wat een gebruiker ziet of gebruikt, omdat de gebruikers een Nederlandstalige vereniging zijn. Dat zijn foutmeldingen en terminaluitvoer, de tekst op de webpagina, de JSON-sleutels en `type`-waarden van `/api/report` (een publieke API), de kolomnamen van de export (`Exporter::COLUMNS`) en de aliassen in de config, want die komen uit de bestanden van de leden. Testdata (CSV-regels, namen van bijenstanden) is Nederlands omdat de echte data dat ook is.

---

## Aannames en open vragen

### Dracht, kwaliteit en beoordeling

**Keuze:** de kolommen voor dracht (`Dracht`, `Bloemsoort`, `honing_type`, `NectarSource`, `Soort`, `bloem`) en kwaliteit (`Kwaliteit`, `rating`, `Cijfer`, `smaak_score`) worden **niet** geïmporteerd.

**Onderliggende argumenten:**
1. *Niet nodig:* geen van de gevraagde rapportages gebruikt ze.
2. *Schalen zijn onvergelijkbaar:* tekst (`Matig` … `Perfect` in 01, `matig` … `excellent` in 06), `1/5`–`5/5` (03), een cijfer van 5–10 (05). In 02 en 04 ontbreekt de kolom helemaal. Omrekenen naar één schaal zou giswerk zijn.
3. *Dracht mengt soorten informatie:* seizoenen (`Zomer`, `Voorjaar`) naast bloemsoorten (`Linde`, `Klaver`), en in formaat 04 in het Engels (`Spring`, `Clover`).
4. *Niets gaat verloren:* via `source_file` en `source_line` is de originele waarde altijd terug te vinden.

### Leden

**Keuze:** leden worden gekoppeld op **naam**, niet op lidnummer.

**Onderliggende argument:**
1. *Lidnummers zijn onbruikbaar:* in formaat 01 en 04 krijgt hetzelfde lid per regel een ander `LID-xxx`-nummer.

**Keuze:** de eerste schrijfwijze van een naam wordt de officiële naam van het lid. Varianten die alleen in hoofdletters of spaties verschillen, worden daaraan gekoppeld.

**Onderliggende argumenten:**
1. *Geen bron van waarheid:* er is geen ledenlijst waaruit een "juiste" schrijfwijze te halen is.
2. *Eén lid, één record:* `Rik Bos` en `rik  bos` moeten één lid worden, anders telt hetzelfde lid dubbel in de rapportages.
3. *Automatisch corrigeren is riskant:* tussenvoegsels (`van den Berg`) en samengestelde namen (`El Idrissi`, `El-Mansoor`) volgen geen vaste hoofdletterregel.
4. *Echte invoer:* de eerste schrijfwijze is een naam die het lid zelf zo heeft ingevoerd, geen variant die de software verzonnen heeft.
5. *Voorspelbaar:* dezelfde bestanden in dezelfde volgorde geven altijd dezelfde uitkomst.

**Context:** in de huidige dataset komen geen varianten voor. Alle 42 namen worden overal gelijk geschreven. De regel is een vangnet voor latere aanleveringen.

**Open vraag:** er is geen ledenregister. Voor het rapport *leden die dit seizoen nog niets hebben aangeleverd* is een lijst nodig van wie er had móeten aanleveren.

- **Voorlopige keuze:** "alle leden" zijn alle leden die ooit een oogst hebben aangeleverd (tabel `members`). Een lid zonder oogst in het gekozen seizoen staat in het rapport, met de datum van zijn laatste eerdere aanlevering.
- **Beperking:** een nieuw lid dat nog nooit iets heeft aangeleverd, is niet bekend en verschijnt dus niet.
- **Met de huidige dataset:** alle data is van 2025, en alle 42 bekende leden hebben aangeleverd. Het rapport over 2025 is daarom leeg. Dat is een juiste uitkomst, geen fout.
- **Als er een register komt** (bijvoorbeeld `leden.csv`), dan kan dat als lijst van leden worden ingelezen. De rapportage zelf hoeft niet te veranderen.

### Bijenstanden

**Aanname:** de ±30 schrijfwijzen in de data horen bij 6 officiële bijenstanden: Achter de Kerk, Bosrand, Heideveld, Klaproos, Molenstraat en Zonneweide. Noord/Zuid/West-varianten (`Bosrand Noord`, `Heideveld Zuid`, `Zonneweide West`) horen bij de hoofdstand.

**Onderliggende argumenten:**
1. *De nette formaten* (01, 04, 05) gebruiken alleen deze zes namen.
2. *De opdracht* spreekt van "een handvol bijenstanden".

**Bevestigd** door de penningmeester, en opgenomen als schrijfwijze in `config/bijenstanden.php`:

| Schrijfwijze | Oogsten | Hoort bij |
|---|---|---|
| `Kerkhof` | 239 | Achter de Kerk |
| `Molenpad` | 371 | Molenstraat |
| `Molenweide` | 423 | Molenstraat |

**Open vraag:** één schrijfwijze is **voorlopig niet toegewezen**:

| Schrijfwijze | Oogsten | Vermoedelijk |
|---|---|---|
| `Klaasveld` | 413 (2,1 %) | Klaproos |

Deze regels komen als *Onbekende bijenstand* in `import_log` en tellen nog **niet** mee in de rapportages.

**Onderbouwing van de vermoedelijke toewijzing:**
1. *Alleen in de rommelige formaten:* `Klaasveld` komt alleen voor in formaat 02, 03 en 06, nooit in de nette formaten 01, 04 en 05. Het is dus een andere schrijfwijze en geen extra bijenstand.
2. *De verdeling klopt alleen met deze toewijzing:* in de nette formaten heeft elke stand ±16,5 % van de oogsten. In de rommelige formaten kwamen zonder de twijfelnamen precies drie standen tekort:

   | Bijenstand | Nette formaten | Rommelig, zonder twijfelnamen | Rommelig, met toewijzing |
   |---|---|---|---|
   | Achter de Kerk (met `Kerkhof`, bevestigd) | 16,5 % | 14,1 % | 16,5 % |
   | Klaproos (met `Klaasveld`, open) | 17,1 % | 12,4 % | 16,7 % |
   | Molenstraat (met `Molenpad` en `Molenweide`, bevestigd) | 16,4 % | 8,3 % | 16,5 % |

   Bosrand, Heideveld en Zonneweide zitten zonder de twijfelnamen al op ±16,5 %. Met de bevestigde toewijzingen komen Achter de Kerk en Molenstraat inderdaad op hetzelfde niveau uit als die drie. Dat ondersteunt ook de vermoedelijke toewijzing van `Klaasveld`.

**Gevolg zolang dit open staat:** Klaproos lijkt in de rapportages kleiner dan de andere standen.

**Na bevestiging door de penningmeester:** voeg de naam toe in `config/bijenstanden.php` en importeer opnieuw:

```bash
php bin/honing.php import dataset --fresh
```

### Volken

**Aanname:** volkcodes worden omgezet naar één notatie, `VOORVOEGSEL-ID`. Codes met hetzelfde voorvoegsel horen bij dezelfde nummering.

| Formaat | In de bron | Genormaliseerd |
|---|---|---|
| 01, 05 | `VOLK-12` | `VOLK-12` |
| 02 | `Koningin M` | `KONINGIN-M` |
| 03 | `v167` | `V-167` |
| 04 | `V-100` | `V-100` |
| 06 | `Bijen 48` | `BIJEN-48` |

**Onderliggende argumenten:**
1. *Elk template heeft een eigen nummering:* `VOLK-1..50` (01, 05), `Koningin A..Z` (02), `v10..199` (03), `V-100..225` (04), `Bijen 1..100` (06).
2. *Zelfde voorvoegsel betekent zelfde nummering:* `v167` (03) en `V-167` (04) worden als hetzelfde volk gezien. Formaat 01 en 05 gebruiken al exact dezelfde notatie.
3. *Verschillende voorvoegsels blijven apart:* `VOLK-12` en `BIJEN-12` worden niet samengevoegd. De data geeft geen enkele aanwijzing dat ze hetzelfde volk zijn.
4. *Een code alleen is niet uniek:* dezelfde code wordt per formaat door gemiddeld 14 tot 40 verschillende leden gebruikt. `VOLK-12` komt bijvoorbeeld bij 33 leden voor. Daarom is een volk de combinatie **lid + code**.

**Open vraag:** wat is één volk?

De opdracht spreekt van "ruim honderd bijenvolken". Geen van de mogelijke definities komt daar in de buurt (aantallen: zie [Resultaat van de import](#dataset)):

| Definitie | Aantal volken | Gem. oogsten per volk |
|---|---|---|
| Lid + code (huidige keuze) | ±9.300 | ~2 |
| Alleen de code (verenigingsbreed) | 368 | ~52 |

- **Lid + code** is het meest logisch, want een volk is van één imker. Met gemiddeld 2 oogsten per volk wordt de *top 5 best presterende volken* wel vooral een lijst van toevallige uitschieters.
- **Alleen de code** geeft meer oogsten per volk, maar telt dan oogsten van tientallen verschillende leden bij één volk op. Dat is niet realistisch.

Deze keuze bepaalt de uitkomst van de top 5. Totdat de penningmeester uitsluitsel geeft, wordt **lid + code** gebruikt. Ook de koppeling `v167` = `V-167` moet zij nog bevestigen.

**Gevolg voor de top 5:** templates met weinig nummers bundelen meer oogsten per volk dan templates met veel nummers:

| Voorvoegsel | Nummers | Volken | Gem. oogsten per volk | Gem. opbrengst per volk |
|---|---|---|---|---|
| `KONINGIN` | 26 | 1.017 | 3,0 | 45,2 kg |
| `VOLK` | 50 | 1.989 | 3,3 | 44,6 kg |
| `V` | ±200 | 4.134 | 1,5 | 27,4 kg |
| `BIJEN` | 100 | 2.183 | 1,4 | 14,0 kg |

De top 5 van 2025 bestaat daardoor alleen uit `VOLK`- en `KONINGIN`-volken. Dat zegt vooral iets over de nummering van het template, niet over hoe goed een volk presteert. Ook zijn de topvolken in één seizoen op 4 tot 6 verschillende bijenstanden geoogst. Dat past niet bij een echt bijenvolk en bevestigt dat de volkcodes in deze dataset weinig betekenis hebben.

### Gewichten

**Aanname:** een gewicht zonder eenheid is in **kilogram**. Dat geldt voor formaat 01 (`Oogst_KG`), 05 (`Kilo`) en 03 (`opbrengst`).

**Onderliggend argument:**
1. *De waarden passen alleen bij kg:* in formaat 03 liggen ze tussen 2 en 35. In grammen zou dat geen oogst zijn.

**Keuze:** alleen `g`, `gr`, `gram`, `kg` en `kilo` worden herkend. Een andere eenheid, zoals `pond` of `ons`, geeft een foutmelding en de regel komt in `import_log`.

**Onderliggende argumenten:**
1. *Komt niet voor:* alle 19.404 gewichten in de dataset zijn in g of kg.
2. *"Pond" is dubbelzinnig:* het Nederlandse pond is 500 g, het Engelse pound 453,6 g. Formaat 04 is een Engelstalige app. Een foutmelding is veiliger dan stilletjes de verkeerde omrekening.

**Open vraag:** opvallend lage gewichten in formaat 06.

In formaat 06 (`06_kladblok_mess_*`) zijn **745 van de 3.234 oogsten lichter dan 2 kg**: 298 onder 1 kg (bijvoorbeeld `"605 g"`, `"886 g"`, laagste 400 g) en 447 tussen 1 en 2 kg (bijvoorbeeld `"1253 g"`). In de andere vijf formaten is de lichtste oogst 2 kg.

- Het is een patroon in één template, geen losse uitschieter.
- De import neemt deze gewichten letterlijk over: `605 g` = 605 gram. Ze worden **niet** overgeslagen en tellen mee in de rapportages.
- Het kan om echte, kleine oogsten gaan of om een invoerfout in dit template. Dat moet de penningmeester beoordelen.

### Datums

**Aanname:** datums met alleen cijfers staan in **dag-maand-jaar**-volgorde (`01/09/2025` = 1 september), tenzij het jaar vooraan staat (`2025/07/05`).

**Onderliggende argumenten:**
1. *Bevestigd in de data:* in 1.558 datums is het eerste getal groter dan 12, en het tweede getal nooit. Een maand-dag-volgorde (Amerikaans) komt dus niet voor.
2. *Past bij het seizoen:* alle datums vallen tussen 1 april en 29 september 2025.

**Keuze:** alleen Nederlandse maandnamen worden herkend, voluit en afgekort (`september`, `sep`, `sept`, `okt.`). Engelse maandnamen (`may`, `oct`) niet: die komen in de data niet voor. Een onmogelijke datum, zoals `31-02-2025`, wordt geweigerd.

### CSV-bestanden

**Aanname:** een waarde bevat nooit een regeleinde. De import leest per regel, zodat de ruwe regel exact in `import_log` kan komen. Dat komt in de dataset niet voor.
