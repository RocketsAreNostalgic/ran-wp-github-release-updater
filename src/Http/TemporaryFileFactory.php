<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Http;

/**
 * Creates and removes private temporary files.
 */
interface TemporaryFileFactory {
	/**
	 * @return string|\WP_Error
	 */
	public function create( string $filename );

	public function delete( string $path ): void;
}
