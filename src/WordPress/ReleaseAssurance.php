<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;

/**
 * One request-local, rejection-only release assurance extension.
 */
final class ReleaseAssurance {
	public const REGISTRATION_ACTION = 'ran_wp_github_release_updater_v1_assurance_registration';

	public const AUTOMATIC_PROFILE_REVISION = 'github-native-immutable-v1';

	private const CACHE_REVISION = 'release-assurance-v1';

	private static ?self $selected = null;

	/** @var callable(array<string, mixed>): mixed|null */
	private $checker = null;

	private bool $sealed = false;

	private bool $invalid = false;

	/**
	 * Select and seal the checker before release clients or targets are built.
	 *
	 * @internal Selected runtime bootstrap only.
	 */
	public static function selectForRequest(): self {
		$assurance      = new self();
		self::$selected = $assurance;
		do_action( self::REGISTRATION_ACTION, $assurance );
		$assurance->seal();

		return $assurance;
	}

	/**
	 * Return the selected checker, or a sealed neutral checker before runtime.
	 *
	 * @internal Prospective preflight composition only.
	 */
	public static function selected(): self {
		if ( null === self::$selected ) {
			self::$selected = new self();
			self::$selected->seal();
		}

		return self::$selected;
	}

	/**
	 * Register the one optional checker during REGISTRATION_ACTION.
	 *
	 * The updater fires that action once, before constructing release clients
	 * and targets, then seals this object. The checker receives only normalized,
	 * non-secret GitHub release, ZIP digest and package-header evidence after
	 * all built-in checks have passed. It may return null or a WP_Error to add a
	 * rejection; it cannot waive a built-in failure, access credentials or
	 * paths, download/install an artifact, or mutate updater state. A future
	 * immutable-release or Artifact Attestation add-on must independently
	 * verify its policy against the supplied release and locally calculated
	 * digest. Registering more than one checker invalidates assurance for the
	 * request rather than making callback order authoritative.
	 *
	 * @param callable(array<string, mixed>): mixed $checker
	 */
	public function register( callable $checker ): bool {
		if ( $this->sealed || null !== $this->checker ) {
			$this->invalid = true;
			return false;
		}

		$this->checker = $checker;
		return true;
	}

	public function seal(): void {
		$this->sealed = true;
	}

	/**
	 * Return the stable built-in cache identity only when no caller checker can
	 * change the verdict. Custom assurance therefore remains request-fresh.
	 */
	public function cacheRevision(): ?string {
		return $this->invalid || null !== $this->checker
			? null
			: self::CACHE_REVISION;
	}

	/**
	 * Enforce the fixed GitHub-native profile required for automatic updates.
	 *
	 * @return null|\WP_Error
	 */
	public function checkAutomatic(
		ArtifactDescriptor $descriptor,
		CandidateValidation $validation,
		string $localSha256
	) {
		$eligibility = $this->automaticEligibility( $descriptor );
		if ( $eligibility instanceof \WP_Error ) {
			return $eligibility;
		}

		return $this->check( $descriptor, $validation, $localSha256 );
	}

	/**
	 * Enforce the descriptor-only portion of the fixed automatic profile.
	 *
	 * This can run before an offer is cached; the ZIP-backed assurance check is
	 * still repeated against fresh bytes immediately before installation.
	 *
	 * @return null|\WP_Error
	 */
	public function automaticEligibility( ArtifactDescriptor $descriptor ) {
		if ( null === $descriptor->repository()->providerRepositoryId() ) {
			return self::automaticError(
				'github_updater_automatic_repository_identity_required',
				'Automatic updates require a stable GitHub repository identity.'
			);
		}
		if ( ! $descriptor->isImmutable() ) {
			return self::automaticError(
				'github_updater_automatic_immutable_release_required',
				'Automatic updates require an immutable published GitHub Release.'
			);
		}

		return null;
	}

	/**
	 * @return null|\WP_Error
	 */
	public function check(
		ArtifactDescriptor $descriptor,
		CandidateValidation $validation,
		string $localSha256
	) {
		if ( $this->invalid ) {
			return self::error( 'github_updater_release_assurance_duplicate' );
		}
		if ( null === $this->checker ) {
			return null;
		}

		try {
			$result = ( $this->checker )(
				array(
					'repository'             => $descriptor->repository()->canonical(),
					'provider_repository_id' => $descriptor->repository()->providerRepositoryId(),
					'release_id'             => $descriptor->releaseId(),
					'tag'                    => $descriptor->tag(),
					'version'                => $descriptor->version(),
					'commit'                 => $descriptor->commit(),
					'prerelease'             => $descriptor->isPrerelease(),
					'immutable'              => $descriptor->isImmutable(),
					'zip_asset_id'           => $descriptor->zipAsset()->id(),
					'zip_name'               => $descriptor->zipAsset()->name(),
					'zip_size'               => $descriptor->zipAsset()->size(),
					'github_sha256'          => $descriptor->zipAsset()->sha256(),
					'local_sha256'           => $localSha256,
					'candidate_validation'   => $validation->toArray(),
				)
			);
		} catch ( \Throwable ) {
			return self::error( 'github_updater_release_assurance_failed' );
		}

		if ( null === $result ) {
			return null;
		}
		if ( ! $result instanceof \WP_Error ) {
			return self::error( 'github_updater_release_assurance_invalid_result' );
		}

		$errorCode = $result->get_error_code();
		$code      = sanitize_key( is_string( $errorCode ) ? $errorCode : '' );
		if ( '' === $code || strlen( $code ) > 80 ) {
			$code = 'github_updater_release_assurance_rejected';
		}

		return self::error( $code );
	}

	private static function error( string $code ): \WP_Error {
		return new \WP_Error(
			$code,
			'The optional GitHub release assurance policy rejected this package.'
		);
	}

	private static function automaticError( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}
