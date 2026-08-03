<?php

// Executed by WP-CLI after the proof process exits and Core runs its shutdown restoration.
// phpcs:disable

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

function ran_updater_readback_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ran_updater_readback_version( string $file, string $type ): string {
	$data = 'plugin' === $type
		? get_plugin_data( $file, false, false )
		: get_file_data( $file, array( 'Version' => 'Version' ), 'theme' );

	return is_string( $data['Version'] ?? null ) ? $data['Version'] : '';
}

function ran_updater_readback_directory_digest( string $directory ): string {
	$entries  = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $iterator as $entry ) {
		$path     = $entry->getPathname();
		$relative = substr( $path, strlen( trailingslashit( $directory ) ) );
		if ( $entry->isDir() ) {
			$entries[] = 'd:' . $relative;
			continue;
		}
		$contents = file_get_contents( $path );
		ran_updater_readback_assert( is_string( $contents ), 'A restored lifecycle proof file could not be read.' );
		$entries[] = 'f:' . $relative . ':' . hash( 'sha256', $contents );
	}
	sort( $entries, SORT_STRING );

	return hash( 'sha256', implode( "\n", $entries ) );
}

$ran_readback_plugin_slug       = 'ran-updater-proof-plugin';
$ran_readback_plugin_directory  = 'ran-updater-proof-plugin-renamed';
$ran_readback_plugin_identifier = $ran_readback_plugin_directory . '/' . $ran_readback_plugin_slug . '.php';
$ran_readback_plugin_root       = WP_PLUGIN_DIR . '/' . $ran_readback_plugin_directory;
$ran_readback_plugin_file       = WP_PLUGIN_DIR . '/' . $ran_readback_plugin_identifier;
$ran_readback_auto_plugin_slug  = 'ran-updater-proof-auto-plugin';
$ran_readback_auto_plugin_identifier = $ran_readback_auto_plugin_slug . '/' . $ran_readback_auto_plugin_slug . '.php';
$ran_readback_auto_plugin_root  = WP_PLUGIN_DIR . '/' . $ran_readback_auto_plugin_slug;
$ran_readback_auto_plugin_file  = WP_PLUGIN_DIR . '/' . $ran_readback_auto_plugin_identifier;
$ran_readback_active_theme      = 'ran-updater-proof-theme-active';
$ran_readback_inactive_theme    = 'ran-updater-proof-theme-inactive';
$ran_readback_theme_root        = get_theme_root();
$ran_readback_state             = get_option( 'ran_updater_lifecycle_proof_state' );
$ran_readback_original_theme    = is_array( $ran_readback_state ) && is_string( $ran_readback_state['original_theme'] ?? null )
	? $ran_readback_state['original_theme']
	: '';
$ran_readback_owns_targets      = is_array( $ran_readback_state )
	&& $ran_readback_plugin_identifier === ( $ran_readback_state['plugin_identifier'] ?? null )
	&& $ran_readback_auto_plugin_identifier === ( $ran_readback_state['auto_plugin_identifier'] ?? null )
	&& $ran_readback_active_theme === ( $ran_readback_state['active_theme'] ?? null )
	&& $ran_readback_inactive_theme === ( $ran_readback_state['inactive_theme'] ?? null );

