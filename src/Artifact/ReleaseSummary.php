<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Bounded published-release projection.
 */
final class ReleaseSummary {
	/**
	 * @param list<string> $expectedAssetNames
	 */
	public function __construct(
		private int $releaseId,
		private string $tag,
		private string $version,
		private bool $prerelease = false,
		private string $publishedAt = '',
		private array $expectedAssetNames = array(),
		private bool $immutable = false
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

	public function isPrerelease(): bool {
		return $this->prerelease;
	}

	public function publishedAt(): string {
		return $this->publishedAt;
	}

	/**
	 * @return list<string>
	 */
	public function expectedAssetNames(): array {
		return $this->expectedAssetNames;
	}

	public function isImmutable(): bool {
		return $this->immutable;
	}
}
