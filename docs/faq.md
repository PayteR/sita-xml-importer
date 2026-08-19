# Riešenie problémov (FAQ)

## Potrebujem niečo od agentúry SITA?

Áno. Bez aktívneho prístupu k XML kanálom SITA nemá plugin čo importovať.

- **Záujem o prístup:** [obchod@sita.sk](mailto:obchod@sita.sk)
- **Ďalšie kontakty:** https://biz.sita.sk/#kontakty

## Import nebeží alebo mešká

WP-Cron spúšťajú návštevy stránky, takže na málo navštevovanom webe sa import odloží až do ďalšej
návštevy. Riešenie a nastavenie systémového cronu nájdete v
[inštalácii a nastavení](instalacia-a-nastavenie.md#spoľahlivé-spúšťanie-wp-cron).

Ak stavový riadok hlási, že import bol zaradený, ale nezačal, býva na vine zablokovaný loopback
alebo vypnutý WP-Cron na hostingu.

## „Importovať teraz" neskončí okamžite

Tak to má byť. Import beží na pozadí, aby neblokoval administráciu. Stavový riadok sa priebežne
aktualizuje a výsledok sa objaví v zázname importov. Veľký prvý import prebehne vo viacerých
automatických prechodoch.

## Niektoré články sa neimportovali

Najčastejšia príčina: **článok nemá titulný obrázok** (`ObrazokLinka`) — také sa zámerne
preskakujú. Pozri [formát XML](xml-format.md#preskakovanie-článkov).

Ďalšou možnosťou je vlastný filter `sita_xml_importer_article`, ktorý článok odmietol.

## Články sa importujú dvakrát

Nemalo by sa to stávať — kontrola duplicít je založená na indexovanej tabuľke. Ak prechádzate zo
starého pluginu, uistite sa, že ste nezmazali staré post meta skôr, než dobehla migrácia. Pozri
[ukladanie dát a migrácia](ukladanie-dat-a-migracia.md).

## Chcem preskočiť určité články

Použite filter [`sita_xml_importer_article`](hooks.md#sita_xml_importer_article).

## Prechádzam zo starého pluginu `sita-parser-xml`

Nastavenia sa prenesú automaticky pri aktivácii a kontrola duplicít funguje okamžite. Na karte
*Údržba* potom raz spustite migráciu údajov. Podrobnosti v
[ukladaní dát a migrácii](ukladanie-dat-a-migracia.md).

## Ako nahlásim chybu?

Cez [GitHub Issues](https://github.com/PayteR/sita-xml-importer/issues). Priložte prosím
**diagnostickú správu** — stiahnete ju na karte *Údržba* (obsahuje prostredie, stav databázy
a posledné chyby).

Otázky k prístupu ku kanálom, faktúram alebo obsahu riešte cez kontakty vyššie, nie cez GitHub.
