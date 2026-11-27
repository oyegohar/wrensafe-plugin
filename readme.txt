=== Wrensafe – Backup, Restore & Migration ===
Contributors: oyegohar
Donate link: https://buy.stripe.com/4gMaEXfvddbfdVV2j65os00
Tags: backup, restore, migration, staging, clone
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Back up, restore and migrate WordPress. Import other plugins' backups, repair broken links after a move, and update plugins safely.

== Description ==

Wrensafe is a WordPress backup, restore and migration plugin built for the moment that matters: getting a site back, working, with its links and images intact. It restores on the same server or a completely different one. Every backup is a single `.wbp` archive with its own manifest and checksums, every restore starts with an integrity check, and the dashboard tells you plainly whether your site is protected.

https://www.youtube.com/watch?v=uYh1AkgG6Kk

### Built for what goes wrong

* **Broken images and links after a restore or migration?** Fix Links finds links that still point to the old site, server folders written into image addresses, http links on an https site and missing media files. It fixes the links after making a database backup.
* **Stuck with a backup from another plugin?** Restore `.wpress`, UpdraftPlus, Duplicator, WPvivid, BackWPup and other backups without installing the plugin that made them.
* **Afraid a plugin update will break the site?** Safe Update checks the site after updating and puts the previous version back if the update causes a fatal error.
* **Moving to a new domain or host?** Wrensafe rewrites the old addresses and paths, including inside serialized data and page-builder content.
* **On shared hosting?** Backups and restores run in small steps, so large sites finish even where PHP may only run for 30 seconds.

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
* **Fix Links:** after a restore or a move, Wrensafe checks for links that still point to the old site or have a server folder in their address, fixes them after a database backup, and lists media files that aren't on the site. Email addresses and other domains are left alone. A link to another site that contains the old address, such as a share or web-archive link, is updated too. Server folders it can't confirm as this site's, and tables left out of backups, are listed for you to check by hand.
* **WooCommerce Safe Mode** keeps new orders, customers and stock untouched while the rest of the store is restored.

### Update safely

