<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;

/**
 * Delegates the native adapter's narrow needs to the hook-free service.
 *
 * @internal
 */
final class GitHubReleaseArtifactClient implements ReleaseArtifactClient {

	public function __construct( private GitHubReleaseArtifactService $service ) {
	}

	public function listReleases( ReleaseQuery $query ) {
		return $this->service->listReleases( $query );
	}

	public function describeExact( ExactReleaseRequest $request ) {
		return $this->service->describeExact( $request );
	}

	public function acquireDescribed( ArtifactDescriptor $descriptor ) {
		return $this->service->acquireDescribed( $descriptor );
	}
}
