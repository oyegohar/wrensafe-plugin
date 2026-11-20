<?php
/**
 * An update type whose items aren't one folder saves and puts back its own
 * copy, instead of the ZIP of folder() that RestorePoint makes.
 *
 * The engine (SafeUpdateManager) asks an update type that implements this
 * interface, as well as UpdateType, for every step of the copy: start it
 * before the update, continue it in bounded steps, put it back when the
 * site check fails (in as many steps as it needs), and remove it. The
 * copy's state is kept with the run's item ('point'), so it must be plain
 * data (strings, numbers, booleans and arrays of them), and it must have
 * 'done' => true once the copy is complete: the engine rolls an item back
 * only when its copy is done.
 *
 * @package WPBP\Updates
 */

namespace WPBP\Updates;

defined( 'ABSPATH' ) || exit;

interface OwnRestorePoint {

	/**
	 * Start the copy of an item, right before its update (the run's backup,
	 * if any, is complete by then). Throws an exception, with a message for
	 * the administrator, when no usable copy can be made: the item is then
	 * skipped, not updated.
	 *
	 * @param array<string,mixed> $item The run's item ('type', 'id', 'name', 'from', 'to', …).
	 * @return array<string,mixed> The copy's state.
	 */
	public function begin_point( int $job_id, array $item ): array;

	/**
	 * Continue the copy until $deadline (a microtime(true) value). True once
	 * it is complete ('done' set in $point).
	 *
	 * @param array<string,mixed> $point State from begin_point(), updated in place.
	 */
	public function build_point( array &$point, float $deadline ): bool;

	/**
	 * Put the copy back. Returns true when the item is as it was before the
	 * update, false when it needs another step (the engine calls again on
	 * the run's next step with $point as it was left, so every step must be
	 * safe to stop after), or a WP_Error when it can't be put back; the item
	 * must then be left in a working state.
	 *
	 * @param array<string,mixed> $point A finished copy, updated in place.
	 * @param array<string,mixed> $item  The run's item.
	 * @return true|false|\WP_Error
	 */
	public function restore_point( array &$point, array $item, int $job_id, float $deadline );

	/**
	 * The run ends while restore_point() hasn't finished (it stopped with an
	 * error, or nothing continued it): finish the rollback now or undo the
	 * part done, so the item is left consistent. True when the previous
	 * version is back, otherwise a WP_Error that says what was kept.
	 *
	 * @param array<string,mixed> $point
	 * @param array<string,mixed> $item
	 * @return true|\WP_Error
	 */
	public function settle_point( array &$point, array $item, int $job_id );

	/**
	 * Remove the copy's files (a finished copy or one left half-written).
	 *
	 * @param array<string,mixed> $point
	 */
	public function delete_point( array $point ): void;

	/**
	 * Whether an item updated in a finished run can still be rolled back
	 * from its copy (the copy is there and nothing changed since that it
	 * can't undo).
	 *
	 * @param array<string,mixed> $point
	 * @param array<string,mixed> $item
	 */
	public function point_usable( array $point, array $item ): bool;
}
