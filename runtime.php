<?php
/**
 * Selected native WordPress update runtime.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

$ran_wp_github_release_updater_sources = array(
	'/src/Http/Transport.php',
	'/src/Http/TemporaryFileFactory.php',
	'/src/Http/Request.php',
	'/src/Http/Response.php',
	'/src/Http/WordPressSafeHttpTransport.php',
	'/src/Http/WordPressTemporaryFileFactory.php',
	'/src/Artifact/Repository.php',
	'/src/Artifact/AccessToken.php',
	'/src/Artifact/ConditionalState.php',
	'/src/Artifact/RateLimit.php',
	'/src/Artifact/ReleaseAsset.php',
	'/src/Artifact/ReleaseSummary.php',
	'/src/Artifact/ReleaseListResult.php',
	'/src/Artifact/ReleaseVersion.php',
	'/src/Artifact/ReleaseQuery.php',
	'/src/Artifact/ExactReleaseRequest.php',
	'/src/Artifact/ArtifactDescriptor.php',
	'/src/Artifact/ClaimedArtifact.php',
	'/src/Artifact/VerifiedArtifact.php',
	'/src/Artifact/GitHubReleaseArtifactService.php',
	'/src/WordPress/ReleaseArtifactClient.php',
	'/src/WordPress/GitHubReleaseArtifactClient.php',
	'/src/WordPress/PackageIdentityTarget.php',
	'/src/WordPress/CandidateValidation.php',
	'/src/WordPress/ReleaseAssurance.php',
	'/src/WordPress/ReleasePackageIdentityValidator.php',
	'/src/WordPress/ReleaseOperationClaim.php',
	'/src/WordPress/ReleaseOperationCoordinator.php',
	'/src/WordPress/ReleaseCandidateSelector.php',
	'/src/WordPress/ReleaseDiscovery.php',
	'/src/WordPress/ProspectiveReleaseCandidate.php',
	'/src/WordPress/ReleaseFingerprint.php',
	'/src/WordPress/ReleaseInspection.php',
	'/src/WordPress/ValidatedReleaseArtifact.php',
	'/src/WordPress/ReleaseCandidatePreflight.php',
	'/src/WordPress/NativePluginUpdater.php',
);

foreach ( $ran_wp_github_release_updater_sources as $ran_wp_github_release_updater_source ) {
	require_once __DIR__ . $ran_wp_github_release_updater_source;
}

unset(
	$ran_wp_github_release_updater_source,
	$ran_wp_github_release_updater_sources
);

use RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressSafeHttpTransport;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\GitHubReleaseArtifactClient;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\NativePluginUpdater;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseAssurance;

return static function ( array $targets ): void {
	$broker = $GLOBALS['ran_wp_github_release_updater_v1_broker'] ?? null;
	if (
		! is_object( $broker )
		|| ! is_callable( array( $broker, 'attachDiagnosticsProvider' ) )
	) {
		return;
	}

	$release_assurance      = null;
	$artifact_client        = null;
	$targets_by_identity    = array();
	$plugin_targets_by_slug = array();

	foreach ( $targets as $target ) {
		if ( ! is_array( $target ) ) {
			continue;
		}
		$registration_id = $target['registrationId'] ?? null;
		$target_type     = $target['targetType'] ?? 'plugin';
		$plugin_file     = $target['pluginFile'] ?? null;
		if ( ! is_string( $registration_id ) || '' === $registration_id ) {
			continue;
		}
		if ( false === ( $target['nativeDiscovery'] ?? true ) ) {
			$broker->attachDiagnosticsProvider(
				$registration_id,
				static fn (): array => array(
					'state' => 'inactive',
					'code'  => 'native_discovery_disabled',
				)
			);
			continue;
		}
		if ( ! in_array( $target_type, array( 'plugin', 'theme' ), true )
			|| ! is_string( $plugin_file )
			|| '' === $plugin_file
		) {
			$targets_by_identity[ 'invalid:' . $registration_id ][] = $target;
			continue;
		}

		$identity = 'plugin' === $target_type
			? strtolower( str_replace( '\\', '/', plugin_basename( $plugin_file ) ) )
			: strtolower(
				is_string( $target['stylesheet'] ?? null )
					? $target['stylesheet']
					: basename( dirname( $plugin_file ) )
			);
		$targets_by_identity[ $target_type . ':' . $identity ][] = $target;
		if ( 'plugin' === $target_type
			&& is_string( $target['pluginSlug'] ?? null )
			&& 1 === preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $target['pluginSlug'] )
		) {
			$plugin_targets_by_slug[ $target['pluginSlug'] ][] = $target;
		}
	}
	$conflicting_plugin_slugs = array();
	foreach ( $plugin_targets_by_slug as $group ) {
		if ( count( $group ) > 1 ) {
			foreach ( $group as $target ) {
				$conflicting_plugin_slugs[ $target['registrationId'] ] = true;
			}
		}
	}

	foreach ( $targets_by_identity as $group ) {
		if ( count( $group ) > 1 ) {
			foreach ( $group as $target ) {
				$conflict_code = 'theme' === ( $target['targetType'] ?? 'plugin' )
					? 'conflicting_theme_target'
					: 'conflicting_plugin_target';
				$broker->attachDiagnosticsProvider(
					$target['registrationId'],
					static fn (): array => array(
						'state' => 'inactive',
						'code'  => $conflict_code,
					)
				);
			}
			continue;
		}

		$target          = $group[0];
		$registration_id = $target['registrationId'];
		if ( true === ( $conflicting_plugin_slugs[ $registration_id ] ?? false ) ) {
			$broker->attachDiagnosticsProvider(
				$registration_id,
				static fn (): array => array(
					'state' => 'inactive',
					'code'  => 'conflicting_plugin_slug',
				)
			);
			continue;
		}
		try {
			$release_assurance ??= ReleaseAssurance::selectForRequest();

			$artifact_client ??= new GitHubReleaseArtifactClient(
				new GitHubReleaseArtifactService( new WordPressSafeHttpTransport() )
			);

			$updater = NativePluginUpdater::fromTarget(
				$target,
				$artifact_client,
				null,
				$release_assurance
			);
			if ( $updater instanceof \WP_Error ) {
				$error_code = $updater->get_error_code();
				$code       = sanitize_key( is_string( $error_code ) ? $error_code : '' );
				$code       = '' === $code
					? 'invalid_target_configuration'
					: substr( $code, 0, 80 );
				NativePluginUpdater::registerConfigurationNotice( $target, $code );
				$broker->attachDiagnosticsProvider(
					$registration_id,
					static fn (): array => array(
						'state' => 'inactive',
						'code'  => $code,
					)
				);
				continue;
			}

			$updater->register();
			$broker->attachDiagnosticsProvider(
				$registration_id,
				static fn (): array => $updater->diagnostics()
			);
			$control_cell   = $target['controlCell'] ?? null;
			$attach_refresh = is_object( $control_cell )
				? array( $control_cell, 'attach' )
				: null;
			if ( is_callable( $attach_refresh ) ) {
				$attach_refresh(
					static fn (): bool => $updater->refreshCache()
				);
			}
		} catch ( Throwable ) {
			$broker->attachDiagnosticsProvider(
				$registration_id,
				static fn (): array => array(
					'state' => 'inactive',
					'code'  => 'runtime_target_failed',
				)
			);
		}
	}
};
