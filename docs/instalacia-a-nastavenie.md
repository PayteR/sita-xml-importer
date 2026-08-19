# Inštalácia a nastavenie

## Inštalácia

1. Nainštalujte plugin z adresára pluginov WordPressu (**Pluginy → Pridať nový** → hľadajte
   „SITA XML Importer"), alebo nahrajte priečinok `sita-xml-importer` do `/wp-content/plugins/`.
2. Aktivujte plugin v menu **Pluginy**.
3. Prejdite do **Nastavenia → SITA XML Importer**.

## Základné nastavenie

| Nastavenie | Popis |
|---|---|
| **Adresy XML kanálov** | Jedna adresa na riadok. Adresa môže obsahovať prístupový token vydaný agentúrou SITA. |
| **Frekvencia importu** | 15 minút, 30 minút alebo hodina. Predvolene 30 minút. |
| **Autor príspevkov** | Používateľ, pod ktorým sa vytvárajú importované príspevky. |
| **Typ príspevku** | Predvolene `post`, možno zvoliť vlastný typ. |
| **Stav príspevku** | Napríklad `publish` alebo `draft`. |
| **Kategórie** | Automatické vytváranie kategórií podľa rubrík a podrubrík z kanála. |
| **Filtrovanie HTML** | Ktoré HTML značky sa v obsahu článku ponechajú. |

Po uložení nastavení beží import automaticky. Nič ďalšie spúšťať netreba.

## Okamžitý import

Tlačidlo **Importovať teraz** na karte *Záznam importov* spustí import ihneď, na pozadí — stránka
sa neblokuje. Stavový riadok sa priebežne aktualizuje a výsledok sa objaví v zázname, keď beh
skončí. Medzi manuálnymi spusteniami je ochranná pauza (predvolene 5 minút).

Import sa dá spustiť aj cez WP-CLI:

```bash
wp cron event run sita_xml_importer_cron
```

## Spoľahlivé spúšťanie (WP-Cron)

WordPress cron spúšťajú návštevy stránky. Na málo navštevovanom webe sa preto import môže oneskoriť
až do ďalšej návštevy.

Ak potrebujete presné načasovanie nezávislé od návštevnosti, vypnite WP-Cron v `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

a spúšťajte cron zo systému, napríklad každých 5 minút:

```
*/5 * * * * cd /cesta/k/webu && wp cron event run --due-now >/dev/null 2>&1
```

Tento spôsob má aj tú výhodu, že nepodlieha limitu na dĺžku behu PHP.

## Diagnostická správa

Na karte *Údržba* je tlačidlo na stiahnutie diagnostickej správy vo formáte Markdown — obsahuje
prostredie, stav databázy a schémy, kontroly a posledné chyby. Priložte ju, keď hlásite problém
cez [GitHub Issues](https://github.com/PayteR/sita-xml-importer/issues).
