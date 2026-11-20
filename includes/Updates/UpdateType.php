<?php
/**
 * A kind of update Safe Updates can run (plugins are built in).
 *
 * The engine (SafeUpdateManager) knows nothing about plugins itself: for
 * each item of a run it asks the item's type what has an update, what to
 * save first, how to install the update, which item a fatal error points
 * to, and what to do when a rollback fails. An add-on registers another
 * type through the wpbp_safe_update_types filter (see UpdateTypes).
 *
 * An item is identified by its type key and an id that is unique within
 * the type (a plugin basename for plugins, e.g. "akismet/akismet.php").
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

defined( 'ABSPATH' ) || exit;

interface UpdateType {

	/** The type's key, e.g. "plugin" (lowercase letters, digits, "_" and "-"). */
	public function key(): string;

	/** The type's name in a list of sections, e.g. "Plugins". */
	public function label(): string;

	/** The capability a user needs to update items of this type, e.g. "update_plugins". */
	public function capability(): string;

	/**
	 * Texts for the update window and the Safe Updates page when every item
	 * shown is of this type. Each key is optional and replaces the general
	 * text with the same key (see SafeUpdates::strings()), e.g. 'updatingMany'
	 * ("%d plugins"), 'updateMany', 'progressTitle', 'cancelRun',
	 * 'orderOneHelp', 'orderAllHelp', 'nothingSelected', 'section', 'column',
	 * 'empty', 'selectAll', 'updateSelected', and 'modes' (mode key =>
	 * description).
	 *
	 * @return array<string,mixed>
	 */
	public function strings(): array;

	/**
	 * The items WordPress has an update for, by id. Each item has 'id',
	 * 'name', 'from' (installed version), 'to' (new version), 'active',
	 * 'tested' (WordPress version it was tested with, or '') and 'problem'
	 * (why it can't be updated here, '' when it can).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function available(): array;

	/** The item's version on disk now, read fresh ('' when it is missing). */
	public function installed_version( string $id ): string;

	/** Whether the item is in use on the site (an active plugin, the active theme). */
	public function is_active( string $id ): bool;

	/**
	 * What to save before the update: 'path', the absolute path of the
	 * item's folder (or single file), and 'main', a file that must be in
	 * the copy for it to be usable, relative to the folder's parent
	 * (e.g. "akismet/akismet.php").
	 *
	 * @return array{path:string,main:string}
	 */
	public function folder( string $id ): array;

	/**
	 * Install the updates of these items (WordPress's own upgrader). The
	 * engine has already checked that it can write files.
	 *
	 * A WP_Error with the code 'wpbp_update_incomplete'
	 * (SafeUpdateManager::INCOMPLETE) says the update stopped part-way and
	 * may have left a mix of old and new files: in a safe mode the saved
	 * copy is put back, as for an item whose files went missing.
	 *
	 * @param string[] $ids
	 * @return array<string,true|\WP_Error> Id => true when the new version was installed.
	 */
	public function upgrade( array $ids ): array;

	/**
	 * The item among $ids that a file reported in a fatal error belongs
	 * to, or ''.
	 *
	 * @param string   $file Absolute path (as PHP reported it).
	 * @param string[] $ids
	 */
	public function owner_of_file( string $file, array $ids ): string;

	/**
	 * After the saved copy of an item was put back: leave it as much in use
	 * as before the update (plugins: still active). $item is the run's item
	 * ('id', 'active', 'from', …).
	 *
	 * @param array<string,mixed> $item
	 */
	public function keep_after_rollback( array $item ): void;

	/**
	 * The item broke the site and couldn't be rolled back (or the site still
	 * shows the error after the rollback): do what keeps the site working
	 * (plugins: deactivate). Returns a sentence that says what was done, for
	 * the run's report, or ''.
	 *
	 * @param array<string,mixed> $item
	 */
	public function last_resort( array $item, int $job_id ): string;

	/**
	 * The run is over: remove these updated items from WordPress's list of
	 * available updates (the run kept the list while it worked).
	 *
	 * @param string[] $ids
	 */
	public function forget_update( array $ids ): void;

	/**
	 * What the temporary boot guard needs to keep Wrensafe's own requests
	 * working while these items are installed but not checked yet (see
	 * includes/Updates/guard/safe-update-guard.php):
	 *
	 *  - 'plugins': plugin basenames those requests load without;
	 *  - 'files':   absolute paths of PHP files inside the plugins folder
	 *               that the guard loads on every request while it is on
	 *               (an add-on's own guard code);
	 *  - anything else is kept under the type's key in the guard's data for
	 *    those files to read.
	 *
	 * @param string[] $ids
	 * @return array<string,mixed>
	 */
	public function guard_data( array $ids ): array;
}
