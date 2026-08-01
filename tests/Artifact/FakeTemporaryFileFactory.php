<?php

declare(strict_types=1);

namespace Tests\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;

/**
 * Private temporary-file fake with deletion observations.
 */
final class FakeTemporaryFileFactory implements TemporaryFileFactory {
	/**
	 * @var list<string>
	 */
	private array $deleted = array();

	public function __construct( private bool $throwOnDelete = false ) {
	}

	/**
	 * @return string|\WP_Error
	 */
	public function create( string $filename ) {
		unset( $filename );
		$path = tempnam( sys_get_temp_dir(), 'ran-artifact-' );
		if ( false === $path ) {
			return new \WP_Error( 'fake_temp_failed', 'Could not create fake temporary file.' );
		}
		chmod( $path, 0600 );

		return $path;
	}

	public function delete( string $path ): void {
		$this->deleted[] = $path;
		if ( $this->throwOnDelete ) {
			throw new \RuntimeException( 'Simulated temporary-file cleanup failure.' );
		}
		if ( file_exists( $path ) || is_link( $path ) ) {
			unlink( $path );
		}
	}

	/**
	 * @return list<string>
	 */
	public function deleted(): array {
		return $this->deleted;
	}
}
