<?php

declare(strict_types=1);

namespace Tests\Artifact;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact;
use Tests\Support\WordPressState;

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

final class ClaimedArtifactTest extends TestCase {

	/** @var list<string> */
	private array $paths = array();

	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
	}

	protected function tearDown(): void {
		foreach ( $this->paths as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		parent::tearDown();
	}

	public function testExactCoreUpdateClaimIsOneUseAndCleansUp(): void {
		$path  = $this->artifact();
		$claim = $this->claim( $path );

		self::assertSame(
			'1.2.3',
			$claim->acceptCoreUpdate( 'plugin', 'example/example.php', 'update', $path )
		);
		$this->expectException( \RuntimeException::class );
		try {
			$claim->acceptCoreUpdate( 'plugin', 'example/example.php', 'update', $path );
		} finally {
			self::assertTrue( $claim->discard() );
			self::assertFileDoesNotExist( $path );
			self::assertContains( $path, WordPressState::$deletedFiles );
		}
	}

	/**
	 * @dataProvider wrongCoreBindingProvider
	 */
	public function testCoreUpdateClaimRejectsWrongBinding(
		string $type,
		string $identifier,
		string $action
	): void {
		$path  = $this->artifact();
		$claim = $this->claim( $path );

		$this->expectException( \RuntimeException::class );
		try {
			$claim->acceptCoreUpdate( $type, $identifier, $action, $path );
		} finally {
			self::assertTrue( $claim->discard() );
		}
	}

	/** @return array<string, array{string, string, string}> */
	public static function wrongCoreBindingProvider(): array {
		return array(
			'wrong type'       => array( 'theme', 'example/example.php', 'update' ),
			'wrong identifier' => array( 'plugin', 'other/other.php', 'update' ),
			'wrong action'     => array( 'plugin', 'example/example.php', 'install' ),
		);
	}

	public function testCoreUpdateClaimRejectsWrongPathAndDeletesOnlyClaimedFile(): void {
		$path      = $this->artifact();
		$otherPath = $this->artifact( 'other bytes' );
		$claim     = $this->claim( $path );

		$this->expectException( \RuntimeException::class );
		try {
			$claim->acceptCoreUpdate( 'plugin', 'example/example.php', 'update', $otherPath );
		} finally {
			self::assertTrue( $claim->discard() );
			self::assertFileDoesNotExist( $path );
			self::assertFileExists( $otherPath );
		}
	}

	public function testCoreUpdateClaimRejectsSubstitutedBytesWithoutDeletingThem(): void {
		$path  = $this->artifact( 'verified bytes' );
		$claim = $this->claim( $path );
		file_put_contents( $path, 'changed! bytes' );

		$this->expectException( \RuntimeException::class );
		try {
			$claim->acceptCoreUpdate( 'plugin', 'example/example.php', 'update', $path );
		} finally {
			self::assertFalse( $claim->discard() );
			self::assertSame( 'changed! bytes', file_get_contents( $path ) );
			self::assertNotContains( $path, WordPressState::$deletedFiles );
		}
	}

	public function testCoreUpdateFactoryRejectsDigestMismatch(): void {
		$path = $this->artifact();

		$this->expectException( \RuntimeException::class );
		ClaimedArtifact::forCoreUpdate(
			$path,
			str_repeat( 'a', 63 ),
			'plugin',
			'example/example.php',
			'1.2.3'
		);
	}

	private function claim( string $path ): ClaimedArtifact {
		$bytes = file_get_contents( $path );
		self::assertIsString( $bytes );
		return ClaimedArtifact::forCoreUpdate(
			$path,
			hash( 'sha256', $bytes ),
			'plugin',
			'example/example.php',
			'1.2.3'
		);
	}

	private function artifact( string $bytes = 'verified bytes' ): string {
		$path = tempnam( sys_get_temp_dir(), 'ran-core-claim-' );
		self::assertIsString( $path );
		file_put_contents( $path, $bytes );
		$this->paths[] = $path;
		return $path;
	}
}
