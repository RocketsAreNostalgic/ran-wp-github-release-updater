<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\AccessToken;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseListResult;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseSummary;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressSafeHttpTransport;

/**
 * WordPress-facing release identity preflight for managed consumers.
 */
final class ReleaseCandidatePreflight {
	public const PROSPECTIVE_API_VERSION = 4;

	/**
	 * Request-local capability for a Core-owned exact-release handoff.
	 *
	 * The native updater normally rejects any earlier pre-download reply. A
	 * managed consumer may admit only the frozen artifact it already acquired
	 * through this updater's exact-version preflight, for the matching Core
	 * update operation. The callback must return strict true to admit it.
	 */
	public const CORE_REINSTALL_HANDOFF_FILTER = 'ran_wp_github_release_updater_v1_core_reinstall_handoff';

	private const CACHE_SCHEMA = 4;

	private const FAILURE_COOLDOWN = 60;

	private ReleaseCandidateSelector $candidates;

	private ReleaseOperationCoordinator $operations;

	/** @var callable(): int */
	private $clock;

	private function __construct(
		private ReleaseArtifactClient $artifacts,
		private Repository $repository,
		private string $packageRoot,
		private string $headerFile,
		private string $channel,
		private AccessToken $accessToken,
		private string $packageType,
		private int $cacheDuration,
		private bool $prospective,
		private ReleaseAssurance $assurance,
		?callable $clock = null
	) {
		$this->candidates = new ReleaseCandidateSelector( $artifacts );
		$this->operations = new ReleaseOperationCoordinator();
		$this->clock      = $clock ?? static fn (): int => time();
	}

	/**
	 * Build the released cached preflight for one known package identity.
	 *
	 * @param array<string, mixed> $target
	 * @return self|\WP_Error
	 */
	public static function fromTarget(
		array $target,
		?ReleaseArtifactClient $artifacts = null,
		?callable $clock = null
	) {
		$repositoryName = $target['repository'] ?? null;
		$repositoryId   = $target['providerRepositoryId'] ?? null;
		if ( ! is_string( $repositoryName )
			|| ! is_string( $repositoryId )
		) {
			return self::invalidTarget();
		}
		$repository  = Repository::fromString( $repositoryName, $repositoryId );
		$token       = AccessToken::fromValue( $target['accessToken'] ?? null );
		$packageRoot = $target['pluginSlug'] ?? null;
		$headerFile  = $target['mainFile'] ?? null;
		$channel     = $target['channel'] ?? ReleaseQuery::STABLE;
		$type        = $target['packageType'] ?? PackageIdentityTarget::PLUGIN;
		$themeRoot   = $target['themeRoot'] ?? null;
		$duration    = $target['cacheDuration'] ?? 21600;
		if ( PackageIdentityTarget::THEME === $type ) {
			$packageRoot = $themeRoot;
			$headerFile  = 'style.css';
		}
		if ( $repository instanceof \WP_Error || $token instanceof \WP_Error
			|| ! is_string( $packageRoot )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $packageRoot )
			|| ! is_string( $headerFile )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.(?:php|css)\z/D', $headerFile )
			|| ( PackageIdentityTarget::PLUGIN === $type && ! str_ends_with( $headerFile, '.php' ) )
			|| ( PackageIdentityTarget::THEME === $type && 'style.css' !== $headerFile )
			|| ! in_array( $channel, array( ReleaseQuery::STABLE, ReleaseQuery::PRERELEASE ), true )
			|| ! in_array( $type, array( PackageIdentityTarget::PLUGIN, PackageIdentityTarget::THEME ), true )
			|| ! is_int( $duration )
			|| $duration < 300
			|| $duration > 86400
		) {
			return self::invalidTarget();
		}

