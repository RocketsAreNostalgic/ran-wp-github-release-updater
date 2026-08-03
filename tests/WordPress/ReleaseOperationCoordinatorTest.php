<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseOperationClaim;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
use Tests\Support\FakeWpdb;
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

final class ReleaseOperationCoordinatorTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
	}

	public function testAcquireIsExclusiveAndReleaseRetainsOneMonotonicTombstone(): void {
		$coordinator = $this->coordinator();
		$first       = $coordinator->acquire( 'plugin\0example\0example.php', 'managed_preflight', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $first );

		$busy = $coordinator->acquire( 'plugin\0example\0example.php', 'native_discovery', 30 );
		self::assertInstanceOf( \WP_Error::class, $busy );
		self::assertSame( 'github_updater_operation_busy', $busy->get_error_code() );
		self::assertTrue( $coordinator->release( $first ) );

		$second = $coordinator->acquire( 'plugin\0example\0example.php', 'native_install:42', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $second );
		self::assertSame( 2, $second->generation() );
		self::assertTrue( $coordinator->release( $second ) );

		$database = $GLOBALS['wpdb'];
		self::assertInstanceOf( FakeWpdb::class, $database );
		self::assertCount( 1, $database->rows['wp_options'] );
		$row = json_decode( array_values( $database->rows['wp_options'] )[0], true );
		self::assertSame( 2, $row['generation'] );
		self::assertSame( '', $row['owner'] );
		self::assertSame( '', $row['operation'] );
	}

	public function testExpiredOwnerCannotPublishOrReleaseAfterTakeover(): void {
		$coordinator = $this->coordinator();
		$stale       = $coordinator->acquire( 'theme\0example\0style.css', 'native_discovery', 5 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $stale );
		$database = $GLOBALS['wpdb'];
		self::assertInstanceOf( FakeWpdb::class, $database );
		$database->now += 6;

		$winner = $coordinator->acquire( 'theme\0example\0style.css', 'native_install:42', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $winner );
		$result = $coordinator->publish( $stale, ReleaseOperationCoordinator::NATIVE_STATE, array( 'winner' => false ) );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_operation_fence_lost', $result->get_error_code() );
		self::assertFalse( $coordinator->release( $stale ) );

		self::assertTrue(
			$coordinator->publish(
				$winner,
				ReleaseOperationCoordinator::NATIVE_STATE,
				array( 'winner' => true )
			)
		);
		self::assertSame(
			array( 'winner' => true ),
			$coordinator->state( 'theme\0example\0style.css', ReleaseOperationCoordinator::NATIVE_STATE )
		);
	}

	public function testMultisiteUsesCurrentNetworksMainSiteRowAcrossBlogs(): void {
		WordPressState::$multisite  = true;
		WordPressState::$mainSiteId = 7;
		$coordinator                = $this->coordinator();
		$claim                      = $coordinator->acquire( 'plugin\0example\0example.php', 'managed_preflight', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );
		self::assertSame( 'wp_7_options', $claim->table() );

		$busy = $coordinator->acquire( 'plugin\0example\0example.php', 'native_discovery', 30 );
		self::assertInstanceOf( \WP_Error::class, $busy );
		self::assertSame( 'github_updater_operation_busy', $busy->get_error_code() );
	}

	public function testRenewFencesTheExactObservedRow(): void {
		$coordinator = $this->coordinator();
		$claim       = $coordinator->acquire( 'plugin\0example\0example.php', 'native_install:42', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );
		$database = $GLOBALS['wpdb'];
		self::assertInstanceOf( FakeWpdb::class, $database );
		$database->now += 4;
		$renewed        = $coordinator->renew( $claim, 60 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $renewed );
		self::assertSame( $claim->generation(), $renewed->generation() );
		self::assertSame( 1064, $renewed->expiresAt() );
		self::assertFalse( $coordinator->release( $claim ) );
		self::assertTrue( $coordinator->release( $renewed ) );
	}

	public function testRapidRenewalChangesTheExactRowWithinOneDatabaseSecond(): void {
		$coordinator = $this->coordinator();
		$claim       = $coordinator->acquire( 'plugin\0example\0example.php', 'native_install:42', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );

		$renewed = $coordinator->renew( $claim, 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $renewed );
		self::assertSame( $claim->expiresAt() + 1, $renewed->expiresAt() );
		self::assertNotSame( $claim->raw(), $renewed->raw() );

		$renewedAgain = $coordinator->renew( $renewed, 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $renewedAgain );
		self::assertSame( $renewed->expiresAt() + 1, $renewedAgain->expiresAt() );
		self::assertNotSame( $renewed->raw(), $renewedAgain->raw() );
		self::assertTrue( $coordinator->release( $renewedAgain ) );
	}

	public function testMalformedActiveRowFailsClosed(): void {
		$coordinator = $this->coordinator();
		$claim       = $coordinator->acquire( 'plugin\0example\0example.php', 'native_discovery', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );
		$database = $GLOBALS['wpdb'];
		self::assertInstanceOf( FakeWpdb::class, $database );
		$row                = json_decode( $claim->raw(), true );
		$row['owner']       = 'short-owner';
		$row['acquired_at'] = 0;
		$database->rows[ $claim->table() ][ $claim->name() ] = json_encode( $row );

		$result = $coordinator->acquire( 'plugin\0example\0example.php', 'native_install:42', 30 );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_operation_corrupt', $result->get_error_code() );
	}

	public function testOversizedPublicationIsRejectedBeforeCas(): void {
		$coordinator = $this->coordinator();
		$claim       = $coordinator->acquire( 'plugin\0example\0example.php', 'native_discovery', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );
		$result = $coordinator->publish(
			$claim,
			ReleaseOperationCoordinator::NATIVE_STATE,
			array( 'oversized' => str_repeat( 'x', 65536 ) )
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_operation_state_too_large', $result->get_error_code() );
		self::assertTrue( $coordinator->release( $claim ) );
	}

	private function coordinator(): ReleaseOperationCoordinator {
		return new ReleaseOperationCoordinator();
	}
}
