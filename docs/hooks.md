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
