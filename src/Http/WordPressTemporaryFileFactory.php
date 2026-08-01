<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Http;

/**
 * WordPress temporary-file implementation.
 */
final class WordPressTemporaryFileFactory implements TemporaryFileFactory {
	/**
	 * @return string|\WP_Error
	 */
	public function create( string $filename ) {
		$path = wp_tempnam( $filename );
		if ( false === $path ) {
			return new \WP_Error(
				'github_updater_temp_file_failed',
				'WordPress could not create a temporary update file.'
			);
		}

		@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $path;
	}

	public function delete( string $path ): void {
		if ( '' !== $path && ( file_exists( $path ) || is_link( $path ) ) ) {
			wp_delete_file( $path );
		}
	}
}
