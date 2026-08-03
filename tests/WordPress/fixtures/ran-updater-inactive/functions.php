<?php
/**
 * Inactive theme non-execution fixture.
 */

declare(strict_types=1);

$GLOBALS['ran_updater_lifecycle_inactive_theme_loaded'] = true;
$ran_updater_lifecycle_bootstrap                        = getenv( 'RAN_UPDATER_LIFECYCLE_BOOTSTRAP' );
if ( ! is_string( $ran_updater_lifecycle_bootstrap )
	|| '' === $ran_updater_lifecycle_bootstrap
	|| ! is_file( $ran_updater_lifecycle_bootstrap ) ) {
	throw new RuntimeException( 'The updater lifecycle bootstrap is unavailable.' );
}

$ran_updater_lifecycle_factory = require $ran_updater_lifecycle_bootstrap;
$ran_updater_lifecycle_facade  = $ran_updater_lifecycle_factory(
	pluginFile: __DIR__ . '/style.css',
	repository: 'RocketsAreNostalgic/ran-updater-inactive',
	providerRepositoryId: '100000005',
	pluginSlug: 'ran-updater-inactive',
	autoUpdatePolicy: 'manual',
	targetType: 'theme',
	stylesheet: 'ran-updater-inactive',
);
$ran_updater_lifecycle_facade->register();
$GLOBALS['ran_updater_lifecycle_inactive_theme_facade'] = $ran_updater_lifecycle_facade;

unset(
	$ran_updater_lifecycle_bootstrap,
	$ran_updater_lifecycle_facade,
	$ran_updater_lifecycle_factory
);