try {
	ran_updater_readback_assert( is_array( $ran_readback_state ), 'The lifecycle proof did not persist readback state.' );
	ran_updater_readback_assert( $ran_readback_plugin_identifier === ( $ran_readback_state['plugin_identifier'] ?? null ), 'The lifecycle proof plugin identity changed unexpectedly.' );
	ran_updater_readback_assert( $ran_readback_auto_plugin_identifier === ( $ran_readback_state['auto_plugin_identifier'] ?? null ), 'The lifecycle proof automatic plugin identity changed unexpectedly.' );
	ran_updater_readback_assert( $ran_readback_active_theme === ( $ran_readback_state['active_theme'] ?? null ), 'The active lifecycle proof theme identity changed unexpectedly.' );
	ran_updater_readback_assert( $ran_readback_inactive_theme === ( $ran_readback_state['inactive_theme'] ?? null ), 'The inactive lifecycle proof theme identity changed unexpectedly.' );
	ran_updater_readback_assert( true === ( $ran_readback_state['failure_reached'] ?? false ), 'The injected early-copy failure was not reached.' );
	ran_updater_readback_assert( true === ( $ran_readback_state['copy_result_was_empty'] ?? false ), 'Core no longer left WP_Upgrader::$result empty for the injected early-copy failure.' );

	ran_updater_readback_assert( '2.0.0' === ran_updater_readback_version( $ran_readback_plugin_file, 'plugin' ), 'The renamed plugin update was not retained after readback.' );
	ran_updater_readback_assert( 'plugin-manual-renamed' === file_get_contents( $ran_readback_plugin_root . '/marker.txt' ), 'The renamed plugin bytes were not retained after readback.' );
	ran_updater_readback_assert( ! file_exists( WP_PLUGIN_DIR . '/' . $ran_readback_plugin_slug ), 'The renamed plugin update escaped into its canonical package directory.' );
	ran_updater_readback_assert( '3.0.0' === ran_updater_readback_version( $ran_readback_auto_plugin_file, 'plugin' ), 'The successful automatic plugin version was not retained after readback.' );
	ran_updater_readback_assert( 'plugin-automatic-success' === file_get_contents( $ran_readback_auto_plugin_root . '/marker.txt' ), 'The active-plugin fatal rollback did not retain the prior plugin bytes.' );
	ran_updater_readback_assert( is_plugin_active( $ran_readback_auto_plugin_identifier ), 'The active-plugin lifecycle proof did not retain activation state.' );

	$ran_readback_active_root = $ran_readback_theme_root . '/' . $ran_readback_active_theme;
	ran_updater_readback_assert( '2.0.0' === ran_updater_readback_version( $ran_readback_active_root . '/style.css', 'theme' ), 'The active theme update was not retained.' );
	ran_updater_readback_assert( $ran_readback_active_theme === get_option( 'stylesheet' ), 'The active theme selection changed during lifecycle proof.' );

	$ran_readback_inactive_root = $ran_readback_theme_root . '/' . $ran_readback_inactive_theme;
	ran_updater_readback_assert( '2.0.0' === ran_updater_readback_version( $ran_readback_inactive_root . '/style.css', 'theme' ), 'Core did not restore the inactive theme version after the injected failure.' );
	ran_updater_readback_assert( $ran_readback_inactive_theme . '-updated' === file_get_contents( $ran_readback_inactive_root . '/marker.txt' ), 'Core did not restore the inactive theme bytes after the injected failure.' );
	ran_updater_readback_assert( ! file_exists( $ran_readback_inactive_root . '/zz-copy-failure.php' ), 'The failed theme update left a newly copied file behind.' );
	ran_updater_readback_assert(
		is_string( $ran_readback_state['pre_failure_digest'] ?? null )
			&& $ran_readback_state['pre_failure_digest'] === ran_updater_readback_directory_digest( $ran_readback_inactive_root ),
		'Core did not restore the exact pre-update inactive-theme tree.'
	);
	ran_updater_readback_assert( ! file_exists( WP_CONTENT_DIR . '/upgrade-temp-backup/themes/' . $ran_readback_inactive_theme ), 'The failed theme update left its temporary backup behind.' );
	ran_updater_readback_assert( ! file_exists( WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . $ran_readback_auto_plugin_slug ), 'The automatic fatal rollback left its plugin backup behind.' );
	ran_updater_readback_assert( ! file_exists( ABSPATH . '.maintenance' ), 'The lifecycle proof left WordPress in maintenance mode.' );
} finally {
	$ran_readback_cleanup_failures = array();
	if ( $ran_readback_owns_targets
		&& '' !== $ran_readback_original_theme
		&& $ran_readback_original_theme !== get_option( 'stylesheet' )
		&& 1 === preg_match( '/^[a-z0-9._-]+$/D', $ran_readback_original_theme )
		&& wp_get_theme( $ran_readback_original_theme )->exists()
	) {
		switch_theme( $ran_readback_original_theme );
	}
	if ( $ran_readback_owns_targets && in_array( get_option( 'stylesheet' ), array( $ran_readback_active_theme, $ran_readback_inactive_theme ), true ) ) {
		$ran_readback_cleanup_failures[] = 'The original theme could not be restored before fixture cleanup.';
	}
	if ( $ran_readback_owns_targets && is_plugin_active( $ran_readback_auto_plugin_identifier ) ) {
		deactivate_plugins( $ran_readback_auto_plugin_identifier, true );
	}
	if ( $ran_readback_owns_targets && is_plugin_active( $ran_readback_auto_plugin_identifier ) ) {
		$ran_readback_cleanup_failures[] = 'The automatic lifecycle proof plugin could not be deactivated.';
	}
	if ( $ran_readback_owns_targets && WP_Filesystem() && $GLOBALS['wp_filesystem'] instanceof WP_Filesystem_Direct ) {
		foreach (
			array(
				$ran_readback_plugin_root,
				$ran_readback_auto_plugin_root,
				$ran_readback_theme_root . '/' . $ran_readback_active_theme,
				$ran_readback_theme_root . '/' . $ran_readback_inactive_theme,
			) as $ran_readback_target
		) {
			if ( $ran_readback_target === $ran_readback_theme_root . '/' . get_option( 'stylesheet' ) ) {
				continue;
			}
			if ( file_exists( $ran_readback_target ) && ! is_link( $ran_readback_target ) ) {
				$GLOBALS['wp_filesystem']->delete( $ran_readback_target, true );
			}
			if ( file_exists( $ran_readback_target ) || is_link( $ran_readback_target ) ) {
				$ran_readback_cleanup_failures[] = 'A lifecycle proof target could not be removed: ' . basename( $ran_readback_target );
			}
		}
	} elseif ( $ran_readback_owns_targets ) {
		$ran_readback_cleanup_failures[] = 'Direct filesystem access was unavailable for lifecycle proof cleanup.';
	}
	delete_option( 'ran_updater_lifecycle_proof_state' );
	delete_site_transient( 'update_plugins' );
	delete_site_transient( 'update_themes' );
	if ( array() !== $ran_readback_cleanup_failures ) {
		throw new RuntimeException( implode( ' ', $ran_readback_cleanup_failures ) );
	}
}

WP_CLI::success( 'Shutdown readback proved exact rollback, activation state, renamed mapping and cleanup.' );
