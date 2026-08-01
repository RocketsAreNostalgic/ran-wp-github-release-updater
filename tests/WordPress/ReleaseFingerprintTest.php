<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseAsset;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\CandidateValidation;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseFingerprint;

spl_autoload_register(
	static function ( string $className ): void {
		$prefix = 'RAN\\WPGitHubReleaseUpdater\\V1\\';
		if ( ! str_starts_with( $className, $prefix ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/src/'
			. str_replace( '\\', '/', substr( $className, strlen( $prefix ) ) )
			. '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);

final class ReleaseFingerprintTest extends TestCase {

	public function testSerializedFingerprintIsFixedShapeAndStrictlyRehydrated(): void {
		$fingerprint = $this->fingerprint();

		self::assertMatchesRegularExpression( '/\Av1:[a-f0-9]{64}\z/D', $fingerprint->value() );
		$rehydrated = ReleaseFingerprint::fromString( $fingerprint->value() );
		self::assertInstanceOf( ReleaseFingerprint::class, $rehydrated );
		self::assertTrue( $fingerprint->equals( $rehydrated ) );

		foreach (
			array(
				' ' . $fingerprint->value(),
				strtoupper( $fingerprint->value() ),
				'v2:' . substr( $fingerprint->value(), 3 ),
				substr( $fingerprint->value(), 0, -1 ),
			) as $invalid
		) {
			self::assertInstanceOf( \WP_Error::class, ReleaseFingerprint::fromString( $invalid ) );
		}
	}

	/**
	 * @dataProvider identityMutationProvider
	 * @param array<string, bool|int|string> $mutation
	 */
	public function testEveryRepresentativeIdentityMutationChangesFingerprint( array $mutation ): void {
		self::assertFalse(
			$this->fingerprint()->equals( $this->fingerprint( $mutation ) )
		);
	}

	/**
	 * @return array<string, array{array<string, bool|int|string>}>
	 */
	public static function identityMutationProvider(): array {
		return array(
			'repository'                 => array( array( 'repository' => 'RocketsAreNostalgic/other-plugin' ) ),
			'provider repository id'     => array( array( 'provider_repository_id' => '987654321' ) ),
			'release id'                 => array( array( 'release_id' => 43 ) ),
			'tag and version'            => array( array( 'version' => '1.2.4' ) ),
			'commit'                     => array( array( 'commit' => str_repeat( '2', 40 ) ) ),
			'channel'                    => array( array( 'channel' => ReleaseQuery::PRERELEASE ) ),
			'prerelease state'           => array( array( 'prerelease' => true ) ),
			'immutable state'            => array( array( 'immutable' => true ) ),
			'ZIP asset id'               => array( array( 'zip_asset_id' => 999 ) ),
			'ZIP asset name'             => array( array( 'zip_name' => 'renamed.zip' ) ),
			'ZIP asset size'             => array( array( 'zip_size' => 124 ) ),
			'ZIP digest'                 => array( array( 'zip_sha256' => str_repeat( 'b', 64 ) ) ),
			'validated package type'     => array( array( 'package_type' => 'theme' ) ),
			'validated package identity' => array( array( 'header_file' => 'example-plugin/renamed.php' ) ),
			'validated header version'   => array( array( 'header_version' => '1.2.3.0' ) ),
			'validated PHP requirement'  => array( array( 'requires_php' => '8.3' ) ),
			'validated WP requirement'   => array( array( 'requires_wordpress' => '6.6' ) ),
		);
	}

	/**
	 * @param array<string, bool|int|string> $values
	 */
	private function fingerprint( array $values = array() ): ReleaseFingerprint {
		$repositoryName = (string) ( $values['repository'] ?? 'RocketsAreNostalgic/example-plugin' );
		$repositoryId   = (string) ( $values['provider_repository_id'] ?? '123456789' );
		$repository     = Repository::fromString( $repositoryName, $repositoryId );
		self::assertInstanceOf( Repository::class, $repository );
		$version     = (string) ( $values['version'] ?? '1.2.3' );
		$tag         = 'v' . $version;
		$releaseId   = (int) ( $values['release_id'] ?? 42 );
		$zipAssetId  = (int) ( $values['zip_asset_id'] ?? 101 );
		$zipSha256   = (string) ( $values['zip_sha256'] ?? str_repeat( 'a', 64 ) );
		$packageType = (string) ( $values['package_type'] ?? 'plugin' );
		$headerFile  = (string) (
			$values['header_file']
				?? ( 'theme' === $packageType ? 'example-plugin/style.css' : 'example-plugin/example-plugin.php' )
		);
		$query       = ReleaseQuery::prospective(
			$repository,
			(string) ( $values['channel'] ?? ReleaseQuery::STABLE ),
			'8.2',
			'6.8',
			5
		);
		$descriptor  = new ArtifactDescriptor(
			$query,
			$repository,
			$releaseId,
			$tag,
			$version,
			(string) ( $values['commit'] ?? str_repeat( '1', 40 ) ),
			(bool) ( $values['prerelease'] ?? false ),
			'https://github.com/' . $repository->canonical() . '/releases/tag/' . $tag,
			new ReleaseAsset(
				$zipAssetId,
				(string) ( $values['zip_name'] ?? 'example-plugin-' . $version . '.zip' ),
				(int) ( $values['zip_size'] ?? 123 ),
				$zipSha256
			),
			(bool) ( $values['immutable'] ?? false )
		);
		$validation  = new CandidateValidation(
			CandidateValidation::READY,
			'release_identity_verified',
			$tag,
			$version,
			(string) ( $values['header_version'] ?? $version ),
			array(
				'release_id'   => $releaseId,
				'tag'          => $tag,
				'zip_asset_id' => $zipAssetId,
				'sha256'       => $zipSha256,
				'package_type' => $packageType,
				'header_file'  => $headerFile,
			),
			(string) ( $values['requires_php'] ?? '8.2' ),
			(string) ( $values['requires_wordpress'] ?? '6.5' )
		);

		return ReleaseFingerprint::fromDescriptor( $descriptor, $validation );
	}
}
