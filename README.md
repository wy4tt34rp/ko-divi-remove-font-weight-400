# KO: Divi font-weight=400px Fix

Version 2.0.0. Requires WordPress 6.2+ and PHP 7.4+.

Permanently removes inline `font-weight: 400` declarations from saved content. Run manually whenever needed, or enable a daily midnight cleanup. This replaces version 1.0.0's rendering filters; no content cleanup runs when a public page is rendered.

## Install and run

1. Upload `ko-divi-remove-font-weight-400-v2.0.0.zip` through Plugins > Add New > Upload Plugin. Replace the previous version if installed, then activate.
2. Open **Tools > KO: Font Weight Fix**.
3. Choose **Preview cleanup** to list affected records without changing content.
4. Choose **Permanently clean saved content**. Keep the screen open until complete.
5. Clear Divi's generated CSS and any page/CDN caches, then check the affected pages.

Repeat these steps whenever needed. Already-clean content is unchanged. The cleanup reads each record's current content; the preview is an estimate, not a frozen snapshot. Backups are saved before each record changes. Content edited concurrently is skipped.

## Optional midnight schedule

Scheduling is OFF by default. Check **Automatically clean saved content daily at midnight**, then **Save schedule**. The page shows the site's timezone, next start, and last scheduled result. Keep the plugin active to run scheduled cleanups.

Midnight uses the WordPress site's timezone. The next start is recalculated daily to follow daylight-saving changes. Updating the timezone in WordPress settings reschedules the next start. WP-Cron starts work on the next request after the scheduled time; a server cron is needed for precise timing or sites with WP-Cron disabled. Large cleanups continue in batches of 25 candidates, with subsequent batches queued at least 30 seconds apart and subject to WP-Cron traffic.

Scheduled cleanup uses the permissions of the administrator who enabled it. If that administrator loses access, cleanup stops and the Tools screen records the error. Save the schedule again as a current administrator to reauthorize it.

Turning the schedule off or deactivating the plugin cancels pending cleanup events. Saved content stays cleaned. Reactivating the plugin does not reenable scheduling automatically.

## Scope and preservation

- Processes `post_content` and `post_excerpt` in posts, pages, custom post types, and Divi Library / Theme Builder records, including drafts and private content the administrator can edit.
- Removes the declaration from every HTML tag type. Handles capitalization, whitespace, optional semicolons, and `!important`.
- Removes empty style attributes while preserving other styles, other font weights, attributes, superscript markup, and Divi shortcode wrappers.
- Excludes revisions, attachments, navigation items, custom CSS records, customization changesets, user-request records, oEmbed cache records, trash, and auto-drafts.
- Does not edit widgets/options, custom fields/post metadata, theme files, encoded HTML within JSON/shortcode attributes, or CSS stylesheets. Does not prevent editors from pasting the style again.
- No frontend filtering, JavaScript, output buffering, or save-time cleanup hooks. Admin code loads only in admin/cron contexts.

Updates use an exact-content conditional SQL write and clear the WordPress post object cache. They do not trigger ordinary post-save hooks, change modification dates, or create WordPress revisions. Full-page caches, Divi caches, and external/CDN caches are not automatically purged; existing cached pages remain until cache expiry or a manual purge, including after scheduled cleanup.

## Backups and restore

Original content/excerpts and the cleaned versions are stored in private, non-autoloaded WordPress options before each affected record is updated. Runs have separate backup identifiers. The Tools screen lists the most recent 100 runs; older backups remain in the database. Backup storage grows only for affected records plus small run-history entries.

Choose a backup and **Restore selected cleanup**. Restore only replaces a record if its current content and excerpt exactly match that run's cleaned version, protecting subsequent edits. Skip counts include later edits, already-restored records, and permission exclusions. Pause the schedule before restoring if you want the original styles to remain. Clear caches after restore.

Backups remain after deactivation or plugin deletion. Reinstall this plugin to access its restore screen. This record-level backup supplements regular site backups. No public backup files are written.

If a request fails, earlier batches may have completed. Preview again to locate remaining styles, or reload and restore from the affected run. A crashed request's mutual-exclusion lock expires after 15 minutes. A stuck scheduled run can be stopped between batches by disabling its schedule.

## Validation and handoff

2026-09-29: All 64 local regression checks passed. PHP syntax, JavaScript syntax, and ZIP integrity/source parity checks passed. Live WordPress/Divi installation, admin interaction, and cron execution remain unverified.

Local tests use WordPress 6.8.3's real HTML parser and hook dispatcher. Workflow tests use an in-memory SQLite adapter and stubbed WordPress site APIs to exercise conditional updates, backup failures, repeated runs, restore conflicts, batching, permissions, locks, DST boundaries, and scheduled cleanup. They are not a live WordPress/MySQL or Divi acceptance test.

Run with an extracted WordPress 6.8.3 source tree:

```sh
php tests/integration.php /path/to/wordpress
```

No site was installed, modified, or scheduled during development. Install and verify on the intended site before relying on the scheduled job.

Source: `public-plugin-repos/ko-divi-remove-font-weight-400`.
Release: `archived zips/ko-divi-remove-font-weight-400-v2.0.0.zip`.

References:
- https://developer.wordpress.org/reference/classes/wp_html_tag_processor/
- https://developer.wordpress.org/plugins/cron/
- https://developer.wordpress.org/reference/functions/wp_schedule_single_event/

## Changelog

- 2.0.0: Replaced render-time filtering with permanent, repeatable admin cleanup; added previews, per-run backups/restore, and optional midnight scheduling.
- 1.0.0: Original render-time cleanup, superseded by 2.0.0.
