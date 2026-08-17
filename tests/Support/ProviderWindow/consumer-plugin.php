<?php
/**
 * Core-like consumer fixture for provider-window target registration.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

$factory = require dirname( __DIR__, 3 ) . '/bootstrap.php';
$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
$broker->registerCandidate(
	array(
		'broker_protocol' => 1,
		'package_version' => '9.0.0-dev',
		'php_floor'       => '8.2.0',
		'wordpress_floor' => '6.5',
		'path'            => dirname( __DIR__ ) . '/TargetCaptureRuntime',
		'runtime_file'    => dirname( __DIR__ ) . '/TargetCaptureRuntime/runtime.php',
	)
);

$GLOBALS['ran_wp_github_release_updater_provider_window_factory'] = $factory;

add_action(
	'plugins_loaded',
	static function (): void {
		do_action( 'ran_test_register_provider_release_targets' );
	},
	100,
	0
);
