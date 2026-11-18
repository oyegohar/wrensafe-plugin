=== Wrensafe – WP Backup, Restore & Migration ===
Contributors: oyegohar
Tags: backup, restore, migration, staging, clone
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore and migrate WordPress with confidence: full or partial backups, encrypted archives, schedules, staging and guided restores.

== Description ==

Wrensafe backs up your WordPress site and restores it — on the same server or on a completely different one — without guesswork. Every backup is a single `.wbp` archive with its own manifest and checksums, every restore starts with an integrity check, and the dashboard tells you plainly whether your site is protected.

### Back up

* **Full site or just what you need:** database, files, media, themes, plugins, or everything.
* **AES-256 encryption** (optional, per backup). The password is never stored.
* **Automatic backups** every day, week or month at the time you choose, keeping only the newest copies — plus an automatic safety backup before WordPress, plugin or theme updates.
* **No size limits:** multi-gigabyte sites back up, download, upload and restore. Interrupted downloads and uploads resume where they stopped.
* **Works on shared hosting:** backups and restores run in small, time-limited steps, so they finish even where PHP may only run for 30 seconds.
* **Smart exclusions:** other plugins' backup archives and cache folders are skipped by default; plugin and theme code is always backed up completely.

### Restore safely

* **Integrity first:** the archive, manifest, database and file checksums are verified before anything changes.
* **Rollback points:** a safety backup is created automatically before every restore, so a restore can be undone.
* **Atomic database swap:** the database is rebuilt in temporary tables and switched over in one step, so your site keeps working during the import.
* **Restore what you need:** the whole site, only the database, only the files, or just the media, themes or plugins.
* **Your server settings stay yours:** `wp-config.php` is never put in a backup or overwritten, so your database password and security keys stay on the server. Wrensafe itself is never overwritten, and server-specific files (`.htaccess`, `.user.ini`, cache drop-ins) are kept when you move a site to another server.
* **Plugin safety:** after a restore, Wrensafe loads the site once. Any plugin that is incomplete, needs a newer PHP or WordPress than this server has, or stops the site from loading is turned off instead of breaking the site, and you're told which one and why.
* **WooCommerce Safe Mode** keeps new orders, customers and stock untouched while the rest of the store is restored.

### Migrate

* **Move a site to a new domain or server:** upload a `.wbp` archive on the new site; URLs and file paths are rewritten safely, including inside serialized data and page-builder JSON. Different table prefixes are handled automatically.
* **Direct Migration:** send a backup straight to another WordPress site using a one-time migration key — no download or upload. It arrives in that site's backup list, ready to review and restore.
* **Migration Assistant:** scans your server limits, database and files, and recommends the safest way to migrate.
* **Staging:** create a password-protected copy of your site, test changes, then push them to live.

### Switch from another backup plugin

* **Restore backups made by other plugins:** upload a backup from All-in-One WP Migration (`.wpress`), UpdraftPlus, Duplicator, WPvivid, BackWPup, Backup Migration, Total Upkeep, Solid Backups (BackupBuddy), WP Migrate or Jetpack VaultPress Backup under **Import / Restore → Other Backup Plugins**. Wrensafe reads it, shows what's inside and restores it with the same checks as its own backups. The other plugin doesn't need to be installed.
* **Any other backup:** a `.sql` database dump and/or a `.zip` or `.tar` of the `wp-content` folder works too, for example from your hosting control panel.
* Backups split over several files (UpdraftPlus, WPvivid) are uploaded together; each file resumes if the connection drops.

### Stay informed

* **Dashboard:** protection status, recent backups, schedules and migration readiness at a glance.
* **Activity Logs** that record every step with the exact reason when something fails (passwords and keys are never logged), plus a one-click debug bundle for support.
* **Email notifications** when a backup or restore fails, and, if you like, when one completes.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New Plugin**, search for **Wrensafe**, then select **Install Now** and **Activate**. (Or upload the `wrensafe` folder to `/wp-content/plugins/` and activate it on the **Plugins** screen.)
2. Open **Wrensafe** in the admin menu.
3. Click **Create backup** to make your first full backup.
4. Optional: turn on automatic backups under **Scheduled Backups**.

To move a site: create a full backup on the old site, download it from the **Backup Browser**, install Wrensafe on the new site and upload the file under **Import / Restore**.

== Frequently Asked Questions ==

= Where are my backups stored? =

On your own server, in `wp-content/wrensafe-storage/` by default. You can choose another folder under **Settings → Backup → Backup folder**; a folder outside the website folder is safest, and existing backups move with it. Folders inside the website are protected from direct web access. Download a copy now and then and keep it somewhere safe, away from the server.

