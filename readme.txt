=== Wrensafe – Backup, Restore & Migration ===
Contributors: oyegohar
Tags: backup, restore, migration, staging, clone
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore and migrate WordPress: full or partial backups, off-site copies (FTP, S3), schedules, staging and guided restores.

== Description ==

Wrensafe backs up your WordPress site and restores it — on the same server or on a completely different one — without guesswork. Every backup is a single `.wbp` archive with its own manifest and checksums, every restore starts with an integrity check, and the dashboard tells you plainly whether your site is protected.

### Back up

* **Full site or just what you need:** database, files, media, themes, plugins, or everything.
* **AES-256 encryption** (optional, per backup). The password is never stored.
* **Automatic backups** every day, week or month at the time you choose, keeping only the newest copies — with a separate schedule for the database, for example the database every day and the whole site every week. Plus an automatic safety backup before WordPress, plugin or theme updates.
* **Off-site copies:** each backup can be copied to your own FTP/FTPS or S3-compatible storage right after it's made. It's sent in small parts that resume after an interruption, so large backups work on any host, and only the newest copies are kept there. Passwords and keys are stored encrypted.
* **Checked after every backup** (optional): the same integrity check as Verify runs as soon as a backup is made, and a backup that fails it is reported instead of being kept as good.
* **No size limits:** multi-gigabyte sites back up, download, upload and restore. Interrupted downloads and uploads resume where they stopped.
* **Works on shared hosting:** backups and restores run in small, time-limited steps, so they finish even where PHP may only run for 30 seconds.
* **Smart exclusions:** other plugins' backup archives and cache folders are skipped by default; plugin and theme code is always backed up completely. You can also leave out files larger than a size you choose, and database tables you don't need, such as large log or statistics tables.

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
* **Search & Replace:** change text anywhere in the database — for example an old address — safely inside serialized and JSON data. A dry run shows how many rows would change before anything is saved.

### Switch from another backup plugin

* **Restore backups made by other plugins:** upload a backup from All-in-One WP Migration (`.wpress`), UpdraftPlus, Duplicator, WPvivid, BackWPup, Backup Migration, Total Upkeep, Solid Backups (BackupBuddy), WP Migrate or Jetpack VaultPress Backup under **Import / Restore → Other Backup Plugins**. Wrensafe reads it, shows what's inside and restores it with the same checks as its own backups. The other plugin doesn't need to be installed.
* **Any other backup:** a `.sql` database dump and/or a `.zip` or `.tar` of the `wp-content` folder works too, for example from your hosting control panel.
* Backups split over several files (UpdraftPlus, WPvivid) are uploaded together; each file resumes if the connection drops.

### Stay informed

* **Dashboard:** protection status, recent backups, schedules and migration readiness at a glance.
* **Activity Logs** that record every step with the exact reason when something fails (passwords and keys are never logged), plus a one-click debug bundle for support.
* **Email notifications** when a backup or restore fails, and, if you like, when one completes.
* **Site Health checks:** Tools → Site Health tells you when the newest backup is out of date, when backups are only on this server, and when the backup folder can be downloaded from the web.
* **Dashboard widget:** the newest backup, the next scheduled one and the off-site copy status on the WordPress Dashboard, with a Back up now button.

### Credits

Wrensafe includes the Manrope typeface by The Manrope Project Authors (github.com/googlefonts/manrope), licensed under the SIL Open Font License 1.1; the licence is in `assets/admin/fonts/OFL.txt`.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New Plugin**, search for **Wrensafe**, then select **Install Now** and **Activate**. (Or upload the `wrensafe` folder to `/wp-content/plugins/` and activate it on the **Plugins** screen.)
2. Open **Wrensafe** in the admin menu.
3. Click **Create backup** to make your first full backup.
4. Optional: turn on automatic backups under **Scheduled Backups**.

To move a site: create a full backup on the old site, download it from the **Backup Browser**, install Wrensafe on the new site and upload the file under **Import / Restore**.

== Frequently Asked Questions ==

= Where are my backups stored? =

On your own server, in `wp-content/wrensafe-storage/` by default. You can choose another folder under **Settings → Backup → Backup folder**; a folder outside the website folder is safest, and existing backups move with it. Folders inside the website are protected from direct web access. Download a copy now and then and keep it somewhere safe, away from the server.

