<?php
/**
 * Prove target registration does not require plugin_basename() before the
 * WordPress plugin runtime is fully loaded.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

function did_action( string $hook ): int {
	if ( 'plugins_loaded' !== $hook ) {
		throw new RuntimeException( 'Unexpected action query.' );
	}

	return 0;
}

function add_action(
	string $hook,
	callable $callback,
	int $priority = 10,
	int $acceptedArgs = 1
): bool {
	unset( $hook, $callback, $priority, $acceptedArgs );
	return true;
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$factory = require dirname( __DIR__, 3 ) . '/bootstrap.php';
$facade  = $factory(
	'/wordpress/wp-content/plugins/renamed-package/renamed-package.php',
	'owner/renamed-package'
);
$facade->register();

$assert(
	function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' ),
	'The registration signal is unavailable.'
);
$assert(
	ran_wp_github_release_updater_v1_has_registered_target(
		'plugin',
		'renamed-package/renamed-package.php'
	),
	'The fallback did not retain the canonical plugin identity.'
);

printf( "Minimal bootstrap target registration passed.\n" );