= Is wp-config.php in my backups? =

No. `wp-config.php` holds your database password and the security keys and salts that sign logins, so it is never put in a backup, downloaded or sent to another site. The keys WordPress can keep in the database are left out too. A restore always keeps the destination site's own `wp-config.php` and keys.

= My site keeps wp-content (or plugins, or uploads) in another folder. Is that backed up? =

Yes. Wrensafe asks WordPress where each folder is, so content, plugins, must-use plugins, themes and uploads are backed up wherever they are, and restored into the matching folders of the destination site, even when its layout is different.

= What is a .wbp file? =

Wrensafe's backup archive. It is a ZIP container (TAR on servers without the ZIP extension) with a manifest, metadata, per-table database files and checksums, so a restore can check every part before using it. Encrypted backups are wrapped in AES-256.

= Can I restore a backup onto a different site or domain? =

Yes. Upload the `.wbp` file under **Import / Restore** on the new site. Wrensafe keeps the new site's own domain, `wp-config.php` and server files, and rewrites the old URLs and paths in the database for you.

= Can I restore a backup made by another backup plugin? =

Yes, without installing that plugin. Go to **Import / Restore → Other Backup Plugins** and upload the backup files:

* All-in-One WP Migration: the `.wpress` file.
* UpdraftPlus: every file of one backup (the `-db.gz` file and the `-plugins`, `-themes`, `-uploads` and `-others` zips).
* Duplicator: the `_archive.zip` or `_archive.daf` file (`installer.php` isn't needed).
* WPvivid: the `_backup_all.zip` file, or every part of one backup.
* BackWPup: the `.zip`, `.tar` or `.tar.gz` archive.
* Backup Migration: the `BM_….zip` file.
* Total Upkeep: the `boldgrid-backup-….zip` file of a full backup.
* Solid Backups (BackupBuddy): the `.zip` file of a full backup.
* WP Migrate: a full-site export `.zip`, or a database export (`.sql` or `.sql.gz`).
* Jetpack VaultPress Backup: the `.tar.gz` backup download.

Password-protected backups from other plugins can't be read; make an unencrypted backup in that plugin first. WordPress core files are not restored (this site keeps its own), only `wp-content` and the database.

= Will restoring log me out? =

A database restore replaces the users table with the one from the backup, so you will be asked to log in again at the end — with the usernames and passwords from the site the backup came from.

= Does it work on shared hosting? =

Yes. Backups, restores, uploads and verification all run in short, time-limited steps, so they complete even where PHP may only run for 30 seconds.

= Can it handle large sites? =

Yes. There is no size limit. A 2.4 GB site (25,000 files, 30,000 posts) was backed up, downloaded, uploaded to another site and restored on a server limited to 30-second requests and 8 MB uploads, with every file and database row checked afterwards. You need free disk space about equal to your site's size to create a backup, and about one and a half times the backup's size to restore one. Interrupted downloads and uploads resume where they stopped.

= Is encryption optional? =

Yes. Turn it on per backup. The password is never stored or logged — keep it safe, because a lost password cannot be recovered.

= Does it work with WooCommerce? =

Yes. WooCommerce Safe Mode protects orders, customers and stock during a restore, and the checkout is paused only while the store's data is being replaced.

= Does it support multisite? =

Not yet. Wrensafe is built and tested for single-site WordPress. Restoring a multisite database is blocked to protect the network.

= What happens when I uninstall the plugin? =

Nothing is deleted unless you turn on **Settings → Advanced → Uninstall**. Even then, only Wrensafe's settings and records are removed from the database — your backup files are always kept.

= What are the minimum requirements? =

WordPress 6.0 or later and PHP 7.4 or later (PHP 8.3 or newer is recommended). Tested up to WordPress 7.1 and PHP 8.4. The PHP ZIP extension is recommended.

== Privacy ==

Wrensafe stores backups on your own server and never sends them, or any usage data, to Wrensafe or anyone else. Data leaves your site only when an administrator starts one of the actions listed under External services. Backups can contain personal data (user accounts, orders, form entries), so download and store them as carefully as the site itself.

== External services ==

Wrensafe only contacts the services below, and only after a site administrator configures or starts the related feature. Nothing is sent on activation, and no usage data or tracking is collected.

* **WordPress.org core checksums API** (api.wordpress.org) — when an administrator runs the Malware Scanner, to compare WordPress core files with the official checksums. Data sent: the site's WordPress version. [Privacy Policy](https://wordpress.org/about/privacy/).
* **Import from URL** — downloads a backup archive from the URL the administrator enters on the Import / Restore page.
* **Direct Migration** — sends backup data to the other WordPress site the administrator pairs with a one-time migration key.

== Screenshots ==

1. Dashboard: see at a glance whether your site is backed up, plus recent backups, schedules and migration readiness.
2. Back up the whole site, or only the database, files, media, themes or plugins, with optional AES-256 encryption.
3. Every backup shows live, step-by-step progress and can be cancelled safely.
4. Backup Browser: restore, download, verify, lock, tag and delete the backups stored on your server.
5. Review before you restore: where the backup comes from, what's inside, what will change, and which parts to restore.
6. Integrity verification checks the archive, manifest, database files and checksums before you restore.
7. Automatic backups: every day, week or month at the time you choose, keeping only the newest copies.
8. Migration Assistant scores how ready the site is to move, recommends the safest method and lists the next steps.
9. Settings grouped by task: the backup folder, backups before updates, exclusions, notifications, migration and WooCommerce Safe Mode.
10. Restore backups made by other backup plugins: upload the files just as that plugin saved them.

== Changelog ==

= 1.0.3 =
* Fixed: some hosting malware scanners (SiteGround's among them) reported a file of the security scan as malicious, because it listed the PHP function names that malware uses, and could suspend the site. The security scan no longer keeps such a list; it now looks for long encoded payloads in PHP files instead. Nothing in the plugin matches common malware signatures (checked with ClamAV, Linux Malware Detect and PHP Malware Finder).
* Security: the staging site password is no longer stored in the database (only its hash, in the staging site's `.htpasswd`), and passwords kept by earlier versions are removed. The hash now uses a random salt.
* Security: settings read from a backup during a restore can't create PHP objects.
* Changed: an import from a URL stops downloading at the size limit instead of after the whole file.

= 1.0.2 =
* New name: Wrensafe – WP Backup, Restore & Migration (slug `wrensafe`). Settings, backups and the backup list carry over, and the backup folder moves to `wp-content/wrensafe-storage/` automatically.
* Fixed: after a site had no visitors for a few days, automatic backups stayed "overdue" and ran again on each check until they caught up. Missed runs are now skipped, and daily, weekly and monthly backups always run at the time you set.

= 1.0.1 =
* Security: `wp-config.php` (and any copy of it) is no longer included in backups, so the database password and the authentication keys and salts are never stored in a backup, downloaded or sent by Direct Migration. The keys and salts WordPress can keep in the database are left out of backups too, and a restore keeps the destination site's own.
* Security: staging sites get their own new authentication keys and salts.
* Fixed: when `wp-config.php` sets the table prefix through a function (for example Docker's `getenv_docker()`), a staging site ran on the live site's database tables. The staging copy now always gets its own prefix, or is not created.
* Fixed: sites that keep wp-content, plugins, must-use plugins, themes or uploads outside the WordPress folder are now backed up completely, and every file is restored into the matching folder of the destination site, whatever its layout.
* Fixed: after restoring a backup from another site, the URL and path rewrite also changed Wrensafe's own backup list, so existing backups could point at folders that don't exist. Only the restored tables are rewritten now, and the WordPress folder path is matched as a whole folder.
* Changed: every folder location comes from WordPress (`WP_PLUGIN_DIR`, `WPMU_PLUGIN_DIR`, `get_theme_root()`, `wp_upload_dir()`, `WPINC`) instead of assumed paths.
* Fixed: staging copies skipped plugin folders named "cache" (only the page cache folder is skipped now), and a new staging site no longer includes copies of other staging sites. The Staging page explains when a site's folder layout can't be copied.
* Changed: Settings and Import / Restore name the site's real content folder instead of assuming "wp-content".
* Changed: the "plugins were turned off after your restore" notice shows on the Dashboard, the Plugins screen and Wrensafe's pages only.

= 1.0.0 =
* First stable release.
* Removed: the beta notice and the "Beta" label in Wrensafe's header.

= Earlier versions =
* Test releases before the first stable version.

== Upgrade Notice ==

= 1.0.3 =
Fixes hosting malware scanners (such as SiteGround's) wrongly flagging the security scan file, and stops storing staging passwords.

= 1.0.2 =
New name: Wrensafe. Your settings and backups carry over, and the backup folder moves to wp-content/wrensafe-storage automatically.

= 1.0.1 =
Backups no longer contain wp-config.php or the site's security keys, and sites with custom folder locations are backed up and restored completely.

= 1.0.0 =
First stable release of Wrensafe.
