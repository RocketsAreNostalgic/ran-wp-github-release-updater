<?php

declare(strict_types=1);

namespace Tests\Artifact;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseVersion;

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

final class ReleaseVersionTest extends TestCase {

	public function testAcceptedVersionOrderIsAntisymmetricAndTransitive(): void {
		$ordered = array(
			'0.0.1',
			'1.0.0-999999999999999999999999999999',
			'1.0.0-1000000000000000000000000000000',
			'1.0.0-alpha',
			'1.0.0-alpha.1',
			'1.0.0-alpha.beta',
			'1.0.0-beta',
			'1.0.0-beta.2',
			'1.0.0-beta.11',
			'1.0.0-rc.1',
			'1.0.0',
			'1.0.1-x.1',
			'1.0.1-y.1',
			'1.0.1',
			'1.1.0',
			'2.0.0',
		);

		foreach ( $ordered as $leftIndex => $left ) {
			self::assertSame( 0, ReleaseVersion::compare( $left, $left ) );
			self::assertSame(
				ReleaseVersion::RELATIONSHIP_SAME,
				ReleaseVersion::relationship( $left, $left )
			);
			foreach ( $ordered as $rightIndex => $right ) {
				if ( $leftIndex >= $rightIndex ) {
					continue;
				}
				self::assertSame( -1, ReleaseVersion::compare( $left, $right ) );
				self::assertSame( 1, ReleaseVersion::compare( $right, $left ) );
				self::assertSame(
					ReleaseVersion::RELATIONSHIP_OLDER,
					ReleaseVersion::relationship( $left, $right )
				);
				self::assertSame(
					ReleaseVersion::RELATIONSHIP_NEWER,
					ReleaseVersion::relationship( $right, $left )
				);

				foreach ( $ordered as $thirdIndex => $third ) {
					if ( $rightIndex >= $thirdIndex ) {
						continue;
					}
					self::assertSame( -1, ReleaseVersion::compare( $left, $third ) );
				}
			}
		}
	}

	public function testStableHeaderShorthandHasCanonicalEquality(): void {
		self::assertSame( 0, ReleaseVersion::compare( '2.1', '2.1.0' ) );
		self::assertSame(
			ReleaseVersion::RELATIONSHIP_SAME,
			ReleaseVersion::relationship( '2.1', '2.1.0' )
		);
	}

	/**
	 * @dataProvider invalidComparisonProvider
	 */
	public function testInvalidVersionsHaveOneFixedRelationship( string $version ): void {
		self::assertNull( ReleaseVersion::compare( $version, '1.0.0' ) );
		self::assertNull( ReleaseVersion::compare( '1.0.0', $version ) );
		self::assertSame(
			ReleaseVersion::RELATIONSHIP_INVALID,
			ReleaseVersion::relationship( $version, '1.0.0' )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function invalidComparisonProvider(): array {
		return array(
			'build metadata remains outside contract' => array( '1.0.0+build.1' ),
			'leading version marker is tag-only'      => array( 'v1.0.0' ),
			'short prerelease is not canonical'       => array( '1.0-beta.1' ),
			'leading numeric zero'                    => array( '01.0.0' ),
		);
	}
}
