<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Request for one immutable GitHub Release identity.
 */
final class ExactReleaseRequest {
	public function __construct(
		private ReleaseQuery $query,
		private int $releaseId,
		private ?string $expectedTag = null
	) {
	}

	public function query(): ReleaseQuery {
		return $this->query;
	}

	public function releaseId(): int {
		return $this->releaseId;
	}

	public function expectedTag(): ?string {
		return $this->expectedTag;
	}
}
