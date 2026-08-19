=== SITA XML Importer ===
Contributors: payter
Tags: sita, xml, importer, news, feed
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Import news articles from SITA (Slovak News Agency) XML feeds into WordPress.

== Description ==

SITA XML Importer automatically imports news articles from SITA (Slovak News Agency / Slovenská tlačová agentúra) XML feeds into your WordPress site. SITA publishes its news at sita.sk (formerly webnoviny.sk).

**Active access to SITA's XML feeds is required to use this plugin.** The feeds are a service provided by SITA. To request access, contact SITA's sales team at obchod@sita.sk. For technical help, contact webmaster@sita.sk.

It is the successor to the older "SITA XML parser správ" (sita-parser-xml) plugin. If you are upgrading from it, your settings and already-imported articles carry over automatically.

= Features =

* Automatic scheduled import via WordPress cron (every 30 minutes by default, configurable)
* "Import now" button for an on-demand run in the background (with a cooldown)
* Per-run import log (created / updated / skipped / errors) with CSV export
* Creates posts with title, excerpt, content, and featured images
* Automatic category creation from feed section/subsection data
* Configurable post type, status, and author
* HTML tag filtering for imported content
* No duplicates - each article is imported once and only updated when it changes at the source
* Reliable on shared hosting and with large feeds
* Developer hooks for customization

= Import log & on-demand import =

Every import is recorded in an activity log showing how many articles were created, updated or skipped, and any errors. You can export the log to CSV, and the "Import now" button runs an import immediately in the background without blocking the page - the result appears in the log when it finishes.

= Expected XML Format =

The plugin parses SITA's XML format. Each article is wrapped in a `<Sprava>` element containing:

* `ID` - Article identifier
* `UnikatneID` - Unique identifier
* `DatumVydania` / `CasVydania` - Publish date/time
* `DatumAktualizacie` / `CasAktualizacie` - Last modified date/time
* `Nadpis` - Title
* `Perex` - Excerpt
* `TextContent` - Full article body (HTML)
* `Sekcia > Rubrika` - Main category
* `Sekcia > Podrubrika` - Subcategory
* `Lokality > Lokalita` - Locations
* `Obrazok > ObrazokLinka` - Featured image URL
* `Obrazok > VelkostSuboru`, `ObrazokVyska`, `ObrazokSirka` - Image metadata

Articles without a featured image (`ObrazokLinka`) are skipped.

= Developer Hooks =

**Actions:**

* `sita_xml_importer_start` - Fires before import processing begins.
* `sita_xml_importer_inserted` - Fires when a new post is created. Receives post ID.
* `sita_xml_importer_end` - Fires after all articles are processed. Receives results array.

**Filters:**

* `sita_xml_importer_article` - Filter individual article data before processing. Return `false` to skip the article.
* `sita_xml_importer_time_budget` - Seconds a single pass may spend saving before it yields and continues in a follow-up background pass. Receives `( $seconds, $php_limit )`.
* `sita_xml_importer_max_passes` - Cap on back-to-back continuation passes (default 20).
* `sita_xml_importer_log_max_rows` - Hard cap on retained log rows (default 20000).
* `sita_xml_importer_manual_cooldown` - Seconds between allowed "Import now" runs (default 300).
* `sita_xml_importer_migration_batch_size` - Posts migrated per background batch (default 2000).
* `sita_xml_importer_cleanup_batch_size` - Legacy meta rows deleted per cleanup batch (default 5000).
* `sita_xml_importer_cleanup_grace_days` - Days the migrated legacy meta is kept as a backup before it is deleted automatically (default 30; set to 0 to disable auto-cleanup and remove it manually only).

= Data storage =

Article tracking is stored in two custom tables (`{prefix}sita_xml_importer_article` and `{prefix}sita_xml_importer_log`) instead of post meta. The article table maps each SITA article to its WordPress post and is the duplicate-detection index. Installations upgrading from the legacy plugin keep their old `_w_id` / `_w_t_p` / `_w_t_m` post meta until you run the built-in migration (Settings > SITA XML Importer > Maintenance), which backfills the table in the background. The old meta is then kept as a backup and removed automatically after a grace period (30 days by default), or immediately via the Maintenance tab.

== External Services ==

This plugin imports content from SITA (Slovak News Agency / Slovenská tlačová agentúra) XML feeds. It connects to external endpoints that **you** configure in the plugin settings. It does not connect to any service until you enter at least one feed URL.

**What it connects to and when:**

