<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseAsset;

/**
 * Compact continuity guard for one exact prospective release identity.
 */
final readonly class ReleaseFingerprint {
	private const PREFIX = 'v1:';

	private function __construct( private string $value ) {
	}

	/**
	 * @return self|\WP_Error
	 */
	public static function fromString( string $value ) {
		if ( 1 !== preg_match( '/\Av1:[a-f0-9]{64}\z/D', $value ) ) {
			return new \WP_Error(
				'github_updater_invalid_release_fingerprint',
				'The expected release fingerprint is invalid.'
			);
		}

		return new self( $value );
	}

	public static function fromDescriptor(
		ArtifactDescriptor $descriptor,
		CandidateValidation $validation
	): self {
		$material = array(
			'schema'                 => 3,
			'repository'             => $descriptor->repository()->canonical(),
			'provider_repository_id' => $descriptor->repository()->providerRepositoryId(),
			'release_id'             => $descriptor->releaseId(),
			'tag'                    => $descriptor->tag(),
			'version'                => $descriptor->version(),
			'commit'                 => $descriptor->commit(),
			'channel'                => $descriptor->query()->channel(),
			'prerelease'             => $descriptor->isPrerelease(),
			'immutable'              => $descriptor->isImmutable(),
			'zip_asset'              => self::assetIdentity( $descriptor->zipAsset() ),
			'validation'             => $validation->toArray(),
		);
		$encoded  = json_encode(
			$material,
			JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		);

		return new self( self::PREFIX . hash( 'sha256', $encoded ) );
	}

	public function value(): string {
		return $this->value;
	}

	public function equals( self $other ): bool {
		return hash_equals( $this->value, $other->value );
	}

	/**
	 * @return array{id: int, name: string, size: int, sha256: string}
	 */
	private static function assetIdentity( ReleaseAsset $asset ): array {
		return array(
			'id'     => $asset->id(),
			'name'   => $asset->name(),
			'size'   => $asset->size(),
			'sha256' => $asset->sha256(),
		);
	}
}
