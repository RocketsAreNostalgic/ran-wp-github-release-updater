<?php
/**
 * External provider fixture for provider-window target registration.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

add_action(
	'ran_test_register_provider_release_targets',
	static function (): void {
		$factory = $GLOBALS['ran_wp_github_release_updater_provider_window_factory'];
		$facade  = $factory(
			pluginFile: '/plugins/provider-package/provider-package.php',
			repository: 'owner/provider-package',
			providerRepositoryId: '123456789'
		);
		$facade->register();

		$GLOBALS['ran_wp_github_release_updater_provider_window_facade']            = $facade;
		$GLOBALS['ran_wp_github_release_updater_provider_window_registration_code'] =
			$facade->diagnostics()['code'];
	},
	10,
	0
);
