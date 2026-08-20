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
	private const DISCOVERY_LEASE = 'RAN_WP_GITHUB_RELEASE_UPDATER_DISCOVERY_LEASE_SECONDS';
	private const INSTALL_LEASE   = 'RAN_WP_GITHUB_RELEASE_UPDATER_INSTALL_LEASE_SECONDS';

	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
		putenv( self::DISCOVERY_LEASE );
		putenv( self::INSTALL_LEASE );
	}

	protected function tearDown(): void {
		putenv( self::DISCOVERY_LEASE );
		putenv( self::INSTALL_LEASE );
		parent::tearDown();
	}

	public function testLeaseDefaultsMatchTheCoreEquivalentOperatingBoundary(): void {
		$coordinator = $this->coordinator();
		self::assertSame( 600, $coordinator->discoveryLeaseSeconds() );
		self::assertSame( 3600, $coordinator->installLeaseSeconds() );
	}

	public function testLeaseDurationsAcceptBoundedEnvironmentOverrides(): void {
		putenv( self::DISCOVERY_LEASE . '=900' );
		putenv( self::INSTALL_LEASE . '=7200' );

		$coordinator = $this->coordinator();
		self::assertSame( 900, $coordinator->discoveryLeaseSeconds() );
		self::assertSame( 7200, $coordinator->installLeaseSeconds() );
	}

	public function testLeaseDurationsAcceptEveryDocumentedBoundary(): void {
		foreach ( array( 60, 3600 ) as $seconds ) {
			putenv( self::DISCOVERY_LEASE . '=' . $seconds );
			self::assertSame( $seconds, $this->coordinator()->discoveryLeaseSeconds() );
		}
		foreach ( array( 600, 86400 ) as $seconds ) {
			putenv( self::INSTALL_LEASE . '=' . $seconds );
			self::assertSame( $seconds, $this->coordinator()->installLeaseSeconds() );
		}
	}

	public function testLeaseConfigurationIsSnapshottedPerCoordinator(): void {
		putenv( self::DISCOVERY_LEASE . '=900' );
		$first = $this->coordinator();
		putenv( self::DISCOVERY_LEASE . '=1200' );

		self::assertSame( 900, $first->discoveryLeaseSeconds() );
		self::assertSame( 1200, $this->coordinator()->discoveryLeaseSeconds() );
	}

	public function testProcessesWithDifferentConfigurationStillShareDatabaseOwnership(): void {
		putenv( self::INSTALL_LEASE . '=600' );
		$firstCoordinator = $this->coordinator();
		$first            = $firstCoordinator->acquire(
			'plugin\0example\0example.php',
			'native_install:first',
			$firstCoordinator->installLeaseSeconds()
		);
		self::assertInstanceOf( ReleaseOperationClaim::class, $first );

		putenv( self::INSTALL_LEASE . '=3600' );
		$secondCoordinator = $this->coordinator();
		$busy              = $secondCoordinator->acquire(
			'plugin\0example\0example.php',
			'native_install:second',
			$secondCoordinator->installLeaseSeconds()
		);
		self::assertInstanceOf( \WP_Error::class, $busy );
		self::assertSame( 'github_updater_operation_busy', $busy->get_error_code() );

		$database = $GLOBALS['wpdb'];
		self::assertInstanceOf( FakeWpdb::class, $database );
		$database->now += 601;
		$takeover       = $secondCoordinator->acquire(
			'plugin\0example\0example.php',
			'native_install:second',
			$secondCoordinator->installLeaseSeconds()
		);
		self::assertInstanceOf( ReleaseOperationClaim::class, $takeover );
		self::assertSame( 2, $takeover->generation() );
		self::assertFalse( $firstCoordinator->release( $first ) );
		self::assertTrue( $secondCoordinator->release( $takeover ) );
	}

	public function testInvalidOrUnsafeEnvironmentOverridesUseSafeDefaults(): void {
		foreach ( array( '0', '59', '3601', '-1', ' 900 ', 'invalid' ) as $value ) {
			putenv( self::DISCOVERY_LEASE . '=' . $value );
			self::assertSame( 600, $this->coordinator()->discoveryLeaseSeconds() );
		}
		foreach ( array( '0', '599', '86401', '-1', ' 7200 ', 'invalid' ) as $value ) {
			putenv( self::INSTALL_LEASE . '=' . $value );
			self::assertSame( 3600, $this->coordinator()->installLeaseSeconds() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testWordPressConstantsTakePrecedenceOverEnvironmentOverrides(): void {
		putenv( self::DISCOVERY_LEASE . '=900' );
		putenv( self::INSTALL_LEASE . '=7200' );
		define( self::DISCOVERY_LEASE, 1200 );
		define( self::INSTALL_LEASE, '10800' );

		$coordinator = $this->coordinator();
		self::assertSame( 1200, $coordinator->discoveryLeaseSeconds() );
		self::assertSame( 10800, $coordinator->installLeaseSeconds() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testInvalidWordPressConstantsUseDefaultsInsteadOfEnvironmentFallback(): void {
		putenv( self::DISCOVERY_LEASE . '=900' );
		putenv( self::INSTALL_LEASE . '=7200' );
		define( self::DISCOVERY_LEASE, 'invalid' );
		define( self::INSTALL_LEASE, 86401 );

		$coordinator = $this->coordinator();
		self::assertSame( 600, $coordinator->discoveryLeaseSeconds() );
		self::assertSame( 3600, $coordinator->installLeaseSeconds() );
	}

	public function testCoordinatorAcceptsOneDayButRejectsLongerClaims(): void {
		$coordinator = $this->coordinator();
		$accepted    = $coordinator->acquire( 'plugin\0example\0example.php', 'native_install:42', 86400 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $accepted );
		self::assertTrue( $coordinator->release( $accepted ) );

		$rejected = $coordinator->acquire( 'plugin\0example\0example.php', 'native_install:43', 86401 );
		self::assertInstanceOf( \WP_Error::class, $rejected );
		self::assertSame( 'github_updater_operation_invalid', $rejected->get_error_code() );
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

	public function testAffectedAcquisitionInvalidatesOnlyItsSlotAndReleaseCannotResurrectIt(): void {
		$coordinator = $this->coordinator();
		$target      = 'plugin\0example\0example.php';
		$this->publishState( $coordinator, $target, ReleaseOperationCoordinator::MANAGED_STATE, array( 'managed' => true ) );
		$this->publishState( $coordinator, $target, ReleaseOperationCoordinator::NATIVE_STATE, array( 'native' => true ) );

		$claim = $coordinator->acquire( $target, 'native_discovery:refresh', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );
		self::assertSame( array( 'native' => true ), $claim->invalidatedResults()[ ReleaseOperationCoordinator::NATIVE_STATE ] );
		self::assertSame( array( 'managed' => true ), $coordinator->state( $target, ReleaseOperationCoordinator::MANAGED_STATE ) );
		self::assertSame( array(), $coordinator->state( $target, ReleaseOperationCoordinator::NATIVE_STATE ) );

		self::assertTrue( $coordinator->release( $claim ) );
		self::assertSame( array( 'managed' => true ), $coordinator->state( $target, ReleaseOperationCoordinator::MANAGED_STATE ) );
		self::assertSame( array(), $coordinator->state( $target, ReleaseOperationCoordinator::NATIVE_STATE ) );
	}

	public function testLostAffectedClaimCannotResurrectItsInvalidatedSlot(): void {
		$coordinator = $this->coordinator();
		$target      = 'plugin\0example\0example.php';
		$this->publishState( $coordinator, $target, ReleaseOperationCoordinator::NATIVE_STATE, array( 'native' => true ) );
		$stale = $coordinator->acquire( $target, 'native_install:42', 5 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $stale );

		$database = $GLOBALS['wpdb'];
		self::assertInstanceOf( FakeWpdb::class, $database );
		$database->now += 6;
		$winner         = $coordinator->acquire( $target, 'managed_preflight:takeover', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $winner );

		self::assertFalse( $coordinator->release( $stale ) );
		self::assertSame( array(), $coordinator->state( $target, ReleaseOperationCoordinator::NATIVE_STATE ) );
		self::assertTrue( $coordinator->release( $winner ) );
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

	/** @param array<string, mixed> $state */
	private function publishState(
		ReleaseOperationCoordinator $coordinator,
		string $target,
		string $slot,
		array $state
	): void {
		$claim = $coordinator->acquire( $target, 'test_seed', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );
		self::assertTrue( $coordinator->publish( $claim, $slot, $state ) );
	}
}