* **SITA XML feed(s)** - On each scheduled run the plugin sends an HTTP GET request to every feed URL you enter under Settings > SITA XML Importer. The request carries only the URL you provided (which may include an access token issued to you by SITA); no data from your WordPress site is transmitted.
* **Article images** - For each imported article that references an image, the plugin downloads that image from the image URL contained inside the feed and stores it in your Media Library.

Access to SITA's XML feeds is a service provided by SITA. To request feed access, contact SITA's sales team at obchod@sita.sk. For technical support with the feeds or this plugin, contact webmaster@sita.sk.

Service provider: SITA Slovenská tlačová agentúra a.s. - https://sita.sk (formerly webnoviny.sk)
Terms of service and privacy policy: https://sita.sk

== Installation ==

1. Upload the `sita-xml-importer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Go to Settings > SITA XML Importer.
4. Enter your SITA XML feed URL(s), one per line.
5. Configure the post author, post type, status, and category options.
6. Save settings - the importer runs automatically on a schedule (every 30 minutes by default).

To obtain XML feed access, contact SITA's sales team at obchod@sita.sk. For technical support, contact webmaster@sita.sk.

== Frequently Asked Questions ==

= Do I need anything from SITA to use this plugin? =

Yes. You need active access to SITA's XML feeds - the plugin has nothing to import without it. To request access, contact SITA's sales team at obchod@sita.sk. For technical support with the feeds or the plugin, contact webmaster@sita.sk.

= How often does the import run? =

Every 30 minutes by default, configurable to 15 or 30 minutes or hourly under Settings > SITA XML Importer. Because WordPress cron (WP-Cron) is triggered by site visits, the actual timing depends on traffic: on a busy site it fires close to the chosen interval, on a quiet site it can lag until the next visit. For exact, traffic-independent timing, disable WP-Cron (`define('DISABLE_WP_CRON', true);`) and run WordPress cron from a real system cron / WP-CLI (which also has no PHP execution-time limit).

You can also click "Import now" on the settings screen for an on-demand background run, or trigger it from WP-CLI:

`wp cron event run sita_xml_importer_cron`

= Why does "Import now" not finish instantly? =

The importer runs in the background so it never blocks the admin page. The status line updates live and the result appears in the import log when the run finishes. Large first-time imports are processed in several automatic passes.

= I'm upgrading from the old sita-parser-xml plugin. What happens to my data? =

Your settings are migrated automatically on activation, and duplicate detection keeps working immediately (it reads the old post meta until migrated). Run the "Legacy data migration" once from the settings screen to move tracking data into the indexed tables; an optional cleanup then removes the old post meta.

= Can I skip certain articles? =

Yes, use the `sita_xml_importer_article` filter:

`add_filter('sita_xml_importer_article', function($article) {
    if (str_contains($article['title'], 'UNWANTED')) {
        return false;
    }
    return $article;
});`

= Why are some articles not imported? =

Articles without a featured image (`ObrazokLinka` element) are skipped by design.

== Screenshots ==

1. Settings - configure your SITA feed URLs, import frequency, post author, status and category handling.
2. Import log - the result of every run (created, updated, skipped, errors), with an "Import now" button and CSV export.
3. Maintenance - one-click migration from the old sita-parser-xml plugin and automatic cleanup of legacy data.

== Changelog ==

= 2.1.3 =

An "Overwrite existing articles" re-import no longer rewrites an image's caption and credit when they already match the feed, so repeated overwrites of unchanged images do no extra database writes.

= 2.1.2 =

Re-importing an article with "Overwrite existing articles" now also refreshes the featured image's caption and photo credit from the feed. Previously these were only set the first time an image was downloaded, so an overwrite left the old values in place.

= 2.1.0 =

Featured images now use the caption and photo credit from the feed when the SITA feed provides them (the `<ObrazokTitulok>` and `<ObrazokZdroj>` elements), instead of whatever caption is embedded in the image file's IPTC/EXIF metadata. Older feeds without these elements are unaffected.

= 2.0.0 =

First public release on WordPress.org. This is the successor to the older "SITA XML parser správ" (sita-parser-xml) plugin. Existing installations upgrade seamlessly: your settings and imported articles are carried over automatically, the old plugin is deactivated, and the Maintenance screen prompts you to remove it.

* Configurable import frequency (every 15 or 30 minutes, or hourly; default 30 minutes), plus an "Import now" button for an on-demand run.
* Activity log of every import (created / updated / skipped / errors) with CSV export.
* Downloadable diagnostic report to share with support when something needs looking at.
* Reliable on shared hosting and with large feeds; a post is only updated when its source article changes.
* Settings / Import log / Maintenance tabbed admin screen.
* Slovak and Czech translations.
