<?php
/**
 * Assert early plugin registration for active and inactive themes.
 */

if ( 'ran-updater-registrar-active' !== get_stylesheet() ) {
	throw new RuntimeException( 'The early-registrar active fixture theme is not active.' );
}
if ( array_key_exists( 'ran_updater_lifecycle_direct_theme_loaded', $GLOBALS )
	|| array_key_exists( 'ran_updater_lifecycle_inactive_theme_loaded', $GLOBALS ) ) {
	throw new RuntimeException( 'An inactive theme functions.php executed.' );
}

$ran_updater_lifecycle_plugin_facade = $GLOBALS['ran_updater_lifecycle_plugin_facade'] ?? null;
if ( ! is_object( $ran_updater_lifecycle_plugin_facade )
	|| ! is_callable( array( $ran_updater_lifecycle_plugin_facade, 'diagnostics' ) ) ) {
	throw new RuntimeException( 'The standalone plugin facade is unavailable.' );
}
$ran_updater_lifecycle_plugin_diagnostics = $ran_updater_lifecycle_plugin_facade->diagnostics();
if ( 'active' !== ( $ran_updater_lifecycle_plugin_diagnostics['state'] ?? null )
	|| ! ran_wp_github_release_updater_v1_has_registered_target(
		'plugin',
		'ran-updater-lifecycle-registrar/lifecycle-registrar.php'
	) ) {
	throw new RuntimeException( 'Standalone plugin registration is not active.' );
}

$ran_updater_lifecycle_facades = $GLOBALS['ran_updater_lifecycle_early_facades'] ?? null;
if ( ! is_array( $ran_updater_lifecycle_facades ) ) {
	throw new RuntimeException( 'The early theme registrar did not execute.' );
}

foreach ( array( 'ran-updater-registrar-active', 'ran-updater-registrar-inactive' ) as $ran_updater_lifecycle_stylesheet ) {
	$ran_updater_lifecycle_facade = $ran_updater_lifecycle_facades[ $ran_updater_lifecycle_stylesheet ] ?? null;
	if ( ! is_object( $ran_updater_lifecycle_facade )
		|| ! is_callable( array( $ran_updater_lifecycle_facade, 'diagnostics' ) ) ) {
		throw new RuntimeException( 'An early theme facade is unavailable: ' . $ran_updater_lifecycle_stylesheet );
	}
	$ran_updater_lifecycle_diagnostics = $ran_updater_lifecycle_facade->diagnostics();
	if ( true !== ( $ran_updater_lifecycle_diagnostics['registered'] ?? null )
		|| 'active' !== ( $ran_updater_lifecycle_diagnostics['state'] ?? null )
		|| true !== ( $ran_updater_lifecycle_diagnostics['selection_fixed'] ?? null ) ) {
		throw new RuntimeException( 'An early theme target is not active: ' . $ran_updater_lifecycle_stylesheet );
	}
	if ( ! ran_wp_github_release_updater_v1_has_registered_target( 'theme', $ran_updater_lifecycle_stylesheet ) ) {
		throw new RuntimeException( 'An early theme target was not accepted: ' . $ran_updater_lifecycle_stylesheet );
	}
}

WP_CLI::success( 'Early active and inactive theme registration passed.' );
