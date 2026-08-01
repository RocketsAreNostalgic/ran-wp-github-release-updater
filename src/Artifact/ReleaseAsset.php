<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Path-free GitHub asset identity.
 */
final class ReleaseAsset {
	public function __construct(
		private int $id,
		private string $name,
		private int $size,
		private string $sha256
	) {
	}

	public function id(): int {
		return $this->id;
	}

	public function name(): string {
		return $this->name;
	}

	public function size(): int {
		return $this->size;
	}

	public function sha256(): string {
		return $this->sha256;
	}

	public function equals( self $other ): bool {
		return $this->id === $other->id
			&& $this->name === $other->name
			&& $this->size === $other->size
			&& $this->sha256 === $other->sha256;
	}
}
