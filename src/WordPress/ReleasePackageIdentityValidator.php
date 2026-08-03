<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseVersion;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\VerifiedArtifact;

/**
 * Inspects one already verified release ZIP without extracting or installing it.
 */
final class ReleasePackageIdentityValidator {
	public const MAX_EXTRACTION_SPACE = 268435456;

	/** Largest expanded ZIP that remains within Core's 2.1 working-space estimate. */
	public const MAX_EXPANDED_ARCHIVE_SIZE = 127826407;

	private const MAX_ARCHIVE_ENTRIES = 10000;

	private const MAX_HEADER_BYTES = 8192;

	private bool $zipAvailable;

	public function __construct() {
		$this->zipAvailable = class_exists( '\\ZipArchive' );
	}

	public function validate(
		VerifiedArtifact $artifact,
		ArtifactDescriptor $descriptor,
		PackageIdentityTarget $target
	): CandidateValidation {
		$identity = self::identity( $descriptor, $target );
		$version  = $descriptor->version();
		$tag      = $descriptor->tag();
		if ( ! self::releaseVersionIsValid( $tag, $version ) ) {
			return $this->blocked(
				CandidateValidation::RELEASE_VERSION_INVALID,
				$tag,
				$version,
				null,
				$identity
			);
		}

		$result = $artifact->inspect(
			fn ( string $path ) => $this->scanArchive( $path, $target, $target->type() )
		);
		if ( $result instanceof \WP_Error ) {
			$code = $result->get_error_code();
			return $this->blocked(
				is_string( $code ) && str_starts_with( $code, 'package_' )
					? $code
					: CandidateValidation::ARCHIVE_UNREADABLE,
				$tag,
				$version,
				null,
				$identity
			);
		}

		return $this->validateHeaders( $result['contents'], $descriptor, $target );
	}

