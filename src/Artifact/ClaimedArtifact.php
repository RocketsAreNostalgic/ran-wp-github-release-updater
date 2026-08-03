<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressTemporaryFileFactory;
use RuntimeException;

/**
 * Verified local artifact whose cleanup ownership has transferred to the caller.
 */
final class ClaimedArtifact {
	private ?bool $discardResult = null;

	private bool $coreUpdateAccepted = false;

	/**
	 * @param array{dev: int, ino: int, mode: int, nlink: int, uid: int, gid: int, size: int, mtime: int, ctime: int} $identity Frozen file identity.
	 */
	public function __construct(
		private string $path,
		private string $sha256,
		private TemporaryFileFactory $temporaryFiles,
		private array $identity,
		private ?string $coreTargetType = null,
		private ?string $coreTargetIdentifier = null,
		private ?string $coreExpectedVersion = null
	) {
	}

	public function __destruct() {
		$this->discard();
	}

	/**
	 * Mint one exact request-local claim for a Core-owned update archive.
	 */
	public static function forCoreUpdate(
		string $path,
		string $sha256,
		string $targetType,
		string $targetIdentifier,
		string $expectedVersion
	): self {
		$identity = VerifiedArtifact::fileIdentity( $path );
		if ( null === $identity
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $sha256 )
			|| ! in_array( $targetType, array( 'plugin', 'theme' ), true )
			|| '' === $targetIdentifier
			|| strlen( $targetIdentifier ) > 4096
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $targetIdentifier )
			|| '' === $expectedVersion
			|| strlen( $expectedVersion ) > 64
		) {
			throw new RuntimeException( 'The Core update artifact claim is invalid.' );
		}

		return new self(
			$path,
			$sha256,
			new WordPressTemporaryFileFactory(),
			$identity,
			$targetType,
			$targetIdentifier,
			$expectedVersion
		);
	}

	public function path(): string {
		return $this->path;
	}

	/**
	 * Consume this claim once for its exact bound Core update.
	 */
	public function acceptCoreUpdate(
		string $targetType,
		string $targetIdentifier,
		string $action,
		string $path
	): string {
		if ( $this->coreUpdateAccepted
			|| 'update' !== $action
			|| null === $this->coreTargetType
			|| null === $this->coreTargetIdentifier
			|| null === $this->coreExpectedVersion
			|| ! hash_equals( $this->coreTargetType, $targetType )
			|| ! hash_equals( $this->coreTargetIdentifier, $targetIdentifier )
			|| ! hash_equals( $this->path, $path )
		) {
			throw new RuntimeException( 'The Core update artifact claim does not match this operation.' );
		}

		$this->assertUnchanged();
		$this->coreUpdateAccepted = true;

		return $this->coreExpectedVersion;
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
