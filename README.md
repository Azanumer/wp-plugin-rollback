# WP Plugin Rollback

Roll back any WordPress.org plugin to a previous version in one click — for when a plugin update breaks your site and you need the old version back *now*.

## How it works

1. Go to **Tools → Plugin Rollback**.
2. Pick a plugin and an older version from the dropdown.
3. Click **Roll back**. The plugin:
   - downloads the chosen release from WordPress.org,
   - verifies the package contains the plugin's main file,
   - **backs up the current version** to `wp-content/wpr-backups/<slug>-<version>-<timestamp>/`,
   - deactivates the plugin, swaps the files, and re-activates it if it was active.

Nothing is deleted before the new package has been downloaded and verified, so a failed download or a bad package leaves your site untouched.

## Features

- **Version list from WordPress.org** — every published release, cached for 12 hours
- **Automatic backup** — the version you roll back *from* is kept under `wp-content/wpr-backups/`
- **Safe swap** — download → verify → backup → deactivate → replace → reactivate, in that order
- **Staging inside the plugins folder** — the new version is unzipped to a staging folder on the same filesystem, so the final move is a single atomic rename
- **Only older versions offered** — the dropdown never lists the installed version or newer releases

## Limitations

- Only plugins hosted on WordPress.org can be rolled back (the version list comes from the WordPress.org API).
- Single-file plugins (no folder) and premium plugins are not listed.
- Rollbacks are for the plugin *files* only — database changes made by the newer version are not reverted. If a bad update changed your data, restore a database backup too.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- The `install_plugins` capability (Administrator by default)

## License

MIT — see [LICENSE](LICENSE).
