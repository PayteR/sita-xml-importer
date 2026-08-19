# Ukladanie dát a migrácia zo starého pluginu

## Vlastné tabuľky

Sledovanie článkov sa ukladá do dvoch vlastných tabuliek, nie do post meta:

| Tabuľka | Účel |
|---|---|
| `{prefix}sita_xml_importer_article` | Mapuje každý článok SITA na príspevok WordPressu. Slúži ako index na rozpoznávanie duplicít. |
| `{prefix}sita_xml_importer_log` | Záznam o každom behu importu (vytvorené / aktualizované / preskočené / chyby). |

Indexovaná tabuľka je dôvod, prečo import zostáva rýchly aj pri desiatkach tisíc článkov —
pri post meta by kontrola duplicít postupne spomaľovala celý web.

## Prechod zo starého pluginu `sita-parser-xml`

Plugin je nástupcom staršieho pluginu „SITA XML parser správ". Prechod je navrhnutý tak, aby
prebehol bez výpadku:

1. **Pri aktivácii** sa automaticky prenesú nastavenia a starý plugin sa deaktivuje.
2. **Kontrola duplicít funguje okamžite** — plugin dovtedy číta staré post meta
   (`_w_id`, `_w_t_p`, `_w_t_m`), takže sa nič neimportuje dvakrát.
3. **Migráciu spustíte raz** na karte **Nastavenia → SITA XML Importer → Údržba**. Beží na pozadí
   v dávkach a postupne naplní indexovanú tabuľku.
4. **Staré meta údaje sa ponechajú ako záloha** a automaticky sa zmažú po ochrannej lehote
   (predvolene 30 dní). Zmazať ich môžete aj ihneď na karte *Údržba*.

Veľkosť dávok a ochrannú lehotu viete zmeniť filtrami — pozri
[hooky pre vývojárov](hooks.md#ostatné-filtre):

- `sita_xml_importer_migration_batch_size`
- `sita_xml_importer_cleanup_batch_size`
- `sita_xml_importer_cleanup_grace_days`

## Odinštalovanie

Pri odinštalovaní pluginu (nie pri obyčajnej deaktivácii) sa odstránia nastavenia a vlastné
tabuľky pluginu. **Importované príspevky a obrázky v knižnici médií zostávajú zachované** — sú to
bežné príspevky WordPressu.
