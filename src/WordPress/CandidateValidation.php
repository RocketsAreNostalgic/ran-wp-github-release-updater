<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseVersion;

/**
 * Bounded, display-safe release package identity verdict.
 */
final class CandidateValidation {
	public const READY = 'ready';

	public const BLOCKED = 'blocked';

	public const VERSION_MISMATCH = 'release_version_mismatch';

	public const HEADER_MISSING = 'package_header_missing';

	public const HEADER_INVALID = 'package_header_invalid';

	public const ARCHIVE_UNREADABLE = 'package_archive_unreadable';

	public const ZIP_EXTENSION_UNAVAILABLE = 'package_zip_extension_unavailable';

	public const ARCHIVE_SIZE_INVALID = 'package_archive_size_invalid';

	public const ARCHIVE_TOO_LARGE = 'package_archive_too_large';

	public const ARCHIVE_PATH_UNSAFE = 'package_archive_path_unsafe';

	public const ARCHIVE_PATH_DUPLICATE = 'package_archive_path_duplicate';

	public const ARCHIVE_ROOT_INVALID = 'package_archive_root_invalid';

	public const ARCHIVE_ENTRY_DUPLICATE = 'package_archive_entry_duplicate';

	public const ARCHIVE_ENTRY_LIMIT = 'package_archive_entry_limit';

	public const RELEASE_VERSION_INVALID = 'release_version_invalid';

	public const UPDATE_URI_MISSING = 'package_update_uri_missing';

	public const UPDATE_URI_INVALID = 'package_update_uri_invalid';

	public const COMPATIBILITY_MISSING = 'package_compatibility_missing';

	public const COMPATIBILITY_INVALID = 'package_compatibility_invalid';

	public const RELEASE_INCOMPATIBLE = 'github_updater_release_incompatible';

	public const HEADER_AMBIGUOUS = 'package_header_ambiguous';

	/**
	 * @param array{release_id: int, tag: string, zip_asset_id: int, sha256: string, package_type: string, header_file: string} $identity
	 */
	public function __construct(
		private string $state,
		private string $code,
		private string $releaseTag,
		private string $releaseVersion,
		private ?string $packageHeaderVersion,
		private array $identity,
		private ?string $requiresPhp = null,
		private ?string $requiresWordPress = null
	) {
	}

	public function isReady(): bool {
		return self::READY === $this->state;
	}

	public function state(): string {
		return $this->state;
	}

	public function code(): string {
		return $this->code;
	}

	public function releaseTag(): string {
		return $this->releaseTag;
	}

	public function releaseVersion(): string {
		return $this->releaseVersion;
	}

	public function packageHeaderVersion(): ?string {
		return $this->packageHeaderVersion;
	}

	/**
	 * Compare this canonical release version with an installed package header.
	 */
	public function relationshipTo( string $installedVersion ): string {
		return ReleaseVersion::relationship( $this->releaseVersion, $installedVersion );
	}

	public function requiresPhp(): ?string {
		return $this->requiresPhp;
	}

	public function requiresWordPress(): ?string {
		return $this->requiresWordPress;
	}

	/**
	 * @return array{release_id: int, tag: string, zip_asset_id: int, sha256: string, package_type: string, header_file: string}
	 */
	public function identity(): array {
		return $this->identity;
	}

	/**
	 * @return array{state: string, code: string, release_tag: string, release_version: string, package_header_version: ?string, requires_php: ?string, requires_wordpress: ?string, identity: array{release_id: int, tag: string, zip_asset_id: int, sha256: string, package_type: string, header_file: string}}
	 */
	public function toArray(): array {
		return array(
			'state'                  => $this->state,
			'code'                   => $this->code,
			'release_tag'            => $this->releaseTag,
			'release_version'        => $this->releaseVersion,
			'package_header_version' => $this->packageHeaderVersion,
			'requires_php'           => $this->requiresPhp,
			'requires_wordpress'     => $this->requiresWordPress,
			'identity'               => $this->identity,
		);
	}

	/**
	 * Rehydrate only a previously emitted bounded verdict.
	 *
	 * @param array<string, mixed> $value
	 */
	public static function fromArray( array $value ): ?self {
		$identity          = $value['identity'] ?? null;
		$releaseTag        = $value['release_tag'] ?? null;
		$releaseVersion    = is_string( $value['release_version'] ?? null )
			? ReleaseVersion::normalize( $value['release_version'] )
			: null;
		$requiresPhp       = $value['requires_php'] ?? null;
		$requiresWordPress = $value['requires_wordpress'] ?? null;
		if ( ! is_array( $identity )
			|| ! in_array( $value['state'] ?? null, array( self::READY, self::BLOCKED ), true )
			|| ! is_string( $value['code'] ?? null )
			|| 1 !== preg_match( '/\A[a-z_]{1,80}\z/D', $value['code'] )
			|| ! is_string( $releaseTag )
			|| strlen( $releaseTag ) > ReleaseVersion::MAX_LENGTH + 1
			|| null === $releaseVersion
			|| ReleaseVersion::fromTag( $releaseTag ) !== $releaseVersion
			|| ( null !== ( $value['package_header_version'] ?? null )
				&& ( ! is_string( $value['package_header_version'] ) || strlen( $value['package_header_version'] ) > 32 ) )
			|| ( null !== $requiresPhp
				&& ( ! is_string( $requiresPhp ) || ! self::isCompatibilityVersion( $requiresPhp ) ) )
			|| ( null !== $requiresWordPress
				&& ( ! is_string( $requiresWordPress ) || ! self::isCompatibilityVersion( $requiresWordPress ) ) )
			|| ( self::READY === $value['state']
				&& ( ! is_string( $requiresPhp ) || ! is_string( $requiresWordPress ) ) )
			|| ! is_int( $identity['release_id'] ?? null )
			|| $identity['release_id'] < 1
			|| ! is_string( $identity['tag'] ?? null )
			|| $identity['tag'] !== $releaseTag
			|| ! is_int( $identity['zip_asset_id'] ?? null )
			|| $identity['zip_asset_id'] < 1
			|| ! is_string( $identity['sha256'] ?? null )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $identity['sha256'] )
			|| ! in_array( $identity['package_type'] ?? null, array( PackageIdentityTarget::PLUGIN, PackageIdentityTarget::THEME ), true )
			|| ! is_string( $identity['header_file'] ?? null )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\/[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.(?:php|css)\z/D', $identity['header_file'] )
		) {
			return null;
		}

		/** @var array{release_id: int, tag: string, zip_asset_id: int, sha256: string, package_type: string, header_file: string} $identity */
		return new self(
			$value['state'],
			$value['code'],
			$releaseTag,
			$releaseVersion,
			$value['package_header_version'] ?? null,
			$identity,
			is_string( $requiresPhp ) ? $requiresPhp : null,
			is_string( $requiresWordPress ) ? $requiresWordPress : null
		);
	}

	private static function isCompatibilityVersion( string $version ): bool {
		return strlen( $version ) <= 32
			&& 1 === preg_match( '/\A\d+\.\d+(?:\.\d+)?\z/D', $version );
	}
}