= Can backups be copied to another server or to cloud storage? =

Yes. Under **Settings → Remote storage**, choose FTP/FTPS or S3-compatible storage (Amazon S3 or any service with an S3-compatible API), enter the details and select **Test connection**. Each automatic backup, and optionally each backup you make yourself, is copied there right after it's made, and only the newest copies you choose to keep are left there. FTP needs PHP's FTP extension (most hosts have it). If a copy fails, the backup stays on your server, the Backup Browser shows it, and you get an email if failure emails are on.

= How do I change my site's address in the database? =

Open **Wrensafe → Search & Replace**, enter the old address and the new one, and start a dry run: it shows how many rows in each table would change, without saving anything. Then select **Replace now**. Serialized data and JSON stay valid, and post GUIDs are left as they are unless you choose otherwise. Make a database backup first: a replacement can only be undone by restoring one.

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

WordPress 6.2 or later and PHP 7.4 or later (PHP 8.3 or newer is recommended). Tested up to WordPress 7.1 and PHP 8.4. The PHP ZIP extension is recommended.

== Privacy ==

Wrensafe stores backups on your own server and never sends them, or any usage data, to Wrensafe. Data leaves your site only when an administrator sets up or starts one of the features listed under External services, such as copying backups to their own remote storage. Backups can contain personal data (user accounts, orders, form entries), so download and store them as carefully as the site itself.

== External services ==

Wrensafe only contacts the services below, and only after a site administrator configures or starts the related feature (the Site Health check, which only calls the site itself, is the one exception). Nothing is sent on activation, and no usage data or tracking is collected. Like every request WordPress makes, these requests include the site's address in the User-Agent header.

* **WordPress.org core checksums API** (api.wordpress.org) — when an administrator runs the Malware Scanner, to compare WordPress core files with the official checksums. Data sent: the site's WordPress version. [Privacy Policy](https://wordpress.org/about/privacy/).
* **Import from URL** — downloads a backup archive from the URL the administrator enters on the Import / Restore page. Nothing is sent to that server apart from the request itself.
* **Direct Migration** — sends backup data to the other WordPress site the administrator pairs with a one-time migration key.
* **Remote storage** — when an administrator sets it up under Settings → Remote storage, backup archives are uploaded to the FTP/FTPS or S3-compatible server they enter, using the login or keys they enter. Wrensafe also lists and deletes older copies in that folder or bucket to keep only the newest ones. Which service this is, and its terms and privacy policy, are the administrator's choice; Wrensafe has no service of its own.
* **Site Health check of the backup folder** — requests a short test file from the site's own address, to check that backups can't be downloaded from the web. It runs when Tools → Site Health is opened and during WordPress's own weekly Site Health check. No other server is contacted.
* **Email notifications** — backup, restore and copy results are sent with WordPress's own `wp_mail()` to the addresses set under Settings → Notifications, through whatever mail service the site uses.

== Screenshots ==

1. Dashboard: see at a glance whether your site is backed up, plus recent backups, schedules and migration readiness.
2. Back up the whole site, or only the database, files, media, themes or plugins, with optional AES-256 encryption.
3. Every backup shows live, step-by-step progress and can be cancelled safely.
4. Backup Browser: restore, download, verify, lock, tag and delete the backups stored on your server.
5. Review before you restore: where the backup comes from, what's inside, what will change, and which parts to restore.
6. Integrity verification checks the archive, manifest, database files and checksums before you restore.
7. Automatic backups: the whole site and the database on separate schedules, keeping only the newest copies.
8. Migration Assistant scores how ready the site is to move, recommends the safest method and lists the next steps.
9. Settings grouped by task: the backup folder, backups before updates, exclusions, notifications, migration and WooCommerce Safe Mode.
10. Restore backups made by other backup plugins: upload the files just as that plugin saved them.
11. Off-site copies: copy each backup to FTP/FTPS or S3-compatible storage, and test the connection before saving.
12. Search & Replace: a dry run shows how many rows would change in each table before anything is saved.
13. The Dashboard widget: the newest backup, the next scheduled one, the off-site copy, and Back up now.

