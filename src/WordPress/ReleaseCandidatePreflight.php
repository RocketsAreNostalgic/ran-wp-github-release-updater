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
	public const PROSPECTIVE_API_VERSION = 3;

	/**
	 * Request-local capability for a Core-owned exact-release handoff.
	 *
	 * The native updater normally rejects any earlier pre-download reply. A
	 * managed consumer may admit only the frozen artifact it already acquired
	 * through this updater's exact-version preflight, for the matching Core
	 * update operation. The callback must return strict true to admit it.
	 */
	public const CORE_REINSTALL_HANDOFF_FILTER = 'ran_wp_github_release_updater_v1_core_reinstall_handoff';

	private const CACHE_SCHEMA = 3;

	private ReleaseCandidateSelector $candidates;

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
			|| ( null !== $repositoryId && ! is_string( $repositoryId ) )
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
		ProspectiveReleaseArtifactClient $artifacts
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
	public function inspectExact(
		int $releaseId,
		string $tag,
		string $defaultBranch
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
		$reachable = $client->isCommitReachableFromBranch(
			$query,
			$descriptor->commit(),
			$defaultBranch
		);
		if ( $reachable instanceof \WP_Error ) {
			return $reachable;
		}
		if ( ! $reachable ) {
			return self::unreachable();
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
		string $defaultBranch,
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

			$reachable = $client->isCommitReachableFromBranch(
				$query,
				$descriptor->commit(),
				$defaultBranch
			);
			if ( true !== $reachable ) {
				$artifact->discard();
				return $reachable instanceof \WP_Error ? $reachable : self::unreachable();
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

		$cached = get_site_transient( $this->cacheKey() );
		if ( ! $force && is_array( $cached ) && self::CACHE_SCHEMA === ( $cached['schema'] ?? null )
			&& is_int( $cached['checked_at'] ?? null ) && $this->now() - $cached['checked_at'] < $this->cacheDuration
		) {
			$result = CandidateValidation::fromArray( $cached['validation'] ?? array() );
			if ( null !== $result ) {
				return $result;
			}
		}

		$query = $this->query();
		$list  = $this->artifacts->listReleases( $query );
		if ( $list instanceof \WP_Error ) {
			return $list;
		}
		if ( $list->rateLimit()->isLimited() ) {
			return self::rateLimitError( $list );
		}

		$target = $this->target();
		if ( $target instanceof \WP_Error ) {
			return $target;
		}
		$selected = $this->candidates->select(
			$list,
			$query,
			$target,
			$this->assurance
		);
		if ( $selected instanceof \WP_Error ) {
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
		set_site_transient(
			$this->cacheKey(),
			array(
				'schema'     => self::CACHE_SCHEMA,
				'checked_at' => $this->now(),
				'validation' => $result->toArray(),
			),
			$this->cacheDuration
		);

		return $result;
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
	 * @return ProspectiveReleaseArtifactClient|\WP_Error
	 */
	private function prospectiveClient() {
		return $this->prospective && $this->artifacts instanceof ProspectiveReleaseArtifactClient
			? $this->artifacts
			: self::prospectiveUnavailable();
	}

	private function cacheKey(): string {
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
					)
				)
			),
			0,
			32
		);
	}

	private function now(): int {
		return ( $this->clock )();
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
			'github_updater_release_reachability_unavailable',
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

	private static function unreachable(): \WP_Error {
		return new \WP_Error(
			'github_updater_release_not_on_default_branch',
			'The release tag commit is not reachable from the repository default branch.'
		);
	}
}
