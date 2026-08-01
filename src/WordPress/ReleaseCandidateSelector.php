<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseListResult;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseVersion;

/**
 * Selects and validates release candidates without owning caller cache policy.
 *
 * @internal
 */
final class ReleaseCandidateSelector {

	public function __construct( private ReleaseArtifactClient $artifacts ) {
	}

	/**
	 * Resolve and inspect candidates, falling back only for incompatibility.
	 *
	 * @return array{descriptor: ArtifactDescriptor, validation: ?CandidateValidation}|\WP_Error|null
	 */
	public function select(
		ReleaseListResult $releaseList,
		ReleaseQuery $query,
		PackageIdentityTarget $target,
		ReleaseAssurance $assurance,
		?string $installedVersion = null
	) {
		foreach ( $releaseList->releases() as $release ) {
			$descriptor = $this->artifacts->describeExact(
				new ExactReleaseRequest( $query, $release->releaseId(), $release->tag() )
			);
			if ( $descriptor instanceof \WP_Error ) {
				return $descriptor;
			}
			if ( ReleaseQuery::STABLE === $query->channel()
				&& (
					$descriptor->isPrerelease()
					|| ReleaseVersion::isPrerelease( $descriptor->version() )
				)
			) {
				continue;
			}
			if ( null !== $installedVersion
				&& version_compare( $descriptor->version(), $installedVersion, '<=' )
			) {
				return array(
					'descriptor' => $descriptor,
					'validation' => null,
				);
			}

			$validation = $this->validate( $descriptor, $target, $assurance );
			if ( $validation instanceof \WP_Error ) {
				return $validation;
			}
			if ( CandidateValidation::RELEASE_INCOMPATIBLE === $validation->code() ) {
				continue;
			}

			return array(
				'descriptor' => $descriptor,
				'validation' => $validation,
			);
		}

		return $releaseList->isSearchExhausted()
			? new \WP_Error(
				'github_updater_release_search_budget_exhausted',
				'The bounded release search did not find a compatible candidate.'
			)
			: null;
	}

	/**
	 * Acquire and inspect the exact verified release ZIP.
	 *
	 * @return CandidateValidation|\WP_Error
	 */
	public function validate(
		ArtifactDescriptor $descriptor,
		PackageIdentityTarget $target,
		ReleaseAssurance $assurance
	): CandidateValidation|\WP_Error {
		$artifact = $this->artifacts->acquireDescribed( $descriptor );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		try {
			$validation = ( new ReleasePackageIdentityValidator() )->validate(
				$artifact,
				$descriptor,
				$target
			);
			if ( $validation->isReady() ) {
				$rejection = $assurance->check(
					$descriptor,
					$validation,
					$artifact->sha256()
				);
				if ( $rejection instanceof \WP_Error ) {
					return $rejection;
				}
			}

			return $validation;
		} finally {
			$artifact->discard();
		}
	}
}
