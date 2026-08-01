<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Safe result of one release-list operation.
 */
final class ReleaseListResult {
	/**
	 * @param list<ReleaseSummary> $releases Eligible releases.
	 */
	public function __construct(
		private array $releases,
		private ConditionalState $conditional,
		private RateLimit $rateLimit,
		private bool $notModified = false,
		private bool $searchExhausted = false
	) {
	}

	/**
	 * @return list<ReleaseSummary>
	 */
	public function releases(): array {
		return $this->releases;
	}

	public function conditional(): ConditionalState {
		return $this->conditional;
	}

	public function rateLimit(): RateLimit {
		return $this->rateLimit;
	}

	public function isNotModified(): bool {
		return $this->notModified;
	}

	public function isSearchExhausted(): bool {
		return $this->searchExhausted;
	}
}
