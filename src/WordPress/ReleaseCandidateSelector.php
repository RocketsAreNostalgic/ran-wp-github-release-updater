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
	private const MAX_ZIP_BACKED_CANDIDATES = 2;

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
		?string $installedVersion = null,
		?callable $checkpoint = null
	) {
		$inspected = 0;
		foreach ( $releaseList->releases() as $release ) {
			$blocked = $this->checkpoint( $checkpoint );
			if ( $blocked instanceof \WP_Error ) {
				return $blocked;
			}
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
				&& ReleaseVersion::RELATIONSHIP_NEWER !== ReleaseVersion::relationship(
					$descriptor->version(),
					$installedVersion
				)
			) {
				return array(
					'descriptor' => $descriptor,
					'validation' => null,
				);
			}
			if ( self::MAX_ZIP_BACKED_CANDIDATES === $inspected ) {
				return self::searchBudgetExhausted();
			}
			++$inspected;

			$validation = $this->validate( $descriptor, $target, $assurance, $checkpoint );
			if ( $validation instanceof \WP_Error ) {
				return $validation;
			}
			if ( CandidateValidation::RELEASE_INCOMPATIBLE === $validation->code() ) {
				if ( self::MAX_ZIP_BACKED_CANDIDATES === $inspected ) {
					return self::searchBudgetExhausted();
				}
				continue;
			}

			return array(
				'descriptor' => $descriptor,
				'validation' => $validation,
			);
		}

		return $releaseList->isSearchExhausted()
			? self::searchBudgetExhausted()
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
		ReleaseAssurance $assurance,
		?callable $checkpoint = null
	): CandidateValidation|\WP_Error {
		$blocked = $this->checkpoint( $checkpoint );
		if ( $blocked instanceof \WP_Error ) {
			return $blocked;
		}
		$artifact = $this->artifacts->acquireDescribed( $descriptor );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		try {
			$blocked = $this->checkpoint( $checkpoint );
			if ( $blocked instanceof \WP_Error ) {
				return $blocked;
			}
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

	private function checkpoint( ?callable $checkpoint ): ?\WP_Error {
		if ( null === $checkpoint ) {
			return null;
		}
		$result = $checkpoint();
		return $result instanceof \WP_Error ? $result : null;
	}

	private static function searchBudgetExhausted(): \WP_Error {
		return new \WP_Error(
			'github_updater_release_search_budget_exhausted',
			'The bounded release search did not find a compatible candidate.'
		);
	}
}
