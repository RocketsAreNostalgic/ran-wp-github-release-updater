<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\VerifiedArtifact;

/**
 * Exact validated package still owned by the updater until explicit handoff.
 */
final class ValidatedReleaseArtifact {

	public function __construct(
		private VerifiedArtifact $artifact,
		private ReleaseInspection $inspection
	) {
	}

	public function inspection(): ReleaseInspection {
		return $this->inspection;
	}

	public function discard(): bool {
		return $this->artifact->discard();
	}

	/**
	 * Transfer cleanup ownership to the WordPress Core caller exactly once.
	 *
	 * @return ClaimedArtifact|\WP_Error
	 */
	public function handoffToCore() {
		return $this->artifact->claim();
	}
}
