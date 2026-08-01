<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

/**
 * Bounded release discovery result safe to project outside the updater.
 */
final readonly class ReleaseDiscovery {

	public function __construct(
		private int $releaseId,
		private string $tag,
		private string $version
	) {
	}

	public function releaseId(): int {
		return $this->releaseId;
	}

	public function tag(): string {
		return $this->tag;
	}

	public function version(): string {
		return $this->version;
	}
}
