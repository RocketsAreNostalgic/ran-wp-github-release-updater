<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;

/**
 * One-time claimable verified local artifact.
 */
final class VerifiedArtifact {
	private bool $claimed = false;

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

	public function __destruct() {
		$this->discard();
	}

	public function sha256(): string {
		return $this->sha256;
	}

	/**
	 * Transfer permanent cleanup ownership to the caller.
	 *
	 * @return ClaimedArtifact|\WP_Error
	 */
	public function claim() {
		if ( $this->claimed || null !== $this->discardResult ) {
			return new \WP_Error(
				'github_updater_artifact_already_claimed',
				'The verified artifact can be claimed only once.'
			);
		}

		if ( ! $this->isUnchanged() ) {
			$this->discardResult = false;

			return new \WP_Error(
				'github_updater_artifact_identity_changed',
				'The verified artifact changed before custody transfer.'
			);
		}

		$this->claimed = true;

		return new ClaimedArtifact(
			$this->path,
			$this->sha256,
			$this->temporaryFiles,
			$this->identity
		);
	}

	/**
	 * Inspect the verified temporary file without transferring its cleanup
	 * ownership or exposing its path beyond this synchronous callback.
	 *
	 * @template T
	 * @param callable(string): T $inspector
	 * @return T|\WP_Error
	 */
	public function inspect( callable $inspector ) {
		if ( $this->claimed || null !== $this->discardResult ) {
			return new \WP_Error(
				'github_updater_artifact_unavailable',
				'The verified artifact is no longer available for inspection.'
			);
		}

		if ( ! $this->isUnchanged() ) {
			$this->discardResult = false;

			return new \WP_Error(
				'github_updater_artifact_identity_changed',
				'The verified artifact changed before inspection.'
			);
		}

		return $inspector( $this->path );
	}

	/**
	 * Delete only the exact verified file still owned by the updater.
	 */
	public function discard(): bool {
		if ( null !== $this->discardResult ) {
			return $this->discardResult;
		}
		if ( $this->claimed ) {
			return false;
		}

		$claimed = $this->claim();
		if ( $claimed instanceof \WP_Error ) {
			return false;
		}

		$this->discardResult = $claimed->discard();

		return $this->discardResult;
	}

	private function isUnchanged(): bool {
		clearstatcache( true, $this->path );
		$identity = self::fileIdentity( $this->path );
		$sha256   = is_file( $this->path ) ? hash_file( 'sha256', $this->path ) : false;

		return null !== $identity && $identity === $this->identity && $sha256 === $this->sha256;
	}

	/**
	 * @return array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int}|null
	 */
	public static function fileIdentity( string $path ): ?array {
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $stat
			|| ! is_file( $path )
			|| 0100000 !== ( (int) $stat['mode'] & 0170000 )
		) {
			return null;
		}

		return array(
			'dev'   => (int) $stat['dev'],
			'ino'   => (int) $stat['ino'],
			'mode'  => (int) $stat['mode'],
			'nlink' => (int) $stat['nlink'],
			'uid'   => (int) $stat['uid'],
			'gid'   => (int) $stat['gid'],
			'size'  => (int) $stat['size'],
			'mtime' => (int) $stat['mtime'],
			'ctime' => (int) $stat['ctime'],
		);
	}
}
