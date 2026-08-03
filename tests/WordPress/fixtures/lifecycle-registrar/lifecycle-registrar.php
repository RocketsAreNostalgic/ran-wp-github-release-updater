<?php
/**
 * Plugin Name: RAN Updater Lifecycle Registrar
 * Description: Registers active and inactive fixture themes before plugins_loaded.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Update URI: https://github.com/RocketsAreNostalgic/ran-updater-lifecycle-registrar
 */

declare(strict_types=1);

if ( ! defined( 'WPINC' ) ) {
	die;
}

$ran_updater_lifecycle_bootstrap = getenv( 'RAN_UPDATER_LIFECYCLE_BOOTSTRAP' );
if ( ! is_string( $ran_updater_lifecycle_bootstrap )
	|| '' === $ran_updater_lifecycle_bootstrap
	|| ! is_file( $ran_updater_lifecycle_bootstrap ) ) {
	throw new RuntimeException( 'The updater lifecycle bootstrap is unavailable.' );
}

$ran_updater_lifecycle_factory = require $ran_updater_lifecycle_bootstrap;
$ran_updater_lifecycle_plugin  = $ran_updater_lifecycle_factory(
	pluginFile: __FILE__,
	repository: 'RocketsAreNostalgic/ran-updater-lifecycle-registrar',
	providerRepositoryId: '100000001',
	pluginSlug: 'ran-updater-lifecycle-registrar',
	autoUpdatePolicy: 'manual',
);
$ran_updater_lifecycle_plugin->register();
$GLOBALS['ran_updater_lifecycle_plugin_facade'] = $ran_updater_lifecycle_plugin;

$ran_updater_lifecycle_themes = array(
	'ran-updater-registrar-active'   => array( 'RocketsAreNostalgic/ran-updater-registrar-active', '100000002' ),
	'ran-updater-registrar-inactive' => array( 'RocketsAreNostalgic/ran-updater-registrar-inactive', '100000003' ),
);

$GLOBALS['ran_updater_lifecycle_early_facades'] = array();
foreach ( $ran_updater_lifecycle_themes as $ran_updater_lifecycle_stylesheet => $ran_updater_lifecycle_repository ) {
	$ran_updater_lifecycle_facade = $ran_updater_lifecycle_factory(
		pluginFile: get_theme_root() . '/' . $ran_updater_lifecycle_stylesheet . '/style.css',
		repository: $ran_updater_lifecycle_repository[0],
		providerRepositoryId: $ran_updater_lifecycle_repository[1],
		pluginSlug: $ran_updater_lifecycle_stylesheet,
		autoUpdatePolicy: 'manual',
		targetType: 'theme',
		stylesheet: $ran_updater_lifecycle_stylesheet,
	);
	$ran_updater_lifecycle_facade->register();
	$GLOBALS['ran_updater_lifecycle_early_facades'][ $ran_updater_lifecycle_stylesheet ] = $ran_updater_lifecycle_facade;
}

unset(
	$ran_updater_lifecycle_bootstrap,
	$ran_updater_lifecycle_facade,
	$ran_updater_lifecycle_factory,
	$ran_updater_lifecycle_plugin,
	$ran_updater_lifecycle_repository,
	$ran_updater_lifecycle_stylesheet,
	$ran_updater_lifecycle_themes
);
