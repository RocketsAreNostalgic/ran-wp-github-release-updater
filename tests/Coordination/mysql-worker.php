<?php
/**
 * One process in the real-MySQL release-operation coordination proof.
 */

use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseOperationClaim;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;

$ran_coord_runtime = getenv( 'RAN_UPDATER_RUNTIME' );
if ( ! is_string( $ran_coord_runtime ) || ! is_file( $ran_coord_runtime ) ) {
	throw new RuntimeException( 'RAN_UPDATER_RUNTIME must identify the updater runtime.' );
}
require_once $ran_coord_runtime;

/**
 * Read one required environment value.
 */
function ran_coord_env( string $name ): string {
	$value = getenv( $name );
	if ( ! is_string( $value ) || '' === $value ) {
		throw new RuntimeException( 'Missing coordination proof value: ' . $name );
	}

	return $value;
}

/**
 * Wait for a filesystem barrier with a strict timeout.
 */
function ran_coord_wait( string $path ): void {
	$deadline = microtime( true ) + 20;
	while ( ! is_file( $path ) ) {
		if ( microtime( true ) >= $deadline ) {
			throw new RuntimeException( 'Timed out at coordination proof barrier.' );
		}
		usleep( 10_000 );
	}
}

/**
 * Emit one machine-readable worker result.
 *
 * @param array<string, bool|int|string> $result Result fields.
 */
function ran_coord_result( array $result ): void {
	echo 'RAN_COORD_RESULT=' . wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) . PHP_EOL;
}

/**
 * Resolve the exact authority table selected by the production coordinator.
 */
function ran_coord_authority_table(): string {
	global $wpdb;

	if ( ! is_multisite() ) {
		return $wpdb->options;
	}

	return $wpdb->get_blog_prefix( get_main_site_id( get_current_network_id() ) ) . 'options';
}

$ran_coord_action    = ran_coord_env( 'RAN_COORD_ACTION' );
$ran_coord_target    = ran_coord_env( 'RAN_COORD_TARGET' );
$ran_coord_operation = ran_coord_env( 'RAN_COORD_OPERATION' );
$ran_coord_name      = 'ran_wp_gh_op_v1_' . substr( hash( 'sha256', $ran_coord_target ), 0, 32 );
$ran_coord           = new ReleaseOperationCoordinator();

if ( 'configured-install' === $ran_coord_action ) {
	$ran_coord_ttl   = $ran_coord->installLeaseSeconds();
	$ran_coord_claim = $ran_coord->acquire( $ran_coord_target, $ran_coord_operation, $ran_coord_ttl );
	if ( ! $ran_coord_claim instanceof ReleaseOperationClaim ) {
		throw new RuntimeException( 'The configured-install worker did not acquire its claim.' );
	}
	ran_coord_result(
		array(
			'kind'       => 'configured-install',
			'ttl'        => $ran_coord_ttl,
			'lifetime'   => $ran_coord_claim->expiresAt() - $ran_coord_claim->acquiredAt(),
			'generation' => $ran_coord_claim->generation(),
			'released'   => $ran_coord->release( $ran_coord_claim ),
		)
	);
	return;
}

if ( 'rapid-renew' === $ran_coord_action ) {
	$ran_coord_claim = $ran_coord->acquire( $ran_coord_target, $ran_coord_operation, 300 );
	if ( ! $ran_coord_claim instanceof ReleaseOperationClaim ) {
		throw new RuntimeException( 'The rapid-renew worker did not acquire its claim.' );
	}
	$ran_coord_first = $ran_coord->renew( $ran_coord_claim, 300 );
	if ( ! $ran_coord_first instanceof ReleaseOperationClaim ) {
		throw new RuntimeException( 'The first same-second renewal lost ownership.' );
	}
	$ran_coord_second = $ran_coord->renew( $ran_coord_first, 300 );
	if ( ! $ran_coord_second instanceof ReleaseOperationClaim ) {
		throw new RuntimeException( 'The second same-second renewal lost ownership.' );
	}
	ran_coord_result(
		array(
			'kind'            => 'rapid-renew',
			'generation'      => $ran_coord_second->generation(),
			'first_advanced'  => $ran_coord_first->expiresAt() > $ran_coord_claim->expiresAt(),
			'second_advanced' => $ran_coord_second->expiresAt() > $ran_coord_first->expiresAt(),
			'released'        => $ran_coord->release( $ran_coord_second ),
		)
	);
	return;
}

