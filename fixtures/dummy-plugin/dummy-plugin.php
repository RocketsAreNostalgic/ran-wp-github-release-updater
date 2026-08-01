<?php
/**
 * Plugin Name: Booster Fixture Plugin
 * Description: Disposable public fixture for RAN Booster release integration tests.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Update URI: https://github.com/RocketsAreNostalgic/booster-fixture-plugin
 */

declare(strict_types=1);

$createUpdater = require dirname( __DIR__, 2 ) . '/bootstrap.php';

$updater = $createUpdater(
	pluginFile: __FILE__,
	repository: 'RocketsAreNostalgic/booster-fixture-plugin',
	pluginSlug: 'booster-fixture-plugin',
	channel: 'stable',
	accessToken: null,
	autoUpdatePolicy: 'site-controlled',
	cacheDuration: 21_600,
	failureCacheDuration: 900,
);

$updater->register();
