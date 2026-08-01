<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

/**
 * Safe, archive-relative WordPress package identity to inspect.
 *
 * This has no knowledge of an installed package or filesystem path.
 */
final class PackageIdentityTarget {
	public const PLUGIN = 'plugin';

	public const THEME = 'theme';

	private function __construct(
		private string $type,
		private string $root,
		private string $headerFile,
		private ?string $expectedUpdateUri
	) {
	}

	/**
	 * @return self|\WP_Error
	 */
	public static function forPlugin(
		string $pluginRoot,
		string $mainFile,
		?string $expectedUpdateUri = null
	) {
		return self::fromValues( self::PLUGIN, $pluginRoot, $mainFile, $expectedUpdateUri );
	}

	/**
	 * @return self|\WP_Error
	 */
	public static function forTheme( string $themeRoot, ?string $expectedUpdateUri = null ) {
		return self::fromValues( self::THEME, $themeRoot, 'style.css', $expectedUpdateUri );
	}

	/**
	 * @return self|\WP_Error
	 */
	public static function fromValues(
		string $type,
		string $root,
		string $headerFile,
		?string $expectedUpdateUri = null
	) {
		$normalizedUpdateUri = null === $expectedUpdateUri
			? null
			: self::normalizeUpdateUri( $expectedUpdateUri );
		if ( ! in_array( $type, array( self::PLUGIN, self::THEME ), true )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/D', $root )
			|| 1 !== preg_match( '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.(?:php|css)\z/D', $headerFile )
			|| ( self::THEME === $type && 'style.css' !== $headerFile )
			|| ( null !== $expectedUpdateUri && null === $normalizedUpdateUri )
		) {
			return new \WP_Error(
				'github_updater_invalid_package_identity_target',
				'The release package identity target is invalid.'
			);
		}

		return new self( $type, $root, $headerFile, $normalizedUpdateUri );
	}

	public function type(): string {
		return $this->type;
	}

	public function root(): string {
		return $this->root;
	}

	public function headerFile(): string {
		return $this->headerFile;
	}

	public function archivePath(): string {
		return $this->root . '/' . $this->headerFile;
	}

	public function expectedUpdateUri(): ?string {
		return $this->expectedUpdateUri;
	}

	/**
	 * GitHub repository identity is case-insensitive. Update URI comparison
	 * therefore lowercases the host/owner/repository and ignores trailing `/`.
	 */
	public static function normalizeUpdateUri( string $updateUri ): ?string {
		$trimmed = rtrim( trim( $updateUri ), '/' );
		if ( 1 !== preg_match(
			'#\Ahttps://github\.com/([A-Za-z0-9-]{1,39})/([A-Za-z0-9_.-]{1,100})\z#Di',
			$trimmed,
			$matches
		) ) {
			return null;
		}

		return 'https://github.com/' . strtolower( $matches[1] . '/' . $matches[2] );
	}
}
