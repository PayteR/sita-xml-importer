# Hooky pre vývojárov

Plugin ponúka akcie a filtre na prispôsobenie importu. Kód umiestnite do vlastného pluginu alebo
do `functions.php` vašej šablóny.

## Akcie

| Akcia | Kedy sa spustí |
|---|---|
| `sita_xml_importer_start` | Pred začiatkom spracovania importu. |
| `sita_xml_importer_inserted` | Po vytvorení nového príspevku. Dostáva ID príspevku. |
| `sita_xml_importer_end` | Po spracovaní všetkých článkov. Dostáva pole s výsledkami. |

Príklad — vlastná akcia po vytvorení príspevku:

```php
add_action( 'sita_xml_importer_inserted', function ( $post_id ) {
    update_post_meta( $post_id, 'moj_priznak', 'zo-sita' );
} );
```

## Filtre

### `sita_xml_importer_article`

Umožňuje upraviť údaje článku pred spracovaním. Vrátením `false` sa článok preskočí.

```php
add_filter( 'sita_xml_importer_article', function ( $article ) {
    if ( str_contains( $article['title'], 'NECHCEM' ) ) {
        return false;
    }
    return $article;
} );
```

### Sťahovanie obrázkov

#### `sita_xml_importer_image_accept_header`

Hlavička `Accept`, ktorú plugin posiela pri sťahovaní obrázka. Dostáva `( $accept, $url )`.

WordPress pri `download_url()` neposiela žiadny `Accept`, takže CDN, ktoré vyberá formát obrázka podľa tejto hlavičky, môže na adresu končiacu `.webp` vrátiť JPEG. WordPress potom súbor odmietne, lebo prípona nesedí so skutočným typom, a v zázname sa objaví „Sorry, you are not allowed to upload this file type".

Hlavička sa pridáva len na túto jednu požiadavku, nie globálne. Ide o prevenciu — nezávisle od nej plugin pred uložením ešte porovná skutočný obsah súboru s príponou a v prípade nezhody príponu opraví, takže obrázok sa uloží aj vtedy, keď CDN hlavičku ignoruje alebo keď kanál pošle adresu s nesprávnou príponou.

Vrátením `false` sa hlavička vypne úplne:

```php
add_filter( 'sita_xml_importer_image_accept_header', '__return_false' );
```

#### `sita_xml_importer_relax_upload_mimes`

Predvolene `true`. Na multisite sa povolené typy súborov prienikom obmedzujú sieťovou voľbou `upload_filetypes`, ktorej predvolená hodnota (`jpg jpeg png gif`) je staršia než podpora WebP (WordPress 5.8) a AVIF (6.5) v jadre. Plugin preto počas vlastného ukladania obrázka dočasne povolí tie formáty, ktoré jadro aj tak podporuje.

Vypnutie uvoľnenia:

```php
add_filter( 'sita_xml_importer_relax_upload_mimes', '__return_false' );
```

#### `sita_xml_importer_add_network_upload_filetypes`

Predvolene `true`. Na multisite plugin pri aktivácii a pri aktualizácii doplní `webp` a `avif` do sieťovej voľby `upload_filetypes`, ak tam chýbajú — aby tieto formáty mohli nahrávať aj redaktori ručne, nielen importér. Pridávajú sa výhradne formáty, ktoré WordPress sám podporuje; nič iné sa nemení.

Zapíše sa to len vtedy, keď plugin aktivuje alebo aktualizuje superadmin, teda niekto s právom meniť sieťové nastavenia. Vypnutie:

```php
add_filter( 'sita_xml_importer_add_network_upload_filetypes', '__return_false' );
```

### Ostatné filtre

| Filter | Popis | Predvolené |
|---|---|---|
| `sita_xml_importer_time_budget` | Počet sekúnd, ktoré smie jeden prechod stráviť ukladaním, kým sa preruší a pokračuje ďalším behom na pozadí. Dostáva `( $seconds, $php_limit )`. | — |
| `sita_xml_importer_max_passes` | Strop pre počet nadväzujúcich prechodov. | 20 |
| `sita_xml_importer_log_max_rows` | Tvrdý strop pre počet uchovaných riadkov záznamu. | 20000 |
| `sita_xml_importer_manual_cooldown` | Počet sekúnd medzi povolenými manuálnymi spusteniami („Importovať teraz"). | 300 |
| `sita_xml_importer_migration_batch_size` | Počet príspevkov migrovaných v jednej dávke na pozadí. | 2000 |
| `sita_xml_importer_cleanup_batch_size` | Počet starých meta riadkov zmazaných v jednej dávke. | 5000 |
| `sita_xml_importer_cleanup_grace_days` | Počet dní, počas ktorých sa migrované staré meta údaje ešte držia ako záloha, než sa automaticky zmažú. `0` vypne automatické čistenie. | 30 |

Príklad — predĺženie ochrannej pauzy medzi manuálnymi importmi na 10 minút:

```php
add_filter( 'sita_xml_importer_manual_cooldown', function () {
    return 10 * MINUTE_IN_SECONDS;
} );
```