	/**
	 * Discover and validate the identity of a not-yet-installed package.
	 *
	 * @return CandidateValidation|\WP_Error
	 */
	public function validateProspective(
		VerifiedArtifact $artifact,
		ArtifactDescriptor $descriptor,
		string $packageType,
		string $expectedUpdateUri
	) {
		if ( ! in_array( $packageType, array( PackageIdentityTarget::PLUGIN, PackageIdentityTarget::THEME ), true )
			|| null === PackageIdentityTarget::normalizeUpdateUri( $expectedUpdateUri )
		) {
			return self::archiveError(
				'github_updater_invalid_package_identity_target',
				'The prospective package identity target is invalid.'
			);
		}
		if ( ! self::releaseVersionIsValid( $descriptor->tag(), $descriptor->version() ) ) {
			return self::archiveError(
				CandidateValidation::RELEASE_VERSION_INVALID,
				'The release version is invalid.'
			);
		}

		$result = $artifact->inspect(
			fn ( string $path ) => $this->scanArchive(
				$path,
				null,
				$packageType,
				$expectedUpdateUri
			)
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		return $this->validateHeaders( $result['contents'], $descriptor, $result['target'] );
	}

	/**
	 * @return array{release_id: int, tag: string, zip_asset_id: int, sha256: string, package_type: string, header_file: string}
	 */
	public static function identity( ArtifactDescriptor $descriptor, PackageIdentityTarget $target ): array {
		return array(
			'release_id'   => $descriptor->releaseId(),
			'tag'          => $descriptor->tag(),
			'zip_asset_id' => $descriptor->zipAsset()->id(),
			'sha256'       => (string) $descriptor->zipAsset()->sha256(),
			'package_type' => $target->type(),
			'header_file'  => $target->archivePath(),
		);
	}

	/**
	 * Perform one bounded archive traversal for managed or prospective identity.
	 *
	 * @return array{target: PackageIdentityTarget, contents: string}|\WP_Error
	 */
	private function scanArchive(
		string $path,
		?PackageIdentityTarget $target,
		string $packageType,
		?string $expectedUpdateUri = null
	) {
		if ( ! $this->zipAvailable ) {
			return self::archiveError(
				CandidateValidation::ZIP_EXTENSION_UNAVAILABLE,
				'The PHP ext-zip platform requirement is unavailable; the release archive cannot be inspected.'
			);
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return self::archiveError(
				CandidateValidation::ARCHIVE_UNREADABLE,
				'The release archive cannot be inspected.'
			);
		}

		try {
			if ( $zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES ) {
				return self::archiveError(
					CandidateValidation::ARCHIVE_ENTRY_LIMIT,
					'The release archive contains an unsupported number of entries.'
				);
			}

			$contents       = null;
			$entryCount     = 0;
			$root           = $target?->root();
			$headerFile     = $target?->headerFile();
			$seenPaths      = array();
			$extractionSize = 0;
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$name = $zip->getNameIndex( $index, \ZipArchive::FL_UNCHANGED );
				$stat = $zip->statIndex( $index, \ZipArchive::FL_UNCHANGED );
				if ( ! is_string( $name )
					|| ! is_array( $stat )
					|| ! is_int( $stat['size'] )
					|| $stat['size'] < 0
				) {
					return self::archiveError(
						CandidateValidation::ARCHIVE_SIZE_INVALID,
						'The release archive contains an invalid entry size.'
					);
				}
				if ( $stat['size'] > self::MAX_EXPANDED_ARCHIVE_SIZE - $extractionSize ) {
					return self::archiveError(
						CandidateValidation::ARCHIVE_TOO_LARGE,
						'The release archive exceeds the extraction limit.'
					);
				}
				$extractionSize += $stat['size'];

				$normalized = self::normalizeArchivePath( $name );
				if ( null === $normalized || self::isSymbolicLink( $zip, $index ) ) {
					return self::archiveError(
						CandidateValidation::ARCHIVE_PATH_UNSAFE,
						'The release archive contains an unsafe path.'
					);
				}
				$pathKey = strtolower( $normalized['path'] );
				if ( isset( $seenPaths[ $pathKey ] ) ) {
					return self::archiveError(
						null !== $target && hash_equals( strtolower( $target->archivePath() ), $pathKey )
							? CandidateValidation::ARCHIVE_ENTRY_DUPLICATE
							: CandidateValidation::ARCHIVE_PATH_DUPLICATE,
						'The release archive contains a duplicate path.'
					);
				}
				$seenPaths[ $pathKey ] = true;

				$segments = explode( '/', $normalized['path'] );
				$root     = $root ?? $segments[0];
				if ( ! hash_equals( $root, $segments[0] )
					|| ( 1 === count( $segments ) && ! $normalized['directory'] )
				) {
					return self::archiveError(
						CandidateValidation::ARCHIVE_ROOT_INVALID,
						'The release archive must contain one safe package root.'
					);
				}

				$prospectivePlugin = null === $target
					&& PackageIdentityTarget::PLUGIN === $packageType
					&& 2 === count( $segments )
					&& ! $normalized['directory']
					&& str_ends_with( $segments[1], '.php' );
				$expectedPath      = null !== $headerFile ? $root . '/' . $headerFile : $root . '/style.css';
				if ( ! $prospectivePlugin && ! hash_equals( $expectedPath, $normalized['path'] ) ) {
					continue;
				}
				if ( $normalized['directory'] ) {
					return self::archiveError(
						CandidateValidation::ARCHIVE_ENTRY_DUPLICATE,
						'The package header archive entry must be a file.'
					);
				}

				$candidateContents = self::readHeaderEntry( $zip, $name );
				if ( $candidateContents instanceof \WP_Error ) {
					return $candidateContents;
				}
				if ( $prospectivePlugin
					&& null === self::headerValue( $candidateContents, 'Plugin Name' )
				) {
					continue;
				}

				++$entryCount;
				if ( $entryCount > 1 ) {
					return self::archiveError(
						null === $target && PackageIdentityTarget::PLUGIN === $packageType
							? CandidateValidation::HEADER_AMBIGUOUS
							: CandidateValidation::ARCHIVE_ENTRY_DUPLICATE,
						'The release archive contains an ambiguous package header.'
					);
				}
				$contents   = $candidateContents;
				$headerFile = $segments[1];
			}

			if ( 1 !== $entryCount || ! is_string( $contents ) ) {
				return self::archiveError(
					CandidateValidation::HEADER_MISSING,
					'The release archive does not contain the required package header.'
				);
			}
			if ( null === $target ) {
				$target = PackageIdentityTarget::fromValues(
					$packageType,
					(string) $root,
					(string) $headerFile,
					$expectedUpdateUri
				);
				if ( $target instanceof \WP_Error ) {
					return self::archiveError(
						CandidateValidation::ARCHIVE_ROOT_INVALID,
						'The release archive package identity is invalid.'
					);
				}
			}

			return array(
				'target'   => $target,
				'contents' => $contents,
			);
		} finally {
			$zip->close();
		}
	}

