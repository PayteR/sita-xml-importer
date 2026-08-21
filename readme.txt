=== SITA XML Importer ===
Contributors: sitask, payter
Tags: sita, xml, importer, news, feed
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.1.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatický import spravodajských článkov z XML kanálov agentúry SITA do WordPressu.

== Description ==

Plugin automaticky sťahuje spravodajské články z XML kanálov agentúry SITA (Slovenská tlačová agentúra) a vytvára z nich príspevky vo vašom WordPresse - vrátane kategórií a titulných obrázkov. Import beží pravidelne na pozadí, k dispozícii je aj tlačidlo na okamžitý import a záznam o každom behu.

= Potrebujete prístup ku kanálom SITA =

**Plugin sám o sebe nemá čo importovať - potrebujete aktívny prístup k XML kanálom agentúry SITA.** Ide o platenú službu agentúry SITA.

* **Záujem o prístup:** obchod@sita.sk
* **Ďalšie kontakty:** https://biz.sita.sk/#kontakty

= Technické problémy a hlásenie chýb =

Zdrojový kód, dokumentáciu a hlásenie chýb nájdete na GitHube:
https://github.com/PayteR/sita-xml-importer

Chybu alebo návrh nahláste cez GitHub Issues:
https://github.com/PayteR/sita-xml-importer/issues

= Čo plugin vie =

* Pravidelný automatický import (predvolene každých 30 minút, nastaviteľné)
* Tlačidlo "Importovať teraz" pre okamžitý import na pozadí
* Záznam o každom behu (vytvorené / aktualizované / preskočené / chyby) s exportom do CSV
* Vytvára príspevky s nadpisom, perexom, obsahom a titulným obrázkom
* Automatické vytváranie kategórií podľa rubrík z kanála
* Nastaviteľný typ príspevku, stav a autor
* Bez duplicít - článok sa importuje raz a aktualizuje sa len pri zmene v zdroji
* Spoľahlivý aj na zdieľanom hostingu a pri veľkých kanáloch
* Hooky pre vývojárov

Plugin je nástupcom staršieho pluginu "SITA XML parser správ" (sita-parser-xml). Pri prechode sa nastavenia aj už importované články prenesú automaticky.

== External Services ==

Tento plugin importuje obsah z XML kanálov agentúry SITA (Slovenská tlačová agentúra). Pripája sa na externé adresy, ktoré **si sami** nastavíte v nastaveniach pluginu. Kým nezadáte aspoň jednu adresu kanála, plugin sa nepripája nikam.

**Na čo a kedy sa pripája:**

* **XML kanál(y) SITA** - pri každom naplánovanom behu plugin odošle HTTP GET požiadavku na každú adresu kanála, ktorú ste zadali v Nastavenia > SITA XML Importer. Požiadavka obsahuje iba vami zadanú adresu (tá môže obsahovať prístupový token vydaný agentúrou SITA); žiadne údaje z vášho WordPressu sa neodosielajú.
* **Obrázky článkov** - pri každom importovanom článku, ktorý obsahuje obrázok, plugin stiahne tento obrázok z adresy uvedenej v kanáli a uloží ho do vašej knižnice médií.

Prístup k XML kanálom SITA je služba poskytovaná agentúrou SITA. Záujem o prístup: obchod@sita.sk, ďalšie kontakty na https://biz.sita.sk/#kontakty.

Poskytovateľ služby: SITA Slovenská tlačová agentúra a.s. - https://sita.sk (predtým webnoviny.sk)
Podmienky používania a ochrana súkromia: https://sita.sk

== Installation ==

1. Nahrajte priečinok `sita-xml-importer` do `/wp-content/plugins/`, alebo plugin nainštalujte priamo z adresára pluginov WordPressu.
2. Aktivujte plugin v menu Pluginy.
3. Prejdite do Nastavenia > SITA XML Importer.
4. Zadajte adresu (adresy) vášho XML kanála SITA, každú na samostatný riadok.
5. Nastavte autora príspevkov, typ príspevku, stav a prácu s kategóriami.
6. Uložte nastavenia - import odteraz beží automaticky (predvolene každých 30 minút).