		return new self(
			$artifacts ?? self::defaultArtifacts(),
			$repository,
			$packageRoot,
			$headerFile,
			$channel,
			$token,
			$type,
			$duration,
			false,
			ReleaseAssurance::selected(),
			$clock
		);
	}

	/**
	 * Build an always-fresh preflight for a not-yet-installed package.
	 *
	 * @param array<string, mixed> $target
	 * @return self|\WP_Error
	 */
	public static function fromProspectiveTarget( array $target ) {
		return self::fromProspectiveTargetWithClient( $target, self::defaultArtifacts() );
	}

	/**
	 * Non-public test seam. A prospective caller must never supply the client
	 * that receives credential-bearing internal release queries.
	 *
	 * @param array<string, mixed> $target
	 * @return self|\WP_Error
	 */
	private static function fromProspectiveTargetWithClient(
		array $target,
		ReleaseArtifactClient $artifacts
	) {
		$repositoryName = $target['repository'] ?? null;
		$repositoryId   = $target['providerRepositoryId'] ?? null;
		if ( ! is_string( $repositoryName )
			|| ! is_string( $repositoryId )
		) {
			return self::invalidTarget();
		}
		$repository = Repository::fromString( $repositoryName, $repositoryId );
		$token      = AccessToken::fromValue( $target['accessToken'] ?? null );
		$channel    = $target['channel'] ?? ReleaseQuery::STABLE;
		$type       = $target['packageType'] ?? PackageIdentityTarget::PLUGIN;
		if ( $repository instanceof \WP_Error || $token instanceof \WP_Error
			|| ! in_array( $channel, array( ReleaseQuery::STABLE, ReleaseQuery::PRERELEASE ), true )
			|| ! in_array( $type, array( PackageIdentityTarget::PLUGIN, PackageIdentityTarget::THEME ), true )
		) {
			return self::invalidTarget();
		}

		return new self(
			$artifacts,
			$repository,
			'',
			'',
			$channel,
			$token,
			$type,
			21600,
			true,
			ReleaseAssurance::selected()
		);
	}

	/**
	 * Discover the newest release allowed by the configured channel.
	 *
	 * @return ReleaseDiscovery|\WP_Error
	 */
	public function discover() {
		if ( ! $this->prospective ) {
			return self::prospectiveUnavailable();
		}

		$list = $this->prospectiveReleaseList();
		if ( $list instanceof \WP_Error ) {
			return $list;
		}
		$release = $list->releases()[0] ?? null;
		if ( null === $release ) {
			return self::noProspectiveRelease( $list );
		}

		return new ReleaseDiscovery(
			$release->releaseId(),
			$release->tag(),
			$release->version()
		);
	}

	/**
	 * List the bounded published releases allowed by the configured channel.
	 *
	 * These summaries are suitable for selection UI, but are not installation
	 * approval. Call inspectExact() for the selected release before installing.
	 *
	 * @return list<ProspectiveReleaseCandidate>|\WP_Error
	 */
	public function listCandidates() {
		if ( ! $this->prospective ) {
			return self::prospectiveUnavailable();
		}

		$list = $this->prospectiveReleaseList();
		if ( $list instanceof \WP_Error ) {
			return $list;
		}
		if ( array() === $list->releases() ) {
			return self::noProspectiveRelease( $list );
		}

		return array_map(
			static fn ( ReleaseSummary $release ): ProspectiveReleaseCandidate =>
				new ProspectiveReleaseCandidate(
					$release->releaseId(),
					$release->tag(),
					$release->version(),
					$release->isPrerelease(),
					$release->publishedAt(),
					$release->expectedAssetNames()
				),
			array_slice(
				$list->releases(),
				0,
				ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS
			)
		);
	}

	/**
	 * Inspect and discard one exact release ZIP.
	 *
	 * @return ReleaseInspection|\WP_Error
	 */
	public function inspectExact( int $releaseId, string $tag ) {
		$client = $this->prospectiveClient();
		if ( $client instanceof \WP_Error ) {
			return $client;
		}

		$query      = $this->query();
		$descriptor = $client->describeExact(
			new ExactReleaseRequest( $query, $releaseId, $tag )
		);
		if ( $descriptor instanceof \WP_Error ) {
			return $descriptor;
		}
		$artifact = $client->acquireDescribed( $descriptor );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		try {
			$validation = ( new ReleasePackageIdentityValidator() )->validateProspective(
				$artifact,
				$descriptor,
				$this->packageType,
				$this->expectedUpdateUri()
			);
			if ( $validation instanceof \WP_Error ) {
				return $validation;
			}
			if ( ! $validation->isReady() ) {
				return new \WP_Error(
					$validation->code(),
					'The exact release package identity is invalid.'
				);
			}
			$rejection = $this->assurance->check(
				$descriptor,
				$validation,
				$artifact->sha256()
			);
			if ( $rejection instanceof \WP_Error ) {
				return $rejection;
			}
			$fingerprint = ReleaseFingerprint::fromDescriptor( $descriptor, $validation );

			return ReleaseInspection::fromDescriptor(
				$descriptor,
				$validation,
				$fingerprint
			);
		} catch ( \Throwable ) {
			return new \WP_Error(
				'github_updater_release_artifact_unavailable',
				'The exact release archive could not be validated.'
			);
		} finally {
			$artifact->discard();
		}
	}

	/**
	 * Reconstruct, acquire and verify the administrator-approved release.
	 *
	 * @return ValidatedReleaseArtifact|\WP_Error
	 */
	public function acquireExact(
		int $releaseId,
		string $tag,
		ReleaseFingerprint $expectedFingerprint
	) {
		$client = $this->prospectiveClient();
		if ( $client instanceof \WP_Error ) {
			return $client;
		}

		$query      = $this->query();
		$descriptor = $client->describeExact(
			new ExactReleaseRequest( $query, $releaseId, $tag )
		);
		if ( $descriptor instanceof \WP_Error ) {
			return $descriptor;
		}
		$artifact = $client->acquireDescribed( $descriptor );
		if ( $artifact instanceof \WP_Error ) {
			return $artifact;
		}

		try {
			$validation = ( new ReleasePackageIdentityValidator() )->validateProspective(
				$artifact,
				$descriptor,
				$this->packageType,
				$this->expectedUpdateUri()
			);
			if ( $validation instanceof \WP_Error ) {
				$artifact->discard();
				return $validation;
			}
			if ( ! $validation->isReady() ) {
				$artifact->discard();
				return new \WP_Error(
					$validation->code(),
					'The exact release package identity is invalid.'
				);
			}
			$fingerprint = ReleaseFingerprint::fromDescriptor( $descriptor, $validation );
			if ( ! $fingerprint->equals( $expectedFingerprint ) ) {
				$artifact->discard();
				return new \WP_Error(
					'github_updater_artifact_continuity_failed',
					'The exact GitHub Release or artifact identity changed after inspection.'
				);
			}
			$rejection = $this->assurance->check(
				$descriptor,
				$validation,
				$artifact->sha256()
			);
			if ( $rejection instanceof \WP_Error ) {
				$artifact->discard();
				return $rejection;
			}

			return new ValidatedReleaseArtifact(
				$artifact,
				ReleaseInspection::fromDescriptor(
					$descriptor,
					$validation,
					$fingerprint
				)
			);
		} catch ( \Throwable ) {
			$artifact->discard();
			return new \WP_Error(
				'github_updater_release_artifact_unavailable',
				'The exact release archive could not be validated.'
			);
		}
	}

	/**
	 * Return a cached or freshly verified release identity verdict.
	 * `true` forces a fresh discovery and archive inspection.
	 *
	 * @return CandidateValidation|\WP_Error
	 */
	public function check( bool $force = false ) {
		if ( $this->prospective ) {
			return self::prospectiveUnavailable();
		}

		$cacheRevision = $this->assurance->cacheRevision();
		$cached        = null === $cacheRevision
			? array()
			: $this->operations->state(
				$this->coordinationTargetKey(),
				ReleaseOperationCoordinator::MANAGED_STATE
			);
		if ( ! is_string( $cached['_cache_key'] ?? null )
			|| ! hash_equals( $this->cacheKey(), $cached['_cache_key'] )
		) {
			$cached = array();
		}
		if ( ! $force
			&& is_int( $cached['cooldown_until'] ?? null )
			&& $cached['cooldown_until'] > $this->now()
		) {
			$code = is_string( $cached['error_code'] ?? null )
				? $cached['error_code']
				: 'github_updater_release_check_failed';
			return new \WP_Error(
				$code,
				'The release check is in a bounded failure cooldown.',
				array(
					'retryable'      => true,
					'cooldown_until' => $cached['cooldown_until'],
				)
			);
		}
		if ( ! $force && null !== $cacheRevision && self::CACHE_SCHEMA === ( $cached['schema'] ?? null )
			&& is_int( $cached['checked_at'] ?? null ) && $this->now() - $cached['checked_at'] < $this->cacheDuration
		) {
			$result = CandidateValidation::fromArray( $cached['validation'] ?? array() );
			if ( null !== $result ) {
				return $result;
			}
		}

		$claim = $this->operations->acquire(
			$this->coordinationTargetKey(),
			'managed_preflight:' . $this->cacheKey(),
			$this->operations->discoveryLeaseSeconds()
		);
		if ( $claim instanceof \WP_Error ) {
			if ( 'github_updater_operation_busy' === $claim->get_error_code() ) {
				return new \WP_Error(
					'github_updater_check_in_progress',
					'Another release check is already in progress for this target.',
					array( 'retryable' => true )
				);
			}
			return $claim;
		}

		try {
			$query = $this->query();
			$list  = $this->artifacts->listReleases( $query );
			if ( $list instanceof \WP_Error ) {
				$this->publishManagedFailure( $claim, self::errorCode( $list ), self::FAILURE_COOLDOWN );
				return $list;
			}
			$renewed = $this->operations->renew(
				$claim,
				$this->operations->discoveryLeaseSeconds()
			);
			if ( $renewed instanceof \WP_Error ) {
				return $renewed;
			}
			$claim = $renewed;
			if ( $list->rateLimit()->isLimited() ) {
				$this->publishManagedFailure(
					$claim,
					'github_updater_rate_limited',
					$list->rateLimit()->cooldownSeconds() ?? self::FAILURE_COOLDOWN
				);
				return self::rateLimitError( $list );
			}

			$target = $this->target();
			if ( $target instanceof \WP_Error ) {
				return $target;
			}
			$checkpoint = function () use ( &$claim ): ?\WP_Error {
				$renewed = $this->operations->renew(
					$claim,
					$this->operations->discoveryLeaseSeconds()
				);
				if ( $renewed instanceof \WP_Error ) {
					return $renewed;
				}
				$claim = $renewed;
				return null;
			};
			$selected   = $this->candidates->select(
				$list,
				$query,
				$target,
				$this->assurance,
				null,
				$checkpoint
			);
			if ( $selected instanceof \WP_Error ) {
				$this->publishManagedFailure(
					$claim,
					self::errorCode( $selected ),
					self::FAILURE_COOLDOWN
				);
				return $selected;
			}
			if ( null === $selected ) {
				return new \WP_Error(
					'github_updater_no_eligible_release',
					'No eligible published release is available for validation.'
				);
			}
			$result = $selected['validation'];
			if ( null === $result ) {
				throw new \LogicException( 'A managed preflight selection must inspect its release ZIP.' );
			}
			if ( null !== $cacheRevision ) {
				$published = $this->operations->publish(
					$claim,
					ReleaseOperationCoordinator::MANAGED_STATE,
					array(
						'schema'     => self::CACHE_SCHEMA,
						'_cache_key' => $this->cacheKey(),
						'checked_at' => $this->now(),
						'validation' => $result->toArray(),
					)
				);
				if ( $published instanceof \WP_Error ) {
					return $published;
				}
				$claim = null;
			}

			return $result;
		} finally {
			if ( $claim instanceof ReleaseOperationClaim ) {
				$this->operations->release( $claim );
			}
		}
	}

	private static function defaultArtifacts(): GitHubReleaseArtifactClient {
		return new GitHubReleaseArtifactClient(
			new \RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService(
				new WordPressSafeHttpTransport()
			)
		);
	}

	private function query(): ReleaseQuery {
		if ( $this->prospective ) {
			return ReleaseQuery::prospective(
				$this->repository,
				$this->channel,
				PHP_VERSION,
				is_string( $GLOBALS['wp_version'] ?? null ) ? $GLOBALS['wp_version'] : '6.5',
				ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS,
				$this->accessToken
			);
		}

		return new ReleaseQuery(
			$this->repository,
			$this->channel,
			PHP_VERSION,
			is_string( $GLOBALS['wp_version'] ?? null ) ? $GLOBALS['wp_version'] : '6.5',
			ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS,
			null,
			$this->accessToken
		);
	}

	/**
	 * @return ReleaseListResult|\WP_Error
	 */
	private function prospectiveReleaseList() {
		$list = $this->artifacts->listReleases( $this->query() );
		if ( $list instanceof \WP_Error ) {
			return $list;
		}
		if ( $list->rateLimit()->isLimited() ) {
			return self::rateLimitError( $list );
		}

		return $list;
	}

	/**
	 * @return PackageIdentityTarget|\WP_Error
	 */
	private function target() {
		return PackageIdentityTarget::fromValues(
			$this->packageType,
			$this->packageRoot,
			$this->headerFile,
			$this->expectedUpdateUri()
		);
	}

	private function expectedUpdateUri(): string {
		return 'https://github.com/' . $this->repository->canonical();
	}

	/**
	 * @return ReleaseArtifactClient|\WP_Error
	 */
	private function prospectiveClient() {
		return $this->prospective
			? $this->artifacts
			: self::prospectiveUnavailable();
	}

	private function cacheKey(): string {
		$wpVersion         = is_string( $GLOBALS['wp_version'] ?? null )
			? $GLOBALS['wp_version']
			: '6.5';
		$assuranceRevision = $this->assurance->cacheRevision() ?? 'uncacheable';

		return 'ran_wp_gh_preflight_v1_' . substr(
			hash(
				'sha256',
				implode(
					"\0",
					array(
						$this->repository->canonical(),
						$this->repository->providerRepositoryId() ?? 'unmanaged',
						$this->packageRoot,
						$this->headerFile,
						$this->channel,
						$this->packageType,
						$this->accessToken->isConfigured() ? 'private' : 'public',
						(string) self::CACHE_SCHEMA,
						PHP_VERSION,
						$wpVersion,
						$assuranceRevision,
					)
				)
			),
			0,
			32
		);
	}

	private function coordinationTargetKey(): string {
		return implode( "\0", array( $this->packageType, $this->packageRoot, $this->headerFile ) );
	}

	private function publishManagedFailure(
		?ReleaseOperationClaim &$claim,
		string $code,
		int $cooldown
	): void {
		if ( null === $claim ) {
			return;
		}
		$failure = array(
			'schema'         => self::CACHE_SCHEMA,
			'_cache_key'     => $this->cacheKey(),
			'status'         => 'cooldown',
			'failed_at'      => $this->now(),
			'cooldown_until' => $this->now() + max( 1, min( 86400, $cooldown ) ),
			'error_code'     => substr( sanitize_key( $code ), 0, 80 ),
		);
		if ( true === $this->operations->publish(
			$claim,
			ReleaseOperationCoordinator::MANAGED_STATE,
			$failure
		) ) {
			$claim = null;
		}
	}

	private function now(): int {
		return ( $this->clock )();
	}

	private static function errorCode( \WP_Error $error ): string {
		$code = $error->get_error_code();
		return is_string( $code ) && '' !== $code
			? $code
			: 'github_updater_error';
	}

	private static function invalidTarget(): \WP_Error {
		return new \WP_Error(
			'github_updater_invalid_preflight_target',
			'The release preflight target is invalid.'
		);
	}

	private static function rateLimitError( ReleaseListResult $releaseList ): \WP_Error {
		return new \WP_Error(
			'github_updater_rate_limited',
			'GitHub temporarily rate limited the release request.',
			array( 'cooldown' => $releaseList->rateLimit()->cooldownSeconds() )
		);
	}

	private static function prospectiveUnavailable(): \WP_Error {
		return new \WP_Error(
			'github_updater_release_preflight_unavailable',
			'Prospective release validation is unavailable.'
		);
	}

	private static function noProspectiveRelease( ReleaseListResult $releaseList ): \WP_Error {
		if ( $releaseList->isSearchExhausted() ) {
			return new \WP_Error(
				'github_updater_release_search_budget_exhausted',
				'The bounded release search did not find an eligible candidate.'
			);
		}

		return new \WP_Error(
			'github_updater_no_eligible_release',
			'No eligible published release is available.'
		);
	}
}
