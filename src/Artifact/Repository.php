<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Canonical GitHub repository identity.
 */
final class Repository {
	private function __construct(
		private string $owner,
		private string $name,
		private ?string $providerRepositoryId
	) {
	}

	/**
	 * @return self|\WP_Error
	 */
	public static function fromString(
		string $repository,
		?string $providerRepositoryId = null
	) {
		if ( 1 !== preg_match( '/\A([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))\/([A-Za-z0-9_.-]{1,100})\z/D', $repository, $matches ) ) {
			return new \WP_Error(
				'github_updater_invalid_repository',
				'The GitHub repository must use the owner/repository form.'
			);
		}
		if ( null !== $providerRepositoryId
			&& 1 !== preg_match( '/\A[1-9][0-9]{0,18}\z/D', $providerRepositoryId )
		) {
			return new \WP_Error(
				'github_updater_invalid_repository_identity',
				'The GitHub repository identity is invalid.'
			);
		}

		return new self( $matches[1], $matches[2], $providerRepositoryId );
	}

	public function canonical(): string {
		return $this->owner . '/' . $this->name;
	}

	public function apiPath(): string {
		return rawurlencode( $this->owner ) . '/' . rawurlencode( $this->name );
	}

	public function providerRepositoryId(): ?string {
		return $this->providerRepositoryId;
	}

	public function equals( self $other ): bool {
		return 0 === strcasecmp( $this->canonical(), $other->canonical() )
			&& $this->providerRepositoryId === $other->providerRepositoryId;
	}
}