Prístup ku kanálom vybavíte na obchod@sita.sk, ďalšie kontakty nájdete na https://biz.sita.sk/#kontakty.

Podrobná dokumentácia (formát XML, hooky pre vývojárov, ukladanie dát a migrácia):
https://github.com/PayteR/sita-xml-importer/tree/main/docs

== Frequently Asked Questions ==

= Potrebujem niečo od agentúry SITA? =

Áno. Potrebujete aktívny prístup k XML kanálom SITA, inak plugin nemá čo importovať. Záujem o prístup: obchod@sita.sk, ďalšie kontakty na https://biz.sita.sk/#kontakty.

= Ako často import beží? =

Predvolene každých 30 minút, nastaviteľné na 15 minút, 30 minút alebo hodinu. Keďže WordPress cron (WP-Cron) spúšťajú návštevy stránky, reálne načasovanie závisí od návštevnosti. Presné a od návštevnosti nezávislé spúšťanie dosiahnete vypnutím WP-Cronu (`define('DISABLE_WP_CRON', true);`) a spúšťaním cronu zo systému.

= Kde nahlásim chybu alebo technický problém? =

Cez GitHub Issues: https://github.com/PayteR/sita-xml-importer/issues

= Prečo sa niektoré články neimportovali? =

Články bez titulného obrázka sa zámerne preskakujú. Podrobnosti nájdete v dokumentácii na GitHube.

= Prechádzam zo starého pluginu sita-parser-xml. Čo sa stane s dátami? =

Nastavenia sa prenesú automaticky pri aktivácii a kontrola duplicít funguje okamžite. Na karte Údržba potom raz spustite migráciu údajov.

== Screenshots ==

1. Nastavenia - adresy XML kanálov, frekvencia importu, autor, stav príspevkov a práca s kategóriami.
2. Záznam importov - výsledok každého behu (vytvorené, aktualizované, preskočené, chyby), tlačidlo "Importovať teraz" a export do CSV.
3. Údržba - migrácia zo starého pluginu sita-parser-xml a automatické čistenie starých údajov.

== Changelog ==

= 2.1.4 =

Interná úprava bez zmeny správania: spracovanie XML kanála sa presunulo do samostatnej triedy nezávislej od WordPressu. Uľahčuje to testovanie a budúce použitie mimo WordPressu. Import, rozpoznávanie duplicít ani práca s obrázkami sa nemenia.

= 2.1.3 =

Opakovaný import s voľbou "Prepísať existujúce články" už neprepisuje popis a zdroj obrázka, ak sa zhodujú s kanálom - opakované prepisy nezmenených obrázkov tak nerobia zbytočné zápisy do databázy.

= 2.1.2 =

Opakovaný import s voľbou "Prepísať existujúce články" po novom aktualizuje aj popis a fotokredit titulného obrázka z kanála. Predtým sa nastavili len pri prvom stiahnutí obrázka.

= 2.1.0 =

Titulné obrázky po novom používajú popis a fotokredit z kanála SITA (prvky `<ObrazokTitulok>` a `<ObrazokZdroj>`), ak ich kanál poskytuje, namiesto popisu z IPTC/EXIF metadát súboru. Staršie kanály bez týchto prvkov to neovplyvní.

= 2.0.0 =

Prvé verejné vydanie na WordPress.org. Nástupca staršieho pluginu "SITA XML parser správ" (sita-parser-xml). Existujúce inštalácie prejdú bez zásahu: nastavenia aj importované články sa prenesú, starý plugin sa deaktivuje.

* Nastaviteľná frekvencia importu (15 minút, 30 minút alebo hodina) a tlačidlo "Importovať teraz".
* Záznam o každom importe (vytvorené / aktualizované / preskočené / chyby) s exportom do CSV.
* Stiahnuteľná diagnostická správa pre podporu.
* Spoľahlivosť na zdieľanom hostingu a pri veľkých kanáloch; príspevok sa aktualizuje len pri zmene v zdroji.
* Prehľadné rozhranie s kartami Nastavenia / Záznam importov / Údržba.
* Slovenský a český preklad.