== Changelog ==

= 1.1.5 =
* Fixed: a backup could miss database rows when the site deleted others while the backup ran (for example WordPress removing its ping notes for new posts, or expired sessions). Tables are now read in the order of their primary key, so rows deleted during a backup no longer make it skip rows that still exist.

= 1.1.4 =
* Changed: the plugin is now called "Wrensafe – Backup, Restore & Migration".
* Changed: Wrensafe now needs WordPress 6.2 or later (PHP 7.4 or later, as before). On an older WordPress or PHP it stays inactive and says what it needs instead of stopping the site.
* Changed: off-site copies go to FTP/FTPS or S3-compatible storage. SFTP is no longer included: its connection relied on a PHP feature many hosts block. Saved SFTP settings are kept and Settings → Remote storage explains what to do.
* Security: Direct Migration's receiving site now checks the key, the request limits and every field before it accepts a request, and "Restrict to one IP address" now locks the key to the first server that sends with it, as described.
* Security: temporary database files made during a backup, restore or import now have names that can't be guessed, and the storage folder is protected on Apache 2.2 and 2.4 (without errors) and on IIS.
* Security: settings saved by any route are checked like the Settings screen checks them, a backup can only be deleted from the backup folders, Import from URL no longer reaches addresses on the server's own network, and on multisite only network administrators can use Wrensafe.
* Security: a restore only runs the table statements of the backup it restores; anything else stops the restore before the live site changes.
* Fixed: "Push to live" copied the staging site's own Wrensafe records and its address and "discourage search engines" setting to the live site.
* Fixed: backups included the tables of staging sites, so each new staging site copied the earlier ones.
* Fixed: a restore's clean-up could remove the temporary tables of another WordPress site that shares the database.
* Fixed: the safety file a restore uses stayed behind when Wrensafe was deactivated during a restore, which could leave the site blank; it is now removed on deactivation and can't be opened directly.
* Fixed: migration keys expired early on sites ahead of UTC, and a key could expire during a long transfer.
* Fixed: a remote storage password with a backslash didn't work after saving.
* Fixed: a missing Zip, Zlib, bz2 or OpenSSL extension now gives a clear message instead of an error; backup encryption is turned off with the reason when OpenSSL is missing.
* Fixed: some error messages showed a character code instead of an apostrophe or a quote.
* Changed: emails are sent as plain text; Wrensafe's menu icon styles load only for users who see the menu; WordPress's own notices are no longer moved on Wrensafe's screens; the 5-minute background check runs only while a schedule is on (otherwise once a day).

= 1.1.3 =
* Fixed: Direct Migration stopped with "Too many requests" partway through a larger backup, and new attempts were refused too. The receiving site no longer counts the parts of a transfer that uses a valid key, its limits no longer extend themselves while you retry, and the sending site waits and continues when asked to slow down (also with a receiving site on an older version).
* Fixed: starting a transfer again with the same key now continues where it stopped instead of starting over, and short connection problems are retried automatically.
* Changed: before sending, Wrensafe checks the receiving site: that it can be reached, the key is valid, and it accepts this connection (HTTP or HTTPS). Problems are shown as an error that names the site to change, for example which site's "Require HTTPS" setting blocks the transfer, an address that redirects to another one, or a local site's self-made HTTPS certificate.
* Fixed: an error during Direct Migration was shown as a plain status line, and some lost their reason ("An error occurred").
* Changed: the Wrensafe icon in the admin menu is now the colour logo (on a small white tile while the item is open or hovered, so it stays clear on any colour scheme).

