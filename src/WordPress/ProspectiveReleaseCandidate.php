<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

/**
 * Bounded, display-safe summary of one published release candidate.
 *
 * Exact inspection remains responsible for validating the ZIP headers,
 * compatibility, artifact identity and default-branch reachability.
 */
final readonly class ProspectiveReleaseCandidate {

	/**
	 * @param list<string> $expectedAssetNames
	 */
	public function __construct(
		private int $releaseId,
		private string $tag,
		private string $version,
		private bool $prerelease,
		private string $publishedAt,
		private array $expectedAssetNames
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
}
