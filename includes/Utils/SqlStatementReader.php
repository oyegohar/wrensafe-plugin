<?php
/**
 * Reads a SQL dump file one complete statement at a time.
 *
 * Unlike a line-based reader, this understands quoted strings, backtick
 * identifiers, and comments, so a semicolon inside a value or a multi-line
 * CREATE TABLE statement never causes a statement to be split incorrectly.
 * The file is streamed in fixed-size chunks rather than loaded whole.
 *
 * @package WPBP\Utils
 */

namespace WPBP\Utils;

defined( 'ABSPATH' ) || exit;

// Backup archives and upload chunks are streamed in bounded blocks with native
// file handles (fopen/fread/fwrite/rename): WP_Filesystem has no streaming API.
// Every path is inside Wrensafe's protected storage directory, a PHP upload
// temp file, or validated by WPBP\Security\PathValidator.
// phpcs:disable WordPress.WP.AlternativeFunctions

class SqlStatementReader {

	const CHUNK_SIZE = 65536;

	/**
	 * Per state, the characters that can change it. Everything between them
	 * is taken in one run (strcspn) instead of one character at a time,
	 * which made reading a multi-gigabyte dump slow in PHP.
	 */
	private const STOPS = array(
		'normal'        => "'\"`-#/;",
		'single_quote'  => "'\\",
		'double_quote'  => '"\\',
		'backtick'      => '`',
		'line_comment'  => "\n",
		'block_comment' => '*',
	);

	/** @var resource|null */
	private $handle = null;

	private int $base_offset  = 0;
	private string $buffer    = '';
	private int $buf_pos      = 0;
	private int $stmt_end_pos = 0;

	/**
	 * Open a SQL file, optionally resuming from a byte offset returned by a
	 * previous call to last_stmt_end().
	 */
	public function open( string $path, int $offset = 0 ): bool {
		$handle = fopen( $path, 'rb' );

		if ( false === $handle ) {
			return false;
		}

		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		}

		$this->handle       = $handle;
		$this->base_offset  = $offset;
		$this->buffer       = '';
		$this->buf_pos      = 0;
		$this->stmt_end_pos = $offset;

		return true;
	}

	public function close(): void {
		if ( null !== $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	/**
	 * True once every statement has been consumed.
	 */
	public function is_eof(): bool {
		if ( null === $this->handle ) {
			return true;
		}

		if ( $this->buf_pos < strlen( $this->buffer ) ) {
			return false;
		}

		return feof( $this->handle );
	}

	/**
	 * Byte position immediately after the last statement returned by
	 * read_statement(). Pass this to open() to resume a later chunk.
	 */
	public function last_stmt_end(): int {
		return $this->stmt_end_pos;
	}

	/**
	 * Read and return the next complete statement, trimmed and without its
	 * trailing semicolon. Returns null when there is nothing left to read.
	 */
	public function read_statement(): ?string {
		$stmt  = '';
		$state = 'normal';

		while ( true ) {
			// Characters that can't change the state: taken as one run.
			if ( $this->ensure_buffer() ) {
				$run = strcspn( $this->buffer, self::STOPS[ $state ], $this->buf_pos );

				if ( $run > 0 ) {
					if ( 'line_comment' !== $state && 'block_comment' !== $state ) {
						$stmt .= substr( $this->buffer, $this->buf_pos, $run );
					}

					$this->buf_pos += $run;
					continue;
				}
			}

			$ch = $this->read_char();

			if ( null === $ch ) {
				break;
			}

			switch ( $state ) {
				case 'normal':
					if ( "'" === $ch ) {
						$state = 'single_quote';
						$stmt .= $ch;
					} elseif ( '"' === $ch ) {
						$state = 'double_quote';
						$stmt .= $ch;
					} elseif ( '`' === $ch ) {
						$state = 'backtick';
						$stmt .= $ch;
					} elseif ( '-' === $ch && '-' === $this->peek_char() ) {
						$this->read_char();
						$state = 'line_comment';
					} elseif ( '#' === $ch ) {
						// MySQL "#" comment (UpdraftPlus and phpMyAdmin dumps use them).
						$state = 'line_comment';
					} elseif ( '/' === $ch && '*' === $this->peek_char() ) {
						$this->read_char();
						$state = 'block_comment';
					} elseif ( ';' === $ch ) {
						$this->stmt_end_pos = $this->current_pos();
						$trimmed            = trim( $stmt );

						if ( '' !== $trimmed ) {
							return $trimmed;
						}

						$stmt = '';
					} else {
						$stmt .= $ch;
					}
					break;

				case 'single_quote':
					$stmt .= $ch;

					if ( '\\' === $ch ) {
						$next = $this->read_char();
						if ( null !== $next ) {
							$stmt .= $next;
						}
					} elseif ( "'" === $ch ) {
						if ( "'" === $this->peek_char() ) {
							$stmt .= $this->read_char();
						} else {
							$state = 'normal';
						}
					}
					break;

				case 'double_quote':
					$stmt .= $ch;

					if ( '\\' === $ch ) {
						$next = $this->read_char();
						if ( null !== $next ) {
							$stmt .= $next;
						}
					} elseif ( '"' === $ch ) {
						if ( '"' === $this->peek_char() ) {
							$stmt .= $this->read_char();
						} else {
							$state = 'normal';
						}
					}
					break;

				case 'backtick':
					$stmt .= $ch;

					if ( '`' === $ch ) {
						if ( '`' === $this->peek_char() ) {
							$stmt .= $this->read_char();
						} else {
							$state = 'normal';
						}
					}
					break;

				case 'line_comment':
					if ( "\n" === $ch ) {
						$state = 'normal';
						$stmt .= "\n";
					}
					break;

				case 'block_comment':
					if ( '*' === $ch && '/' === $this->peek_char() ) {
						$this->read_char();
						$state = 'normal';
						$stmt .= ' ';
					}
					break;
			}
		}

		$trimmed = trim( $stmt );

		if ( '' !== $trimmed ) {
			$this->stmt_end_pos = $this->current_pos();
			return $trimmed;
		}

		return null;
	}

	private function ensure_buffer(): bool {
		if ( $this->buf_pos < strlen( $this->buffer ) ) {
			return true;
		}

		if ( null === $this->handle || feof( $this->handle ) ) {
			return false;
		}

		$this->base_offset += strlen( $this->buffer );
		$this->buf_pos      = 0;
		$this->buffer       = '';

		$chunk = fread( $this->handle, self::CHUNK_SIZE );

		if ( false !== $chunk && '' !== $chunk ) {
			$this->buffer = $chunk;
			return true;
		}

		return false;
	}

	private function read_char(): ?string {
		if ( ! $this->ensure_buffer() ) {
			return null;
		}

		return $this->buffer[ $this->buf_pos++ ];
	}

	private function peek_char(): ?string {
		if ( ! $this->ensure_buffer() ) {
			return null;
		}

		return $this->buffer[ $this->buf_pos ];
	}

	private function current_pos(): int {
		return $this->base_offset + $this->buf_pos;
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions
