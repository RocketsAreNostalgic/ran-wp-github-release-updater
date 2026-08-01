<?php

declare(strict_types=1);

namespace Tests\Artifact;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\VerifiedArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;

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

final class VerifiedArtifactTest extends TestCase {

	public function testDiscardReportsCleanupFailureAndRemainsOneShot(): void {
		$factory  = new TemporaryFileFactoryStub();
		$artifact = $this->artifact( $factory );
		$path     = $factory->path();

		self::assertFalse( $artifact->discard() );
		self::assertFalse( $artifact->discard() );
		unset( $artifact );

		self::assertSame( 1, $factory->deleteCalls );
		$this->removeFixture( $path );
	}

	public function testDiscardReportsExactFileDeletionAndRemainsOneShot(): void {
		$factory  = new TemporaryFileFactoryStub( false );
		$artifact = $this->artifact( $factory );

		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/D', $artifact->sha256() );
		self::assertTrue( $artifact->discard() );
		self::assertTrue( $artifact->discard() );
		self::assertSame( 1, $factory->deleteCalls );
	}

	public function testDiscardRefusesToDeleteReplacement(): void {
		$factory  = new TemporaryFileFactoryStub( false );
		$artifact = $this->artifact( $factory );
		$path     = $factory->path();
		unlink( $path );
		file_put_contents( $path, 'replacement' );

		self::assertFalse( $artifact->discard() );
		self::assertFalse( $artifact->discard() );
		self::assertSame( 0, $factory->deleteCalls );
		self::assertSame( 'replacement', file_get_contents( $path ) );
		$this->removeFixture( $path );
	}

	public function testDestructorSwallowsCleanupFailure(): void {
		$factory  = new TemporaryFileFactoryStub();
		$artifact = $this->artifact( $factory );
		$path     = $factory->path();

		unset( $artifact );

		self::assertSame( 1, $factory->deleteCalls );
		$this->removeFixture( $path );
	}

	public function testFailedClaimDoesNotDeleteChangedFile(): void {
		$factory  = new TemporaryFileFactoryStub();
		$artifact = $this->artifact( $factory );
		$path     = $factory->path();
		file_put_contents( $path, 'tampered' );

		$result = $artifact->claim();
		unset( $artifact );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_artifact_identity_changed', $result->get_error_code() );
		self::assertSame( 0, $factory->deleteCalls );
		$this->removeFixture( $path );
	}

	public function testClaimedDiscardReportsCleanupFailureWithoutRetrying(): void {
		$factory  = new TemporaryFileFactoryStub();
		$artifact = $this->artifact( $factory );
		$path     = $factory->path();
		$claimed  = $artifact->claim();
		self::assertNotInstanceOf( \WP_Error::class, $claimed );

		self::assertFalse( $claimed->discard() );
		self::assertFalse( $claimed->discard() );

		self::assertSame( 1, $factory->deleteCalls );
		$this->removeFixture( $path );
	}

	private function artifact( TemporaryFileFactoryStub $factory ): VerifiedArtifact {
		$path = $factory->create( 'artifact.zip' );
		self::assertIsString( $path );
		$identity = VerifiedArtifact::fileIdentity( $path );
		$bytes    = file_get_contents( $path );
		self::assertNotNull( $identity );
		self::assertIsString( $bytes );

		return new VerifiedArtifact(
			$path,
			hash( 'sha256', $bytes ),
			$factory,
			$identity
		);
	}

	private function removeFixture( string $path ): void {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
}

final class TemporaryFileFactoryStub implements TemporaryFileFactory {
	public int $deleteCalls = 0;

	private ?string $path = null;

	public function __construct( private bool $throwOnDelete = true ) {
	}

	public function create( string $filename ) {
		unset( $filename );
		$path = tempnam( sys_get_temp_dir(), 'ran-throwing-artifact-' );
		if ( false === $path ) {
			return new \WP_Error( 'fixture_failed', 'Could not create test fixture.' );
		}
		file_put_contents( $path, 'verified bytes' );
		$this->path = $path;

		return $path;
	}

	public function delete( string $path ): void {
		++$this->deleteCalls;
		if ( $this->throwOnDelete ) {
			throw new \RuntimeException( 'Simulated cleanup failure.' );
		}
		unlink( $path );
	}

	public function path(): string {
		if ( null === $this->path ) {
			throw new \LogicException( 'The fixture path is unavailable.' );
		}

		return $this->path;
	}
}
