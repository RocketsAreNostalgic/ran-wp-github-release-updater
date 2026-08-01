<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;

/**
 * Optional capability used by prospective release inspection.
 *
 * @internal
 */
interface ProspectiveReleaseArtifactClient extends ReleaseArtifactClient {

	/** @return bool|\WP_Error */
	public function isCommitReachableFromBranch(
		ReleaseQuery $query,
		string $commit,
		string $branch
	);
}
