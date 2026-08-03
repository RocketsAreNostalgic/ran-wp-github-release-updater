<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseAsset;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\CandidateValidation;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseAssurance;

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

final class ReleaseAssuranceTest extends TestCase {

	public function testAbsentCheckerIsNeutral(): void {
		$assurance = new ReleaseAssurance();
		$assurance->seal();

		self::assertNull(
			$assurance->check(
				$this->descriptor(),
				$this->validation(),
				str_repeat( 'a', 64 )
			)
		);
	}

	public function testOnlyBuiltInAssuranceHasAReusableCacheRevision(): void {
		$builtIn = new ReleaseAssurance();
		$builtIn->seal();
		self::assertSame( 'release-assurance-v1', $builtIn->cacheRevision() );

		$custom = new ReleaseAssurance();
		self::assertTrue( $custom->register( static fn (): null => null ) );
		$custom->seal();
		self::assertNull( $custom->cacheRevision() );
	}

	public function testAutomaticProfileRequiresStableRepositoryIdentityAndImmutability(): void {
		$assurance = new ReleaseAssurance();
		$assurance->seal();

		$missingIdentity = $assurance->checkAutomatic(
			$this->descriptor( null ),
			$this->validation(),
			str_repeat( 'a', 64 )
		);
		self::assertInstanceOf( \WP_Error::class, $missingIdentity );
		self::assertSame(
			'github_updater_automatic_repository_identity_required',
			$missingIdentity->get_error_code()
		);

		$mutable = $assurance->checkAutomatic(
			$this->descriptor( immutable: false ),
			$this->validation(),
			str_repeat( 'a', 64 )
		);
		self::assertInstanceOf( \WP_Error::class, $mutable );
		self::assertSame(
			'github_updater_automatic_immutable_release_required',
			$mutable->get_error_code()
		);

		self::assertNull(
			$assurance->checkAutomatic(
				$this->descriptor(),
				$this->validation(),
				str_repeat( 'a', 64 )
			)
		);
	}

	public function testCheckerReceivesOnlyBoundedReleaseAndValidationEvidence(): void {
		$received  = null;
		$assurance = new ReleaseAssurance();

		self::assertTrue(
			$assurance->register(
				static function ( array $evidence ) use ( &$received ): ?\WP_Error {
					$received = $evidence;
					return null;
				}
			)
		);
		$assurance->seal();

		self::assertNull(
			$assurance->check(
				$this->descriptor(),
				$this->validation(),
				str_repeat( 'a', 64 )
			)
		);
		self::assertIsArray( $received );
		self::assertSame( true, $received['immutable'] );
		self::assertSame( str_repeat( 'a', 64 ), $received['local_sha256'] );
		self::assertArrayNotHasKey( 'access_token', $received );
		self::assertArrayNotHasKey( 'path', $received );
	}

	public function testDuplicateRegistrationFailsClosed(): void {
		$assurance = new ReleaseAssurance();
		self::assertTrue( $assurance->register( static fn (): null => null ) );
		self::assertFalse( $assurance->register( static fn (): null => null ) );
		$assurance->seal();

		$result = $assurance->check(
			$this->descriptor(),
			$this->validation(),
			str_repeat( 'a', 64 )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_release_assurance_duplicate', $result->get_error_code() );
	}

	/**
	 * @dataProvider closedFailureProvider
	 */
	public function testCheckerFailureIsBoundedAndClosed( callable $checker, string $code ): void {
		$assurance = new ReleaseAssurance();
		self::assertTrue( $assurance->register( $checker ) );
		$assurance->seal();

		$result = $assurance->check(
			$this->descriptor(),
			$this->validation(),
			str_repeat( 'a', 64 )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( $code, $result->get_error_code() );
		self::assertStringNotContainsString( 'secret', $result->get_error_message() );
	}

	/**
	 * @return array<string, array{callable, string}>
	 */
	public static function closedFailureProvider(): array {
		return array(
			'rejection'        => array(
				static fn (): \WP_Error => new \WP_Error( 'future_attestation_rejected', 'secret' ),
				'future_attestation_rejected',
			),
			'exception'        => array(
				static function (): void {
					throw new \RuntimeException( 'secret' );
				},
				'github_updater_release_assurance_failed',
			),
			'malformed result' => array(
				static fn (): bool => true,
				'github_updater_release_assurance_invalid_result',
			),
		);
	}

	private function descriptor(
		?string $repositoryId = '123',
		bool $immutable = true
	): ArtifactDescriptor {
		$repository = Repository::fromString( 'owner/example', $repositoryId );
		self::assertInstanceOf( Repository::class, $repository );

		return new ArtifactDescriptor(
			new ReleaseQuery( $repository ),
			$repository,
			42,
			'v1.2.3',
			'1.2.3',
			str_repeat( '1', 40 ),
			false,
			'https://github.com/owner/example/releases/tag/v1.2.3',
			new ReleaseAsset( 7, 'example-1.2.3.zip', 100, str_repeat( 'a', 64 ) ),
			$immutable
		);
	}

	private function validation(): CandidateValidation {
		return new CandidateValidation(
			CandidateValidation::READY,
			'release_identity_verified',
			'v1.2.3',
			'1.2.3',
			'1.2.3',
			array(
				'release_id'   => 42,
				'tag'          => 'v1.2.3',
				'zip_asset_id' => 7,
				'sha256'       => str_repeat( 'a', 64 ),
				'package_type' => 'plugin',
				'header_file'  => 'example/example.php',
			),
			'8.2',
			'6.5'
		);
	}
}