	private function validateHeaders(
		string $contents,
		ArtifactDescriptor $descriptor,
		PackageIdentityTarget $target
	): CandidateValidation {
		$tag      = $descriptor->tag();
		$version  = $descriptor->version();
		$identity = self::identity( $descriptor, $target );
		$name     = self::headerValue(
			$contents,
			PackageIdentityTarget::THEME === $target->type() ? 'Theme Name' : 'Plugin Name'
		);
		$header   = self::versionHeader( $contents );
		if ( null === $name || null === $header ) {
			return $this->blocked( CandidateValidation::HEADER_MISSING, $tag, $version, null, $identity );
		}
		$normalizedHeader = ReleaseVersion::normalizeHeader( $header );
		if ( null === $normalizedHeader ) {
			return $this->blocked( CandidateValidation::HEADER_INVALID, $tag, $version, $header, $identity );
		}
		if ( ! hash_equals( $version, $normalizedHeader ) ) {
			return $this->blocked( CandidateValidation::VERSION_MISMATCH, $tag, $version, $header, $identity );
		}

		$updateUri = self::headerValue( $contents, 'Update URI' );
		if ( null === $updateUri ) {
			return $this->blocked( CandidateValidation::UPDATE_URI_MISSING, $tag, $version, $header, $identity );
		}
		$normalizedUpdateUri = PackageIdentityTarget::normalizeUpdateUri( $updateUri );
		if ( null === $normalizedUpdateUri
			|| ! hash_equals( (string) $target->expectedUpdateUri(), $normalizedUpdateUri )
		) {
			return $this->blocked( CandidateValidation::UPDATE_URI_INVALID, $tag, $version, $header, $identity );
		}

		$requiresPhp       = self::headerValue( $contents, 'Requires PHP' );
		$requiresWordPress = self::headerValue( $contents, 'Requires at least' );
		if ( null === $requiresPhp || null === $requiresWordPress ) {
			return $this->blocked( CandidateValidation::COMPATIBILITY_MISSING, $tag, $version, $header, $identity );
		}
		if ( ! self::compatibilityVersionIsValid( $requiresPhp )
			|| ! self::compatibilityVersionIsValid( $requiresWordPress )
		) {
			return $this->blocked( CandidateValidation::COMPATIBILITY_INVALID, $tag, $version, $header, $identity );
		}
		if ( version_compare( $descriptor->query()->phpVersion(), $requiresPhp, '<' )
			|| version_compare( $descriptor->query()->wordpressVersion(), $requiresWordPress, '<' )
		) {
			return $this->blocked(
				CandidateValidation::RELEASE_INCOMPATIBLE,
				$tag,
				$version,
				$header,
				$identity,
				$requiresPhp,
				$requiresWordPress
			);
		}

		return new CandidateValidation(
			CandidateValidation::READY,
			'release_identity_verified',
			$tag,
			$version,
			$header,
			$identity,
			$requiresPhp,
			$requiresWordPress
		);
	}

