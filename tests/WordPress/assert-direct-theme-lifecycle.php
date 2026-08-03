<?php
/**
 * Assert direct active-theme rejection and inactive-theme non-execution.
 */

if ( 'ran-updater-direct-active' !== get_stylesheet() ) {
	throw new RuntimeException( 'The direct-registration fixture theme is not active.' );
}
if ( true !== ( $GLOBALS['ran_updater_lifecycle_direct_theme_loaded'] ?? null ) ) {
	throw new RuntimeException( 'The active theme functions.php did not execute.' );
}
if ( array_key_exists( 'ran_updater_lifecycle_inactive_theme_loaded', $GLOBALS ) ) {
	throw new RuntimeException( 'The inactive theme functions.php executed.' );
}

$ran_updater_lifecycle_facade = $GLOBALS['ran_updater_lifecycle_direct_theme_facade'] ?? null;
if ( ! is_object( $ran_updater_lifecycle_facade )
	|| ! is_callable( array( $ran_updater_lifecycle_facade, 'diagnostics' ) ) ) {
	throw new RuntimeException( 'The direct active-theme facade is unavailable.' );
}
$ran_updater_lifecycle_diagnostics = $ran_updater_lifecycle_facade->diagnostics();
$ran_updater_lifecycle_expected    = array(
	'registered'      => true,
	'state'           => 'inactive',
	'code'            => 'late_registration',
	'selection_fixed' => true,
);
foreach ( $ran_updater_lifecycle_expected as $ran_updater_lifecycle_key => $ran_updater_lifecycle_value ) {
	if ( ( $ran_updater_lifecycle_diagnostics[ $ran_updater_lifecycle_key ] ?? null ) !== $ran_updater_lifecycle_value ) {
		throw new RuntimeException( 'Unexpected direct-theme diagnostic: ' . $ran_updater_lifecycle_key );
	}
}

if ( ran_wp_github_release_updater_v1_has_registered_target( 'theme', 'ran-updater-direct-active' ) ) {
	throw new RuntimeException( 'The late direct-theme target was accepted.' );
}
if ( ran_wp_github_release_updater_v1_has_registered_target( 'theme', 'ran-updater-inactive' ) ) {
	throw new RuntimeException( 'The inactive self-registering theme target was accepted.' );
}

WP_CLI::success( 'Direct active-theme rejection and inactive-theme non-execution passed.' );