* **Choose how to update:** when you update a plugin on the Plugins or Updates screen, Wrensafe asks how. **Quick Update** is WordPress's standard update; **Safe Update** adds a safety net.
* **Safe Update:** Wrensafe saves a copy of the plugin, updates it, then loads your home page and admin area. If the update causes a fatal error, a server error or an empty page, the previous version is put back within seconds and stays active (if it can't be put back, the plugin is turned off so the site keeps working). You see what went wrong, down to the file and line.
* **Several plugins at once:** update them one by one (each is checked before the next) or all at once (one check; if the site breaks and the error names one of them, only that one is rolled back, otherwise all of them are). The updates continue in the background, through WP-Cron, when you close the page. If a run stops for six minutes with a plugin installed but not yet checked (the request installing it was cut off, or WP-Cron doesn't run), visitors' pages load without that plugin until the run has checked it, and put the previous version back if needed.
* **Roll back later:** the copy saved before each update is kept for 14 days (you choose how long), so an update can be undone from Wrensafe → Safe Updates even when the site check passed.
* **Clear caches after updating:** the page cache of the common caching plugins and WordPress's object cache, so visitors see pages built with the new versions.

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
* **AI assistants (MCP)**: let Claude, Cursor, Codex, Gemini CLI and others check your backups, make and test them, or ask you for a restore (WordPress 6.9+, with the MCP Adapter plugin). Off by default; you approve every restore yourself, and deleting backups is never available.
* **Activity Logs** as a timeline: each backup, restore, update run or move with how it went and the exact reason when something fails, every step one click away (passwords and keys are never logged), plus a one-click debug bundle for support.
* **Email notifications** when a backup or restore fails, and, if you like, when one completes.
* **Site Health checks:** Tools → Site Health tells you when the newest backup is out of date, when backups are only on this server, and when the backup folder can be downloaded from the web.
* **Dashboard widget:** the newest backup, the next scheduled one and the off-site copy status on the WordPress Dashboard, with a Back up now button.

### Credits

Wrensafe includes the Manrope typeface by The Manrope Project Authors (github.com/googlefonts/manrope), licensed under the SIL Open Font License 1.1; the licence is in `assets/admin/fonts/OFL.txt`.

The AI client logos under Settings → AI assistants come from Simple Icons 16.38.1 (simpleicons.org), CC0 1.0, except the GitHub Copilot icon: MIT License, Copyright (c) GitHub Inc. (Primer Octicons); its license text is in `includes/Admin/McpClients.php`. Each name and logo is a trademark of its owner, shown only to say which client a setup is for.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New Plugin**, search for **Wrensafe**, then select **Install Now** and **Activate**. (Or upload the `wrensafe` folder to `/wp-content/plugins/` and activate it on the **Plugins** screen.)
2. Open **Wrensafe** in the admin menu.
3. Click **Create backup** to make your first full backup.
4. Optional: turn on automatic backups under **Scheduled Backups**.

To move a site: create a full backup on the old site, download it from the **Backup Browser**, install Wrensafe on the new site and upload the file under **Import / Restore**.

== Frequently Asked Questions ==

= How do I fix broken images and links after restoring or migrating WordPress? =

Open **Wrensafe → Fix Links**, check the old site address (it is filled in after a restore) and select **Check links and media**. The check changes nothing. If it finds links to fix, select **Fix links**: Wrensafe makes a database backup first, then repairs them. Media files that aren't on the site are listed so you can upload them again.

= How do I restore a .wpress backup? =

Install Wrensafe on the site, go to **Import / Restore → Other Backup Plugins** and upload the `.wpress` file. Wrensafe shows what's inside, then restores it with the same checks as its own backups. All-in-One WP Migration doesn't need to be installed.

= How do I roll back a plugin update? =

Update the plugin with **Safe Update**. Wrensafe saves a copy of the current version first and keeps it for 14 days (you can change this). To undo the update, open **Wrensafe → Safe Updates**, find it under **Recent updates** and roll it back.

= How do I migrate WordPress to a new domain or host? =

Create a full backup on the old site and download it from the **Backup Browser**. Install Wrensafe on the new site and upload the file under **Import / Restore**. Wrensafe rewrites the old addresses and paths. To skip the download, use **Direct Migration** to send the backup straight to the new site with a one-time key.

= How does Safe Update know an update broke my site? =

Before the first update, Wrensafe loads your home page and admin area the same way WordPress checks a plugin you edit in the plugin editor, and it does the same after each update (with "All at once", after the whole group). A new fatal error, a server error (HTTP 500 or higher), a page that no longer loads, or a home page that comes back empty means the update is rolled back. Problems the site already had before the update don't count.

= What if my server can't load its own pages? =

Some hosts block these "loopback" requests (Tools → Site Health tells you). Safe Update then still saves a copy of each plugin, so you can roll an update back from Wrensafe → Safe Updates, but it can't check the site for you, and the result says so.

= Does Safe Update work with premium plugins? =

Yes, whenever WordPress itself can update the plugin: an update is listed on the Plugins screen and its download is available (premium plugins usually need an active license for that). Wrensafe uses WordPress's own updater.

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

= Does a restore remove files added after the backup? =

No. A restore puts back the files in the backup and leaves newer files where they are. After a hack, that means it won't remove files an attacker added: clean the site first, or restore a backup made before the hack onto a fresh WordPress install. `wp-config.php` is never in a backup, so check it on the hacked site too.

= Will restoring log me out? =

A database restore replaces the users table with the one from the backup, so you will be asked to log in again at the end — with the usernames and passwords from the site the backup came from.

= Does it work on shared hosting? =

Yes. Backups, restores, uploads and verification all run in short, time-limited steps, so they complete even where PHP may only run for 30 seconds.

= Can it handle large sites? =

Yes. There is no size limit. A 2.4 GB site (25,000 files, 30,000 posts) was backed up, downloaded, uploaded to another site and restored on a server limited to 30-second requests and 8 MB uploads, with every file and database row checked afterwards. You need free disk space about equal to your site's size to create a backup, and about one and a half times the backup's size to restore one. Interrupted downloads and uploads resume where they stopped.

= Is encryption optional? =

Yes. Turn it on per backup. The password is never stored or logged — keep it safe, because a lost password cannot be recovered.

= Does it work with WooCommerce? =

Yes. WooCommerce Safe Mode protects orders, customers and stock during a restore. While a restore runs, visitors see a short maintenance notice and the checkout is paused; both end as soon as the restore is done.

= Does it support multisite? =

Not yet. Wrensafe is built and tested for single-site WordPress. Restoring a multisite database is blocked to protect the network.

= What happens when I uninstall the plugin? =

Nothing is deleted unless you turn on **Settings → Advanced → Uninstall**. Then Wrensafe removes its settings and records from the database, its work folders (unfinished uploads, logs and the plugin copies Safe Updates saved before updates) and the staging sites it made (their folders and database tables). Your backup files are always kept.

= Does Wrensafe add files outside its plugin folder? =

Only while it needs them. During a restore or a Safe Update it adds a small file to `wp-content/mu-plugins` (`wpbp-restore-plugin-guard.php` or `wpbp-safe-update-guard.php`) and removes it when it finishes, when Wrensafe is deactivated and when it is deleted. A staging site is a copy in a `staging-…` folder inside your WordPress folder, with its own database tables; a password-protected one gets `wrensafe-staging-guard.php` in its own `mu-plugins` folder, and page caching is turned off in its own `wp-config.php` (WP_CACHE). Where that can't be done, the staging site's cache folder is emptied and the Activity Log says how to turn caching off. Backups, logs and temporary files are kept in the `wrensafe-storage` folder (or the folder you chose). While Safe Updates runs, a small progress file (only the position and state of each update) is kept in `wp-content/uploads/wrensafe-live/` and removed when the run ends, when Wrensafe is deactivated and when it is deleted.

= Can AI assistants like Claude or Cursor use Wrensafe? =

Yes, on WordPress 6.9 or newer, with the free MCP Adapter plugin by WordPress.org. Turn it on under **Settings → AI assistants**: assistants can then read the backup status, the backups and recent activity. You choose whether they may also create, verify and test backups, and whether they may ask for a restore, a rollback or a Search & Replace, which you approve and run yourself in wp-admin. Deleting backups is never available to them, and every call is recorded in Activity Logs. The tab shows a copy-paste setup for Claude Code, Claude Desktop, Cursor, VS Code, Windsurf, Codex, Gemini CLI and Antigravity.

= How can I support Wrensafe? =

A review on WordPress.org helps the most, and so do bug reports in the support forum. If you'd like, you can also send a small tip with the Donate link on this page or under **Settings → Support Us**. Wrensafe stays free: a tip doesn't unlock features.

= What are the minimum requirements? =

WordPress 6.2 or later and PHP 7.4 or later (PHP 8.3 or newer is recommended). Tested up to WordPress 7.1 and PHP 8.4. The PHP ZIP extension is recommended.

== Privacy ==

Wrensafe stores backups on your own server and never sends them, or any usage data, to Wrensafe. Data leaves your site only when an administrator sets up or starts one of the features listed under External services, such as copying backups to their own remote storage. Backups can contain personal data (user accounts, orders, form entries), so download and store them as carefully as the site itself.

== External services ==

Wrensafe only contacts the services below, and only after a site administrator configures or starts the related feature (the Site Health check, which only calls the site itself, is the one exception). Nothing is sent on activation, and no usage data or tracking is collected. Like every request WordPress makes, these requests include the site's address in the User-Agent header.

* **WordPress.org core checksums API** (api.wordpress.org) — when an administrator runs the Security scan (Wrensafe → System Info), to compare WordPress core files with the official checksums. Data sent: the site's WordPress version. [Privacy Policy](https://wordpress.org/about/privacy/).
* **Import from URL** — downloads a backup archive from the URL the administrator enters on the Import / Restore page. Nothing is sent to that server apart from the request itself.
* **Direct Migration** — when an administrator sends a backup, sends it to the other WordPress site they pair with a one-time migration key, and only to that site. Data sent: the migration key, the backup archive (in signed parts), its file name, size, type and checksum, and this site's address, which is shown in the receiving site's backup list.
* **Remote storage** — when an administrator sets it up under Settings → Remote storage, backup archives are uploaded to the FTP/FTPS or S3-compatible server they enter, using the login or keys they enter. Wrensafe also lists and deletes older copies in that folder or bucket to keep only the newest ones. Which service this is, and its terms and privacy policy, are the administrator's choice: no service is preset (the providers named in the settings are only examples), and Wrensafe has no service of its own.
* **Site Health check of the backup folder** — requests a short test file from the site's own address, to check that backups can't be downloaded from the web. It runs when Tools → Site Health is opened and during WordPress's own weekly Site Health check. No other server is contacted.
* **Plugin updates (Safe Updates)** — updates run through WordPress's own updater, which downloads each update from the address WordPress lists for it (WordPress.org, or the author's server for a premium plugin). "Check again" on the Safe Updates page runs WordPress's own update check with WordPress.org. To check the site after an update, Wrensafe loads the site's own home page and admin area; no other server is contacted.
* **YouTube (intro video)** — under Settings → Support Us the intro video is a picture stored in the plugin. Only when an administrator presses play, their browser loads the player from youtube-nocookie.com and other YouTube and Google addresses, which receive their IP address, browser details and this site's address (as the referrer); once the video plays, YouTube may store cookies or similar data in the browser. [YouTube terms](https://www.youtube.com/t/terms), [Google privacy policy](https://policies.google.com/privacy).
* **Stripe (optional tip)** — The tip card under Settings → Support Us and "Send a tip" on the Plugins screen link to a payment page at buy.stripe.com (an amount button fills in that amount). Nothing is loaded or sent until an administrator selects one. Their browser then opens Stripe's page, and what they enter there goes to Stripe. Tips are received by Tech and Webs, the agency behind Wrensafe. [Terms](https://stripe.com/legal) and [Privacy Policy](https://stripe.com/privacy).
* **AI assistants (MCP)** — off by default. Wrensafe contacts no AI service itself. After an administrator turns on Settings → AI assistants and connects a client (such as Claude, Cursor, VS Code or Gemini CLI), that client signs in to this site with an Application Password and receives what it asks for: the backup status, the list of backups and their file names, recent activity (which includes user names), updates waiting, and the progress and results of the backups, checks and requests it starts (for Fix Links: the old addresses and server folders it found, example links with their post titles, and the names of missing media files). The client may pass this on to the AI service it uses (for example Anthropic, OpenAI or Google), under that service's own terms and privacy policy. Turning the setting off or revoking the Application Password stops it.
* **Email notifications** — backup, restore and copy results are sent with WordPress's own `wp_mail()` to the addresses set under Settings → Notifications, through whatever mail service the site uses.

== Screenshots ==

1. Dashboard: see at a glance whether your site is backed up, plus recent backups, schedules and migration readiness.
2. Fix Links: after a restore or a move, find links that still point to the old site or have a server folder in them, fix them after a database backup, and see which media files are missing.
3. Safe Updates: update several plugins one by one or all at once, follow the progress, and roll an update back later.
4. Restore backups made by other backup plugins: upload the files just as that plugin saved them.
5. When you update a plugin, choose Quick Update or Safe Update, which checks your site and rolls a breaking update back.
6. Back up the whole site, or only the database, files, media, themes or plugins, with optional AES-256 encryption.
7. Every backup shows live, step-by-step progress and can be cancelled safely.
8. Backup Browser: restore, test, download, verify, lock, tag and delete the backups stored on your server.
9. Review before you restore: where the backup comes from, what's inside, what will change, and which parts to restore.
10. Integrity verification checks the archive, manifest, database files and checksums before you restore.
11. Automatic backups: the whole site and the database on separate schedules, keeping only the newest copies.
12. Migration Assistant scores how ready the site is to move, recommends the safest method and lists the next steps.
13. Off-site copies: copy each backup to FTP/FTPS or S3-compatible storage, and test the connection before saving.
14. Search & Replace: a dry run shows how many rows would change in each table before anything is saved.
15. Settings grouped by task: the backup folder, exclusions, updates, remote storage, notifications, migration and WooCommerce Safe Mode.
16. The Dashboard widget: the newest backup, the next scheduled one, the off-site copy, and Back up now.

== Changelog ==

= 1.3.0 =
* New: "What's new" after an update: once, on the first Wrensafe screen, each new feature with a link to try it and a tour of them all.
* New: "New" labels in the Wrensafe menu and on each new feature, until you have opened it.
* Improved: the guided tours show AI assistants, Restore Test and the activity timeline, and a "What's new in 1.3.0" tour walks through them.
* Improved: AI assistants turn on with one button, then you connect in four steps: a new or an existing Application Password, or WP-CLI; Claude Code, Cursor, VS Code, Windsurf and Gemini CLI connect directly, without Node.js.
* New: AI assistants (MCP). Claude, Cursor, Codex, Gemini CLI and other assistants can check your backups, make and test them, and ask for a restore, through WordPress's Abilities API and the MCP Adapter plugin (WordPress 6.9 or newer). Off by default; actions and requests are separate switches; a person approves every restore, rollback or Search & Replace in wp-admin and runs it there; deleting backups is never available, and backups an assistant makes stay on this server (never copied off-site, so off-site clean-up can't remove older copies because of them), up to 6 a day; every call is in Activity Logs. Copy-paste setup for each assistant under Settings → AI assistants.
* New: Restore Test. In the Backup Browser, "Test restore" restores a backup into a temporary copy to prove it works: every file is checked, the database is imported, and the copy is checked for its settings, an administrator, its theme and its plugins. The live site isn't changed and stays online. Like a restore, it first checks there is enough free disk space. The result shows next to the backup and in Activity Logs.
* New: after you install and activate Wrensafe, it opens on its Dashboard with the welcome. "Show me around" (on every welcome slide) starts the full guided tour. This happens once, only for the person who activated it, and not when several plugins are activated together.
* New: Import / Restore finds backups other plugins left on this server (UpdraftPlus, All-in-One WP Migration, Duplicator, WPvivid, BackWPup and others) and restores one without uploading anything (for administrators, like an import from a server path). Wrensafe reads them where they are; nothing is moved or deleted.
* New: Activity Logs as a timeline. Each backup, restore, Safe Updates run or move is one card with how it went, the reason when something went wrong, and its steps one click away, grouped by day. Totals for the last 30 days, filters (Backups, Restores, Safe Updates, Moves, Fix Links, Problems) and search. "All entries" keeps the full list.
* New: Changelog. A link in the header opens every release of Wrensafe, newest first, with filters and search.
* New: Guided tours. A button in the header opens short tours (See everything, Make a backup, Restore a backup, Move a site, Update plugins safely, Fix broken links). Each one highlights a part of the screen, explains it and moves from page to page. A tour never changes anything on your site.
* New: a Support Us link in the header, and a softer highlight on the header links.
* New: an optional tip under Settings → Support Us ($3, $5, $10, $25 or your own amount) and on the Plugins screen. It opens Stripe's checkout page; a tip doesn't unlock features.
* Fixed: a backup couldn't be restored or imported where PHP's zip extension is turned off: the upload ended with "This file is not a readable archive". This is common on local setups on Windows (Laragon, XAMPP and others leave it off since PHP 8.2) and on some hosts. Wrensafe now reads its backups with its own built-in reader there, and .zip backups from other plugins (UpdraftPlus, Duplicator, WPvivid, BackWPup, Backup Migration and others) too.
* Improved: on servers without PHP's zip extension, backups are now compressed .wbp files like everywhere else (they were uncompressed TAR files).
* Improved: large database tables restore much faster. Rows are sent to the database in batches (about ten times faster on a table with millions of rows), and the progress shows how far each large table is.
* Fixed: a very large table (gigabytes of data) had to be unpacked from the backup within one request, which could time out on slower servers. It is now unpacked in steps, and so are large compressed files in wp-content.
* Fixed: if a table's data can't be unpacked from the backup, the restore now stops before the live database is changed, instead of possibly restoring that table empty.
* Fixed: checking a backup (before a restore, and with Verify) could run out of time on a very large table on hosts with a strict time limit. A large table is now checked in steps (always on PHP 8.1 or newer; on PHP 7.4 and 8.0, in backups made by Wrensafe 1.3.0 or later).
* Fixed: a restore could stop partway, on "Database restored", when a plugin the site already had was waiting to show its welcome screen in the backup's database (WPvivid, for one): it redirected the restore's own requests. Those requests now load Wrensafe alone.
* Improved: checking and restoring a backup with thousands of files is much faster on PHP 7.4 and 8.0 (a backup check went from almost 10 minutes to under a minute).
* Improved: clearer messages when a backup file can't be read (an incomplete download or upload, a file PHP isn't allowed to open, PHP without zip and zlib), and Activity Logs records the technical reason.
* Fixed: the welcome tour opened a second time after "Get started" when it was started on a Wrensafe page other than the Dashboard.
* Fixed: Fix Links counted links inside WordPress's caches (transients), which aren't part of the site's content and are rebuilt on their own.
* Fixed: the Dashboard and System Info showed a different migration readiness score than the Migration Assistant. All three now show the Migration Assistant's score and warnings, from its latest scan, which runs again when it's a day old.
* Fixed: one backup, update run or other job that went wrong long ago could keep the review request away for good. Only the last 14 days count now.
* Improved: the review request's check reads less from the database on each Wrensafe screen.

