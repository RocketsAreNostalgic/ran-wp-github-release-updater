<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;
use RuntimeException;

/**
 * Verified local artifact whose cleanup ownership has transferred to the caller.
 */
final class ClaimedArtifact {
	private ?bool $discardResult = null;

	/**
	 * @param array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int} $identity Frozen file identity.
	 */
	public function __construct(
		private string $path,
		private string $sha256,
		private TemporaryFileFactory $temporaryFiles,
		private array $identity
	) {
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Prove that the caller still holds the exact verified bytes.
	 *
	 * @return array{
	 *     sha256: string,
	 *     identity: array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int}
	 * }
	 */
	public function assertUnchanged(): array {
		if ( null !== $this->discardResult ) {
			throw new RuntimeException( 'The claimed artifact cleanup has already been attempted.' );
		}

		clearstatcache( true, $this->path );
		$identity = VerifiedArtifact::fileIdentity( $this->path );
		$sha256   = is_file( $this->path ) ? hash_file( 'sha256', $this->path ) : false;
		if ( null === $identity || $identity !== $this->identity || $sha256 !== $this->sha256 ) {
			throw new RuntimeException( 'The claimed artifact changed after custody transfer.' );
		}

		return array(
			'sha256'   => $this->sha256,
			'identity' => $this->identity,
		);
	}

	/**
	 * Delete only the exact verified file whose custody was claimed.
	 */
	public function discard(): bool {
		if ( null !== $this->discardResult ) {
			return $this->discardResult;
		}

		try {
			$this->assertUnchanged();
		} catch ( \Throwable ) {
			$this->discardResult = false;
			return false;
		}

		try {
			$this->temporaryFiles->delete( $this->path );
		} catch ( \Throwable ) {
			$this->discardResult = false;
			return false;
		}

		clearstatcache( true, $this->path );
		$this->discardResult = ! file_exists( $this->path ) && ! is_link( $this->path );

		return $this->discardResult;
	}
}
