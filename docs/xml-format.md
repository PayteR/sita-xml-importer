# Formát XML kanála

Plugin spracúva XML formát agentúry SITA. Každý článok je zabalený v prvku `<Sprava>`.

> **Spracovanie mimo WordPressu.** Parser je k dispozícii aj ako samostatná PHP knižnica
> bez závislostí — `composer require sita/xml-feed-parser`, prípadne stačí skopírovať
> jediný súbor z [PayteR/sita-xml-feed-parser](https://github.com/PayteR/sita-xml-feed-parser).
> Nemusíte teda formát nižšie implementovať znova.

## Prvky článku

| Prvok | Význam |
|---|---|
| `ID` | Identifikátor článku |
| `UnikatneID` | Unikátny identifikátor |
| `DatumVydania` / `CasVydania` | Dátum a čas vydania |
| `DatumAktualizacie` / `CasAktualizacie` | Dátum a čas poslednej úpravy |
| `Nadpis` | Nadpis |
| `Perex` | Perex (výňatok) |
| `TextContent` | Celé telo článku (HTML) |
| `Sekcia > Rubrika` | Hlavná kategória |
| `Sekcia > Podrubrika` | Podkategória |
| `Lokality > Lokalita` | Lokality |

## Obrázok

| Prvok | Význam |
|---|---|
| `Obrazok > ObrazokLinka` | Adresa titulného obrázka |
| `Obrazok > ObrazokTitulok` | Popis obrázka |
| `Obrazok > ObrazokZdroj` | Fotokredit (zdroj) |
| `Obrazok > VelkostSuboru` | Veľkosť súboru |
| `Obrazok > ObrazokVyska`, `ObrazokSirka` | Rozmery obrázka |

Ak kanál poskytuje `ObrazokTitulok` a `ObrazokZdroj`, plugin ich použije ako popis a fotokredit
prílohy. Staršie kanály bez týchto prvkov použijú popis z IPTC/EXIF metadát súboru.

## Preskakovanie článkov

**Články bez titulného obrázka (`ObrazokLinka`) sa zámerne preskakujú** a do WordPressu sa
neimportujú. Ide o vlastnosť, nie chybu.

Ďalšie články môžete preskočiť vlastným kódom cez filter
[`sita_xml_importer_article`](hooks.md#sita_xml_importer_article).

## Duplicity a aktualizácie

Každý článok sa importuje **raz**. Plugin si mapovanie článku na príspevok WordPressu drží vo
vlastnej tabuľke (pozri [ukladanie dát](ukladanie-dat-a-migracia.md)). Existujúci príspevok sa
aktualizuje len vtedy, keď sa článok zmenil v zdroji — podľa `DatumAktualizacie` /
`CasAktualizacie`.