= 1.2.8 =
* New: numbers in the Wrensafe menu when something needs you: updates waiting (Safe Updates) and links to fix (Fix Links). Rollback and Scheduled Backups show a "!" only when a restore or a scheduled backup failed, or schedules stopped running.
* New: a redesigned review card, and a notice asking for a review at a good moment (after a restore that worked, or a few backups and two weeks of use). "Maybe later" asks once more after 30 days; closing it or "I already did" ends it. It doesn't appear while a backup, restore or update is running, while the Fix Links notice is showing, or when the latest backup, restore, update run, transfer, import or staging job didn't go well (it failed, its off-site copy failed, or an update was put back), and not when a scheduled backup failed or is late.
* New: the intro video under Settings → Support Us. Nothing loads from YouTube until you press play.
* New: Settings → Import / Export. Export Wrensafe's settings to a file, import them on another site after reviewing every change, or reset them to defaults, with an undo. Passwords, off-site storage, the backup folder and your email address are never included.
* Improved: Fix Links now shows what it is reading, how far it has got and each part as it finishes, with a live count of what it has found. Old addresses are added and removed as neat chips, with a clear message when one is wrong.
* Improved: when an update isn't installed or is rolled back, Safe Updates now says why (for example an inactive license, a blocked download, no disk space or a fatal error) and what to do. A run where most updates worked shows as a success, with the ones that didn't marked next to it.
* Improved: Safe Updates shows live progress in both update modes: how many are done, which plugin is being worked on and whether it is downloading, installing or being checked.
* Improved: the Security scan shows each check as it runs, then a clear summary with what needs a look first. A check that couldn't run now says so instead of showing "Nothing found".
* Improved: buttons now look and behave the same on every screen, and every delete or stop action has the same clear style.
* Improved: on sites with a long admin menu, short Wrensafe screens stay in view while the menu scrolls, instead of leaving an empty page.
* Fixed: off-site storage settings and their connection test now need the permission to manage off-site storage (administrators have it), and a saved password or secret key has to be typed again when the server or account changes.
* Fixed: two restores started at the same moment could both go ahead; starting a restore is now one step only one request can take.
* Fixed: Fix Links also finds and fixes a Windows server folder written with doubled backslashes (as some page builders store it).
* Changed: the empty Roadmap box under Settings → Support Us is hidden until there is a roadmap page to link to.

