<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Bounded release-discovery request.
 */
final class ReleaseQuery {
	public const STABLE     = 'stable';
	public const PRERELEASE = 'prerelease';

	public const MAX_CANDIDATE_DESCRIPTIONS = 8;

	public function __construct(
		private Repository $repository,
		private string $channel = self::STABLE,
		private string $phpVersion = PHP_VERSION,
		private string $wordpressVersion = '6.5',
		private int $candidateLimit = self::MAX_CANDIDATE_DESCRIPTIONS,
		private ?ConditionalState $conditional = null,
		private ?AccessToken $accessToken = null,
		private bool $prospective = false
	) {
	}

	public static function prospective(
		Repository $repository,
		string $channel = self::STABLE,
		string $phpVersion = PHP_VERSION,
		string $wordpressVersion = '6.5',
		int $candidateLimit = self::MAX_CANDIDATE_DESCRIPTIONS,
		?AccessToken $accessToken = null
	): self {
		return new self(
			$repository,
			$channel,
			$phpVersion,
			$wordpressVersion,
			$candidateLimit,
			null,
			$accessToken,
			true
		);
	}

	public function repository(): Repository {
		return $this->repository;
	}

	public function channel(): string {
		return $this->channel;
	}

	public function phpVersion(): string {
		return $this->phpVersion;
	}

	public function wordpressVersion(): string {
		return $this->wordpressVersion;
	}

	public function candidateLimit(): int {
		return max( 1, min( self::MAX_CANDIDATE_DESCRIPTIONS, $this->candidateLimit ) );
	}

	public function conditional(): ConditionalState {
		return $this->conditional ?? new ConditionalState();
	}

	public function accessToken(): AccessToken {
		if ( null === $this->accessToken ) {
			$public = AccessToken::fromValue( null );
			if ( $public instanceof \WP_Error ) {
				throw new \LogicException( 'A public credential source must be valid.' );
			}
			$this->accessToken = $public;
		}

		return $this->accessToken;
	}

	public function isProspective(): bool {
		return $this->prospective;
	}

	/**
	 * @return true|\WP_Error
	 */
	public function validate() {
		if ( self::STABLE !== $this->channel && self::PRERELEASE !== $this->channel ) {
			return new \WP_Error( 'github_updater_invalid_channel', 'The release channel is invalid.' );
		}
		if ( strlen( $this->phpVersion ) > 32 || strlen( $this->wordpressVersion ) > 32 ) {
			return new \WP_Error(
				'github_updater_invalid_runtime_version',
				'The local PHP or WordPress version is invalid.'
			);
		}

		return true;
	}
}
