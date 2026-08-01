<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * One canonical release-version contract for discovery, headers and caches.
 */
final class ReleaseVersion {
	public const MAX_LENGTH = 100;

	private const PATTERN = '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
		. '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
		. '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?\z/D';

	public static function normalize( string $version ): ?string {
		return strlen( $version ) <= self::MAX_LENGTH
			&& 1 === preg_match( self::PATTERN, $version )
				? $version
				: null;
	}

	public static function fromTag( string $tag ): ?string {
		if ( strlen( $tag ) > self::MAX_LENGTH + 1 ) {
			return null;
		}
		$version = str_starts_with( $tag, 'v' ) ? substr( $tag, 1 ) : $tag;
		return self::normalize( $version );
	}

	/**
	 * WordPress retains its stable two-part shorthand. Prerelease headers must
	 * contain the complete canonical release version.
	 */
	public static function normalizeHeader( string $version ): ?string {
		if ( strlen( $version ) > self::MAX_LENGTH ) {
			return null;
		}
		if ( 1 === preg_match( '/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $version, $matches ) ) {
			return $matches[1] . '.' . $matches[2] . '.0';
		}

		return self::normalize( $version );
	}

	public static function isPrerelease( string $version ): bool {
		return null !== self::normalize( $version ) && str_contains( $version, '-' );
	}
}