= 1.2.7 =
* Fixed: restoring a backup made with All-in-One WP Migration onto another site broke images and file links: the server's folder (such as /var/www/html) was written into their addresses, right after the site's address. Restores now only replace full folders and whole addresses (the old address no longer changes a longer one such as old-site.com.au), and also replace the old address's "www." form.
* New: Wrensafe → Fix Links. After a restore or a move it checks posts, pages, page-builder data, settings and the Media Library for links that still point to the old site, links with a server folder in them, http:// links on an https:// site, and media files that aren't on the site. It fixes the links after a database backup and lists the missing files. Email addresses and other domains are left alone. A link to another site that contains the old address, such as a share or web-archive link, is updated too. Server folders it can't confirm as this site's, and tables left out of backups, are listed for you to check by hand. After a restore that moved the site, a notice offers it with a Fix links button.
* Fixed: when the site check after an update only timed out (a slow site) and the update couldn't be rolled back, Safe Updates deactivated the plugin, which could break a theme that needs it (for example Advanced Custom Fields). Slow pages now get a longer second try; when an update can't be rolled back after a timeout, the plugin is left switched off only if the site answers again without it; and a plugin whose deactivation breaks something else is switched back on.
* Fixed: after one update left the site with a problem, Safe Updates rolled back every later update for that same problem. The run now stops instead.
* New: "Stop now" in Safe Updates ends a run at once (an update installed but not checked yet is rolled back), next to "Stop after this update". A run nothing has moved for five minutes says so.
* Fixed: a restore started while Safe Updates was running left the run hanging for hours. A restore now stops the update run first.
* Changed: a new header on Wrensafe's screens, with links to the documentation and support.
* Fixed: the import screen counted one of Wrensafe's own protection files as an uploaded backup file.
* Fixed: deleting two copies of Wrensafe together (for example one installed from a GitHub download) could stop with a PHP error on the second copy, or keep its data although "Delete Wrensafe's data" was on. When both copies are 1.2.7 or later, the second one now removes the data once no copy is left.
* Fixed: in "All at once" Safe Updates continued in the background, a step that runs out of time now checks the updates it already installed before it installs more, so it never ends with updates installed but not checked.
* Fixed: a Safe Updates step that runs longer than its lock now leaves the run to the request that took it over in every case, also when an error happens at that moment, and a large package's unzip and copy keep the lock.
* Fixed: choosing to keep or roll back an update an add-on held back no longer ends in an error page if another request took the run over meanwhile.
* Fixed: when a run that an add-on's WordPress core update was part of stops, the restore clean-up waits if another restore is under way.
* Changed: the FAQ says what to do when restoring after a hack.