if ( 'acquire' === $ran_coord_action ) {
	$ran_coord_ready = ran_coord_env( 'RAN_COORD_READY' );
	$ran_coord_go    = ran_coord_env( 'RAN_COORD_GO' );
	file_put_contents( $ran_coord_ready, 'ready', LOCK_EX );
	ran_coord_wait( $ran_coord_go );
	$ran_coord_claim = $ran_coord->acquire( $ran_coord_target, $ran_coord_operation, 300 );
	if ( $ran_coord_claim instanceof ReleaseOperationClaim ) {
		ran_coord_result(
			array(
				'kind'       => 'claim',
				'generation' => $ran_coord_claim->generation(),
				'table'      => $ran_coord_claim->table(),
				'name'       => $ran_coord_claim->name(),
			)
		);
		return;
	}

	ran_coord_result(
		array(
			'kind' => 'error',
			'code' => $ran_coord_claim->get_error_code(),
		)
	);
	return;
}

if ( 'hold-stale' === $ran_coord_action ) {
	$ran_coord_ready  = ran_coord_env( 'RAN_COORD_READY' );
	$ran_coord_resume = ran_coord_env( 'RAN_COORD_GO' );
	$ran_coord_claim  = $ran_coord->acquire( $ran_coord_target, $ran_coord_operation, 300 );
	if ( ! $ran_coord_claim instanceof ReleaseOperationClaim ) {
		throw new RuntimeException( 'The stale-owner worker did not acquire its initial claim.' );
	}
	file_put_contents( $ran_coord_ready, 'ready', LOCK_EX );
	ran_coord_wait( $ran_coord_resume );
	$ran_coord_published = $ran_coord->publish(
		$ran_coord_claim,
		ReleaseOperationCoordinator::MANAGED_STATE,
		array( 'marker' => 'stale' )
	);
	$ran_coord_released  = $ran_coord->release( $ran_coord_claim );
	ran_coord_result(
		array(
			'kind'         => 'stale',
			'publish_code' => $ran_coord_published instanceof WP_Error
				? $ran_coord_published->get_error_code()
				: 'published',
			'released'     => $ran_coord_released,
		)
	);
	return;
}

if ( 'expire' === $ran_coord_action ) {
	global $wpdb;

	$ran_coord_table = ran_coord_authority_table();
	$ran_coord_raw   = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
			$ran_coord_table,
			$ran_coord_name
		)
	);
	$ran_coord_row   = is_string( $ran_coord_raw ) ? json_decode( $ran_coord_raw, true ) : null;
	if ( ! is_array( $ran_coord_row ) ) {
		throw new RuntimeException( 'The authority row could not be expired.' );
	}
	$ran_coord_row['acquired_at'] = 1;
	$ran_coord_row['expires_at']  = 2;
	$ran_coord_expired            = wp_json_encode( $ran_coord_row, JSON_UNESCAPED_SLASHES );
	$ran_coord_changed            = $wpdb->query(
		$wpdb->prepare(
			'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
			$ran_coord_table,
			$ran_coord_expired,
			$ran_coord_name,
			$ran_coord_raw
		)
	);
	if ( 1 !== $ran_coord_changed ) {
		throw new RuntimeException( 'The authority row expiry setup lost its exact-row comparison.' );
	}
	ran_coord_result( array( 'kind' => 'expired' ) );
	return;
}

if ( 'inspect' === $ran_coord_action ) {
	global $wpdb;

	$ran_coord_authority_table = ran_coord_authority_table();
	$ran_coord_child_table     = $wpdb->options;
	$ran_coord_authority_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE option_name = %s',
			$ran_coord_authority_table,
			$ran_coord_name
		)
	);
	$ran_coord_child_count     = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE option_name = %s',
			$ran_coord_child_table,
			$ran_coord_name
		)
	);
	$ran_coord_stale_state     = $ran_coord->state(
		$ran_coord_target,
		ReleaseOperationCoordinator::MANAGED_STATE
	);
	ran_coord_result(
		array(
			'kind'            => 'inspection',
			'authority_count' => $ran_coord_authority_count,
			'child_count'     => $ran_coord_child_count,
			'stale_marker'    => 'stale' === ( $ran_coord_stale_state['marker'] ?? null ),
		)
	);
	return;
}

throw new RuntimeException( 'Unknown coordination proof action.' );
