<?php
/**
 * Shared state-machine behaviour for BackupJob and RestoreJob.
 *
 * @package WPBP\Core
 */

namespace WPBP\Core;

use WPBP\Core\Database\JobsRepository;

defined( 'ABSPATH' ) || exit;

abstract class AbstractJob {

	const STATUS_PENDING   = 'pending';
	const STATUS_RUNNING   = 'running';
	const STATUS_PAUSED    = 'paused';
	const STATUS_COMPLETED = 'completed';
	const STATUS_FAILED    = 'failed';
	const STATUS_CANCELLED = 'cancelled';

	private array $row;

	private function __construct( array $row ) {
		$this->row = $row;
	}

	/**
	 * The job_type value stored in wp_wpbp_jobs for this job class.
	 */
	abstract protected static function job_type(): string;

	public static function create( array $payload = array() ): self {
		$id  = JobsRepository::create( static::job_type(), $payload );
		$row = JobsRepository::find( $id );

		return new static( $row );
	}

	public static function get( int $id ): ?self {
		$row = JobsRepository::find( $id );

		if ( null === $row || static::job_type() !== $row['job_type'] ) {
			return null;
		}

		return new static( $row );
	}

	private function refresh(): void {
		$row = JobsRepository::find( $this->id() );

		if ( null !== $row ) {
			$this->row = $row;
		}
	}

	public function id(): int {
		return (int) $this->row['id'];
	}

	public function status(): string {
		return (string) $this->row['status'];
	}

	public function progress(): int {
		return (int) $this->row['progress'];
	}

	public function current_step(): string {
		return (string) $this->row['current_step'];
	}

	public function payload(): array {
		$decoded = json_decode( (string) $this->row['payload'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Replace the job's stored payload, e.g. to persist state-machine cursors.
	 */
	public function set_payload( array $payload ): void {
		JobsRepository::update( $this->id(), array( 'payload' => wp_json_encode( $payload ) ) );
		$this->refresh();
	}

	public function result(): array {
		$decoded = json_decode( (string) ( $this->row['result'] ?? '' ), true );

		return is_array( $decoded ) ? $decoded : array();
	}

	public function error_message(): string {
		return (string) ( $this->row['error_message'] ?? '' );
	}

	public function mark_running(): void {
		JobsRepository::update(
			$this->id(),
			array(
				'status'     => self::STATUS_RUNNING,
				'started_at' => current_time( 'mysql' ),
			)
		);
		$this->refresh();
	}

	public function update_progress( int $percent, string $step = '' ): void {
		$data = array( 'progress' => max( 0, min( 100, $percent ) ) );

		if ( '' !== $step ) {
			$data['current_step'] = $step;
		}

		JobsRepository::update( $this->id(), $data );
		$this->refresh();
	}

	/** Job type: 'backup', 'restore', … */
	public function type(): string {
		return static::job_type();
	}

	/**
	 * Fires wpbp_job_finished once, when a job first reaches a final status.
	 */
	private function announce_finished( string $previous_status ): void {
		if ( $previous_status === $this->status() ) {
			return;
		}

		/**
		 * Fires when a backup, restore or other job completes or fails —
		 * from the admin, from a schedule (cron) or from an add-on.
		 *
		 * @param AbstractJob $job
		 * @param string      $status 'completed' or 'failed'.
		 */
		do_action( 'wpbp_job_finished', $this, $this->status() );
	}

	public function mark_completed( array $result = array() ): void {
		$previous = $this->status();

		JobsRepository::update(
			$this->id(),
			array(
				'status'       => self::STATUS_COMPLETED,
				'progress'     => 100,
				'result'       => wp_json_encode( $result ),
				'completed_at' => current_time( 'mysql' ),
			)
		);
		$this->refresh();
		$this->announce_finished( $previous );
	}

	public function mark_failed( string $message ): void {
		$previous = $this->status();

		JobsRepository::update(
			$this->id(),
			array(
				'status'        => self::STATUS_FAILED,
				'error_message' => $message,
				'completed_at'  => current_time( 'mysql' ),
			)
		);
		$this->refresh();
		$this->announce_finished( $previous );
	}

	public function cancel(): void {
		JobsRepository::update(
			$this->id(),
			array(
				'status'       => self::STATUS_CANCELLED,
				'completed_at' => current_time( 'mysql' ),
			)
		);
		$this->refresh();
	}

	public function pause(): void {
		JobsRepository::update( $this->id(), array( 'status' => self::STATUS_PAUSED ) );
		$this->refresh();
	}

	public function resume(): void {
		JobsRepository::update( $this->id(), array( 'status' => self::STATUS_RUNNING ) );
		$this->refresh();
	}

	public function to_array(): array {
		return array(
			'id'            => $this->id(),
			'status'        => $this->status(),
			'progress'      => $this->progress(),
			'current_step'  => $this->current_step(),
			'error_message' => $this->error_message(),
			'result'        => $this->result(),
		);
	}
}