= 1.2.6 =
The 1.2.5 fixes below come to WordPress.org with this version (1.2.5 was a private test release).

* Changed: the plugin's WordPress.org page now has a short overview video.
* Changed: the FAQ now explains that a restore leaves files added after the backup in place.

= 1.2.5 =
* Fixed: with "All at once", a Safe Updates run continued in the background (WP-Cron) could stop after installing updates and before checking them. On hosts where WP-Cron only runs every few minutes, visitors then got the site without those plugins until the next run. A background step now keeps going for up to 150 seconds more so it can check what it installed, and keeps its lock while it runs, also while it waits for a download or a site check.
* Fixed: if a background Safe Updates step ended early (PHP's time or memory limit, or an exit), nothing continued the run. It now continues on the next WP-Cron run, usually within about a minute, or about six minutes later if the host killed the request outright. If steps are cut off at the same point three times in a row, the run stops and puts back any update it couldn't check (if that is cut off too, the run stops without trying again: an update being rolled back is handled as when a rollback fails, which keeps the site working and deactivates a plugin if needed, and every update left unchecked is named for you to check).
* Fixed: a background run waiting for your decision (when an add-on asks for it) or for another request's step no longer starts a WP-Cron request on every page view; it waits until you decide, or looks again every 30 seconds.
* Fixed: on a password-protected staging site whose page caching couldn't be turned off automatically (WP_CACHE set in an unusual way or outside its wp-config.php), pages cached earlier were kept. They are deleted now, and the Activity Log says how to turn caching off.

= 1.2.4 =
The first 1.2 release on WordPress.org. Versions 1.2.0 to 1.2.3 were tested on a few sites first; everything they added is listed here, and the 1.1.5 to 1.1.7 fixes are included.

* New: Safe Updates. When you update a plugin on the Plugins or Updates screen, Wrensafe asks how: Quick Update (WordPress's standard update) or Safe Update, which saves a copy of the plugin, updates it, checks the home page and admin area, and puts the previous version back automatically if the update causes a fatal error, a server error or an empty page. To update without being asked, turn the question off under Settings → Updates.
* New: Wrensafe → Safe Updates. Update several plugins one by one or all at once, in the background (it continues through WP-Cron when you close the page), follow the progress, and roll an update back from the copy saved before it for 14 days (you choose how long). If a run stops for six minutes with a plugin installed but not yet checked (the request installing it was cut off, or WP-Cron doesn't run), visitors' pages load without that plugin until the run has checked it, so an update that breaks the site doesn't keep it down; the run then puts the previous version back if needed.
* New: "Clear the cache after updating" empties the page cache of the common caching plugins and WordPress's object cache. It is ticked by default; untick it in the update window or under Settings → Updates.
* New: Settings → Updates: the update question (on or off), the way chosen by default, the cache option, how long saved copies are kept, and "Back up before updates" (moved here from Settings → Backup). A Safe Updates run makes one backup before its first plugin instead of one per plugin.
* New: failure emails (Settings → Notifications) also report updates that were rolled back.
* New: for add-ons, Safe Updates can run other kinds of updates and add their own checks and report details (the wpbp_safe_update_types, wpbp_safe_update_baseline, wpbp_safe_update_check and wpbp_safe_update_item_report filters), and Settings → Remote storage can offer more kinds of off-site storage (wpbp_remote_storage_types).
* Security: staging password protection now works on every web server, nginx included. It used .htaccess, which nginx (and Apache without AllowOverride) ignores, so such a staging site could be open. Visitors now get a password form before any page, and the staging site's plugins don't run for them. After 10 wrong passwords from one address (or 100 in all) the form pauses for 15 minutes, also for passwords sent as HTTP Basic credentials. Pages shown after the password are marked as not cacheable, and page caching (WP_CACHE) is turned off on password-protected staging sites, whose cached pages are deleted, so a cache can't show them without the password. Caches your host runs in front of WordPress that ignore "do not cache" headers aren't covered. Everyone, administrators included, enters the password once a day; WP-CLI and the site's own scheduled tasks get through. The password's hash is no longer kept in a .htpasswd file that could be downloaded. Existing staging sites switch over automatically and keep their user name and password; new ones need a password of at least 8 characters.
* Security: the temporary Safe Updates guard takes effect for Wrensafe's own requests (a secret for each run, WordPress's own cron requests, WP-CLI) and, once a run has stalled for six minutes with a plugin update not checked yet, for WP-Cron and visitors' page views (never admin screens, WP-CLI or form posts). It only refreshes PHP's opcode cache for real site checks, is installed through WP_Filesystem, and a restore never puts it back. Saved copies have unguessable file names.
* Fixed: on PHP 7.4 and 8.0, very large backups no longer need one long request. The backup's checksum, and a single very large file inside it, are now handled in short steps like the rest of the backup, so hosts with a 30-second limit don't stop it at "Calculating checksum". When a file or archive is too large for one step there, its checksum is recorded in a new format (8 MB slices, chained). Backups made by earlier versions are checked and restored as before.
* Fixed: Verify checks the whole-file checksum of a very large backup too, in short steps (it was skipped above 1 GB).
* Changed: Direct Migration sends each part of the backup as it is instead of as base64 text: a quarter less data, and nothing that hosting malware scanners flag. It still works in both directions with sites running Wrensafe 1.1.3 or later, and an interrupted transfer still continues where it stopped.
* Fixed: the receiving site of a Direct Migration checks the backup in short steps, so a large full-site backup no longer ends with "The receiving site had an error (HTTP 500)" on hosts with a 30-second limit.
* Fixed: staging sites have a working administrator again: the copy's roles and capabilities now use the staging site's table prefix (WordPress said "Sorry, you are not allowed to access this page"). Existing staging sites are repaired, and Push to Live turns them back into the live site's.
* Fixed: Push to Live no longer stops half-way, logging you out, after copying the staging site's settings: the live site keeps its address and your login while the push runs.
* Fixed: backups, staging copies and Search & Replace no longer include the tables of another WordPress site that shares the database with a longer table prefix (for example wp_shop_ next to wp_), and a restore leaves those tables alone, also when the backup was made by an earlier version. A multisite network's own site tables are still included in its backups.
* Fixed: the Plugins and Updates screens no longer connect to the file system (FTP on some hosts) on every visit; that check runs when updates start.
* Changed: with "Delete Wrensafe's data" on, uninstalling also removes the copies Safe Updates saved, wherever the storage folder is. The setting and this readme now list everything it removes.

Older versions are in the [full changelog](https://plugins.svn.wordpress.org/wrensafe/trunk/changelog.txt), and under Changelog in the header of every Wrensafe screen.

== Upgrade Notice ==

= 1.3.0 =
Restores now work on servers without PHP's zip extension (Laragon, XAMPP), and huge databases restore far faster. New: AI assistants (MCP, opt-in), Restore Test, Activity Logs as a timeline, guided tours and a Changelog.

= 1.2.8 =
Fix Links shows live progress, the menu shows when something needs you, buttons match on every screen, short screens no longer leave empty space, plus a new review card, the intro video and safer restore starts.

= 1.2.7 =
Fixes broken images after restoring All-in-One WP Migration backups, adds Fix Links (repairs links and finds missing media after a restore), Stop now for update runs, and safer Safe Updates on slow sites.

= 1.2.6 =
Safe Updates in the background get more time to check what they installed and continue after a step is cut off, and protected staging sites delete pages cached earlier.

= 1.2.5 =
Safe Updates in the background get more time to check what they installed and continue after a step is cut off, and protected staging sites delete pages cached earlier.

= 1.2.4 =
Adds Safe Updates, a safety net for plugin updates (turn its question off under Settings → Updates), makes staging password protection work on every web server, and keeps very large backups in short steps on PHP 7.4. Recommended for everyone.

= 1.1.7 =
Visitors see the maintenance notice instead of blank pages while a WooCommerce store is being restored.

= 1.1.6 =
Security hardening for restores of TAR backups and of backup SQL. Recommended for everyone.

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