= 1.1.2 =
* Fixed: a Wrensafe zip installed from a folder with another name (for example GitHub's "Source code" download, which unpacks to "wrensafe-plugin-1.1.0") was added as a second plugin when you uploaded a new version, instead of replacing the installed one. Wrensafe now says when it runs from such a folder and how to fix it once.
* Fixed: when two copies are active, the notice now says which version is running and has a button to deactivate the older copy.
* Fixed: deleting one copy of Wrensafe while another is still installed never removes the shared settings and backup records, even when Settings → Advanced → Uninstall is on.

= 1.1.1 =
* Changed: the new Wrensafe look (Cobalt Ice). The new logo in each page header and the welcome tour, the Wrensafe icon in the admin menu (it follows your admin colour scheme), the brand's cobalt, navy and ice-blue colours, a light frost background on Wrensafe's pages, and the Manrope typeface for headings. Manrope is bundled with the plugin; nothing is loaded from other sites.
* Changed: new WordPress.org banner, icon and screenshots.
* Fixed: the backup progress window now lists "Checking the backup" and the off-site copy as their own steps when they're turned on, and its percentage no longer steps back during the check.
* Fixed: the Backup Browser count in the admin menu no longer wraps onto a second line.

= 1.1.0 =
* New: off-site copies (Settings → Remote storage). Each backup can be copied to FTP/FTPS, SFTP or S3-compatible storage right after it's made, in parts that resume after an interruption, keeping only the newest copies there. Choose whether automatic backups, backups you make yourself, or both are copied. Passwords and keys are stored encrypted with the site's own security keys. The Backup Browser shows each backup's copy status, and a failed copy is emailed when failure emails are on.
* New: a separate schedule for the database, next to the full-site schedule, on the Scheduled Backups page.
* New: leave chosen database tables out of backups (Settings → Backup → Exclusions). WordPress's own tables are always backed up, and a left-out table keeps its current version when you restore.
* New: leave out files larger than a size you set. Plugin and theme files are always backed up.
* New: check each backup right after it's made (Settings → Backup → Checks). A backup that fails the check is marked damaged, isn't copied off-site, and is reported.
* New: Search & Replace page with a dry run, for serialized and JSON data too. Post GUIDs are left alone by default.
* New: Site Health checks for a recent backup, off-site copies and a private backup folder, and a Wrensafe section under Site Health → Info.
* New: Dashboard widget with the newest backup, the next scheduled one, the off-site copy status and Back up now.

= 1.0.3 =
* Fixed: some hosting malware scanners (SiteGround's among them) reported a file of the security scan as malicious, because it listed the PHP function names that malware uses, and could suspend the site. The security scan no longer keeps such a list; it now looks for long encoded payloads in PHP files instead. Nothing in the plugin matches common malware signatures (checked with ClamAV, Linux Malware Detect and PHP Malware Finder).
* Security: the staging site password is no longer stored in the database (only its hash, in the staging site's `.htpasswd`), and passwords kept by earlier versions are removed. The hash now uses a random salt.
* Security: settings read from a backup during a restore can't create PHP objects.
* Changed: an import from a URL stops downloading at the size limit instead of after the whole file.

= 1.0.2 =
* New name: Wrensafe (slug `wrensafe`). Settings, backups and the backup list carry over, and the backup folder moves to `wp-content/wrensafe-storage/` automatically.
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

= 1.1.5 =
Fixes backups that could miss database rows on busy sites. Recommended for everyone.

= 1.1.4 =
Security and reliability fixes, WordPress 6.2 or later required. SFTP off-site copies are no longer included; choose FTP/FTPS or S3-compatible storage instead.

= 1.1.3 =
Direct Migration no longer stops with "Too many requests", continues an interrupted transfer, and explains HTTPS and key problems before sending. Update both sites.

= 1.1.2 =
If uploading a new version added a second Wrensafe instead of replacing it, Wrensafe now shows why and how to fix it once; deleting a duplicate copy always keeps your data.

= 1.1.1 =
The new Wrensafe look: new logo and menu icon, Cobalt Ice colours and Manrope headings. The backup progress window also shows the check and the off-site copy steps.

= 1.1.0 =
Adds off-site copies to FTP, SFTP and S3-compatible storage, a separate database schedule, table and large-file exclusions, automatic backup checks, Search & Replace, Site Health checks and a Dashboard widget.

= 1.0.3 =
Fixes hosting malware scanners (such as SiteGround's) wrongly flagging the security scan file, and stops storing staging passwords.

= 1.0.2 =
New name: Wrensafe. Your settings and backups carry over, and the backup folder moves to wp-content/wrensafe-storage automatically.

= 1.0.1 =
Backups no longer contain wp-config.php or the site's security keys, and sites with custom folder locations are backed up and restored completely.

= 1.0.0 =
First stable release of Wrensafe.