	/**
	 * @param array{release_id: int, tag: string, zip_asset_id: int, sha256: string, package_type: string, header_file: string} $identity
	 */
	private function blocked(
		string $code,
		string $tag,
		string $releaseVersion,
		?string $headerVersion,
		array $identity,
		?string $requiresPhp = null,
		?string $requiresWordPress = null
	): CandidateValidation {
		return new CandidateValidation(
			CandidateValidation::BLOCKED,
			$code,
			$tag,
			$releaseVersion,
			$headerVersion,
			$identity,
			$requiresPhp,
			$requiresWordPress
		);
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function readHeaderEntry( \ZipArchive $zip, string $name ) {
		$stream = $zip->getStream( $name );
		if ( ! is_resource( $stream ) ) {
			return self::archiveError(
				CandidateValidation::ARCHIVE_UNREADABLE,
				'The release archive header cannot be read.'
			);
		}
		$contents = stream_get_contents( $stream, self::MAX_HEADER_BYTES );
		fclose( $stream );

		return is_string( $contents )
			? $contents
			: self::archiveError(
				CandidateValidation::ARCHIVE_UNREADABLE,
				'The release archive header cannot be read.'
			);
	}

	private static function releaseVersionIsValid( string $tag, string $version ): bool {
		return ReleaseVersion::normalize( $version ) === $version
			&& ReleaseVersion::fromTag( $tag ) === $version;
	}

	private static function compatibilityVersionIsValid( string $version ): bool {
		return strlen( $version ) <= 32
			&& 1 === preg_match( '/\A\d+\.\d+(?:\.\d+)?\z/D', $version );
	}

	private static function archiveError( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}

	private static function versionHeader( string $contents ): ?string {
		$value = self::headerValue( $contents, 'Version' );
		return null !== $value && strlen( $value ) <= 32 ? $value : null;
	}

	private static function headerValue( string $contents, string $name ): ?string {
		if ( 1 !== preg_match( '/^[ \\t\\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $contents, $matches ) ) {
			return null;
		}
		$value = preg_replace( '/\\s*(?:\\*\\/)?\\s*$/', '', trim( $matches[1] ) );
		return is_string( $value ) && '' !== $value && strlen( $value ) <= 500 ? $value : null;
	}

	/**
	 * @return array{path: string, directory: bool}|null
	 */
	private static function normalizeArchivePath( string $name ): ?array {
		if ( '' === $name
			|| strlen( $name ) > 4096
			|| str_starts_with( $name, '/' )
			|| str_contains( $name, '\\' )
			|| str_contains( $name, ':' )
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $name )
			|| 1 === preg_match( '/[^\x20-\x7e]/', $name )
		) {
			return null;
		}

		$directory = str_ends_with( $name, '/' );
		$path      = $directory ? substr( $name, 0, -1 ) : $name;
		if ( '' === $path || str_ends_with( $path, '/' ) ) {
			return null;
		}
		$segments = explode( '/', $path );
		foreach ( $segments as $segment ) {
			$basename = explode( '.', $segment, 2 )[0];
			if ( '' === $segment
				|| '.' === $segment
				|| '..' === $segment
				|| strlen( $segment ) > 255
				|| str_ends_with( $segment, '.' )
				|| str_ends_with( $segment, ' ' )
				|| 1 === preg_match( '/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])\z/iD', $basename )
			) {
				return null;
			}
		}

		return array(
			'path'      => implode( '/', $segments ),
			'directory' => $directory,
		);
	}

	private static function isSymbolicLink( \ZipArchive $zip, int $index ): bool {
		$operatingSystem = 0;
		$attributes      = 0;
		if ( ! $zip->getExternalAttributesIndex(
			$index,
			$operatingSystem,
			$attributes,
			\ZipArchive::FL_UNCHANGED
		) || \ZipArchive::OPSYS_UNIX !== $operatingSystem
		) {
			return false;
		}

		return 0120000 === ( ( $attributes >> 16 ) & 0170000 );
	}
}
