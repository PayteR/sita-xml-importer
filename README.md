# SITA XML Importer

Imports news articles from [SITA](https://sita.sk) (Slovenská tlačová agentúra / Slovak News
Agency) XML feeds into WordPress. Creates posts with categories and featured images on a
schedule, with an on-demand trigger and a per-run import log.

**WordPress.org:** https://wordpress.org/plugins/sita-xml-importer/

> Active access to SITA's XML feeds is required. To request access, contact SITA's sales team
> at obchod@sita.sk. For technical support, webmaster@sita.sk.

## Issues and contributions

Bug reports and feature requests are welcome — please [open an issue](../../issues).

Note that this repository is a **published mirror** of SITA's internal development repository.
Code is pushed here automatically on release. Pull requests are read and appreciated, but they
are ported into the internal repository by hand rather than merged directly, so please open an
issue first for anything substantial.

## Releases

Releases are automated. Pushing a version tag (for example `2.1.3`) publishes that version to
the WordPress.org plugin directory via the `10up/action-wordpress-plugin-deploy` action.

Files in `.wordpress-org/` (icon, banner, screenshots) are directory assets and are published
separately — they are not part of the downloadable plugin.

## Requirements

- WordPress 5.9 or newer
- PHP 7.4 or newer

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
