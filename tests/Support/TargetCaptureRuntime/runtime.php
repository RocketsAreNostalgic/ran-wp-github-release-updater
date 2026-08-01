<?php

declare(strict_types=1);

return static function ( array $targets ): void {
	$GLOBALS['ran_wp_github_release_updater_broker_runtime_targets'] = $targets;
};
