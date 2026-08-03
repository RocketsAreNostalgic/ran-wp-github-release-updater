<?php

// Executed by WP-CLI inside an isolated disposable WordPress installation.
// phpcs:disable

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ConditionalState;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\RateLimit;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseAsset;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseListResult;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseSummary;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\VerifiedArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressTemporaryFileFactory;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\NativePluginUpdater;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseArtifactClient;

$ran_proof_root = getenv( 'RAN_UPDATER_LIFECYCLE_ROOT' );
if ( ! is_string( $ran_proof_root ) || ! is_file( $ran_proof_root . '/runtime.php' ) ) {
	throw new RuntimeException( 'The updater lifecycle proof root is unavailable.' );
}

require_once $ran_proof_root . '/runtime.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-automatic-updater.php';

if ( ! defined( 'DOING_CRON' ) ) {
	define( 'DOING_CRON', true );
}
if ( is_multisite() || PHP_VERSION_ID < 80200 || ! class_exists( ZipArchive::class ) ) {
	throw new RuntimeException( 'The updater lifecycle proof requires single-site WordPress, PHP 8.2 and ZipArchive.' );
}
if ( ! WP_Filesystem() || ! $GLOBALS['wp_filesystem'] instanceof WP_Filesystem_Direct ) {
	throw new RuntimeException( 'The updater lifecycle proof requires WordPress direct filesystem access.' );
}

final class RanUpdaterLifecycleLocalClient implements ReleaseArtifactClient {
	private ArtifactDescriptor $descriptor;
	private string $archive;

	public function configure( ArtifactDescriptor $descriptor, string $archive ): void {
		$this->descriptor = $descriptor;
		$this->archive    = $archive;
	}

	public function listReleases( ReleaseQuery $query ) {
		return new ReleaseListResult(
			array(
				new ReleaseSummary(
					$this->descriptor->releaseId(),
					$this->descriptor->tag(),
					$this->descriptor->version(),
					false,
					'2026-08-03T12:00:00Z',
					array( $this->descriptor->zipAsset()->name() ),
					true
				),
			),
			new ConditionalState(),
			new RateLimit()
		);
	}

	public function describeExact( ExactReleaseRequest $request ) {
		return $request->releaseId() === $this->descriptor->releaseId()
			? $this->descriptor
			: new WP_Error( 'ran_updater_proof_release_mismatch' );
	}

	public function acquireDescribed( ArtifactDescriptor $descriptor ) {
		$temporary_files = new WordPressTemporaryFileFactory();
		$path            = $temporary_files->create( $descriptor->zipAsset()->name() );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( ! copy( $this->archive, $path ) ) {
			return new WP_Error( 'ran_updater_proof_archive_copy_failed' );
		}
		chmod( $path, 0600 );
		$identity = VerifiedArtifact::fileIdentity( $path );
		$sha256   = hash_file( 'sha256', $path );
		if ( null === $identity || ! is_string( $sha256 ) ) {
			return new WP_Error( 'ran_updater_proof_archive_identity_failed' );
		}

		return new VerifiedArtifact( $path, $sha256, $temporary_files, $identity );
	}
}

final class RanUpdaterLifecycleFailingFilesystem extends WP_Filesystem_Direct {
	public function __construct( private string $theme_slug ) {
		parent::__construct( null );
	}

	public function move( $source, $destination, $overwrite = false ) {
		$source      = str_replace( '\\', '/', (string) $source );
		$destination = str_replace( '\\', '/', (string) $destination );
		if ( str_contains( $source, '/upgrade/' )
			&& str_ends_with( $destination, '/themes/' . $this->theme_slug ) ) {
			return false;
		}

		return parent::move( $source, $destination, $overwrite );
	}

	public function copy( $source, $destination, $overwrite = false, $mode = false ) {
		$destination = str_replace( '\\', '/', (string) $destination );
		if ( str_ends_with( $destination, '/themes/' . $this->theme_slug . '/zz-copy-failure.php' ) ) {
			return false;
		}

		return parent::copy( $source, $destination, $overwrite, $mode );
	}
}

function ran_updater_proof_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ran_updater_proof_plugin_header( string $name, string $repository, string $version ): string {
	return "<?php\n/*\nPlugin Name: {$name}\nVersion: {$version}\nUpdate URI: https://github.com/{$repository}\nRequires at least: 6.5\nRequires PHP: 8.2\n*/\n";
}

function ran_updater_proof_theme_header( string $name, string $repository, string $version ): string {
	return "/*\nTheme Name: {$name}\nVersion: {$version}\nUpdate URI: https://github.com/{$repository}\nRequires at least: 6.5\nRequires PHP: 8.2\n*/\n";
}

/** @param array<string, string> $entries */
function ran_updater_proof_archive( string $name, array $entries ): string {
	$path = wp_tempnam( $name );
	ran_updater_proof_assert( is_string( $path ) && '' !== $path, 'A lifecycle proof archive could not be allocated.' );
	$zip = new ZipArchive();
	ran_updater_proof_assert( true === $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ), 'A lifecycle proof archive could not be opened.' );
	foreach ( $entries as $entry => $contents ) {
		ran_updater_proof_assert( $zip->addFromString( $entry, $contents ), 'A lifecycle proof archive entry could not be added.' );
	}
	$zip->close();
	chmod( $path, 0600 );

	return $path;
}

function ran_updater_proof_descriptor(
	string $repository_name,
	string $provider_id,
	string $version,
	int $release_id,
	string $archive
): ArtifactDescriptor {
	$repository = Repository::fromString( $repository_name, $provider_id );
	ran_updater_proof_assert( $repository instanceof Repository, 'The lifecycle proof repository is invalid.' );
	$sha256 = hash_file( 'sha256', $archive );
	$size   = filesize( $archive );
	ran_updater_proof_assert( is_string( $sha256 ) && is_int( $size ), 'The lifecycle proof archive identity is unavailable.' );
	$slug = substr( $repository_name, strpos( $repository_name, '/' ) + 1 );
	$tag  = 'v' . $version;

	return new ArtifactDescriptor(
		new ReleaseQuery( $repository, ReleaseQuery::STABLE, PHP_VERSION, get_bloginfo( 'version' ) ),
		$repository,
		$release_id,
		$tag,
		$version,
		str_pad( dechex( $release_id ), 40, '0', STR_PAD_LEFT ),
		false,
		'https://github.com/' . $repository_name . '/releases/tag/' . $tag,
		new ReleaseAsset( $release_id + 1000, $slug . '-' . $version . '.zip', $size, $sha256 ),
		true
	);
}

/** @return array<string, mixed> */
function ran_updater_proof_package_data( string $file, string $type ): array {
	if ( 'plugin' === $type ) {
		return get_plugin_data( $file, false, false );
	}

	return get_file_data(
		$file,
		array(
			'Name'        => 'Theme Name',
			'Version'     => 'Version',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
			'UpdateURI'   => 'Update URI',
		),
		'theme'
	);
}

function ran_updater_proof_offer(
	NativePluginUpdater $updater,
	string $type,
	string $file,
	string $identifier
): object {
	$offer = $updater->filterUpdate(
		false,
		ran_updater_proof_package_data( $file, $type ),
		$identifier,
		array( 'en_US' )
	);
	ran_updater_proof_assert( is_array( $offer ), 'The lifecycle proof did not receive a native update offer.' );
	$item              = (object) $offer;
	$item->new_version = $offer['version'];
	$transient         = new stdClass();
	$transient->response = array(
		$identifier => 'plugin' === $type ? $item : $offer,
	);
	$transient->no_update    = array();
	$transient->translations = array();
	$transient->checked      = array( $identifier => ran_updater_proof_package_data( $file, $type )['Version'] );
	$transient->last_checked = time();
	set_site_transient( 'plugin' === $type ? 'update_plugins' : 'update_themes', $transient );

	return $item;
}

function ran_updater_proof_read_version( string $file, string $type ): string {
	$data = ran_updater_proof_package_data( $file, $type );
	return is_string( $data['Version'] ?? null ) ? $data['Version'] : '';
}

function ran_updater_proof_directory_digest( string $directory ): string {
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
		ran_updater_proof_assert( is_string( $contents ), 'A lifecycle proof file could not be read for its digest.' );
		$entries[] = 'f:' . $relative . ':' . hash( 'sha256', $contents );
	}
	sort( $entries, SORT_STRING );

	return hash( 'sha256', implode( "\n", $entries ) );
}

function ran_updater_proof_scrape( bool $fatal, callable $operation ): mixed {
	$filter = static function ( mixed $preempt, array $arguments, string $url ) use ( $fatal ): mixed {
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) ) {
			return $preempt;
		}
		parse_str( $query, $parameters );
		$key = $parameters['wp_scrape_key'] ?? null;
		if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $key ) ) {
			return $preempt;
		}

		return array(
			'headers'  => array(),
			'body'     => '###### wp_scraping_result_start:' . $key . ' ######'
				. ( $fatal ? '{"type":1}' : '{}' )
				. '###### wp_scraping_result_end:' . $key . ' ######',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	$allow_disposable_checkout = '__return_false';
	add_filter( 'automatic_updates_is_vcs_checkout', $allow_disposable_checkout, 10, 2 );
	add_filter( 'pre_http_request', $filter, 10, 3 );
	try {
		return $operation();
	} finally {
		remove_filter( 'pre_http_request', $filter, 10 );
		remove_filter( 'automatic_updates_is_vcs_checkout', $allow_disposable_checkout, 10 );
	}
}

function ran_updater_proof_finish_automatic( WP_Automatic_Updater $automatic ): void {
	$property = new ReflectionProperty( WP_Automatic_Updater::class, 'update_results' );
	do_action( 'automatic_updates_complete', $property->getValue( $automatic ) );
}

$ran_proof_plugin_slug       = 'ran-updater-proof-plugin';
$ran_proof_plugin_directory  = 'ran-updater-proof-plugin-renamed';
$ran_proof_plugin_identifier = $ran_proof_plugin_directory . '/' . $ran_proof_plugin_slug . '.php';
$ran_proof_plugin_file       = WP_PLUGIN_DIR . '/' . $ran_proof_plugin_identifier;
$ran_proof_auto_plugin_slug  = 'ran-updater-proof-auto-plugin';
$ran_proof_auto_plugin_file  = WP_PLUGIN_DIR . '/' . $ran_proof_auto_plugin_slug . '/' . $ran_proof_auto_plugin_slug . '.php';
$ran_proof_auto_plugin_identifier = $ran_proof_auto_plugin_slug . '/' . $ran_proof_auto_plugin_slug . '.php';
$ran_proof_active_theme      = 'ran-updater-proof-theme-active';
$ran_proof_inactive_theme    = 'ran-updater-proof-theme-inactive';
$ran_proof_original_theme    = (string) get_option( 'stylesheet' );
$ran_proof_archives          = array();

add_action(
	'shutdown',
	static function () use ( &$ran_proof_archives ): void {
		foreach ( $ran_proof_archives as $archive ) {
			if ( is_string( $archive ) && is_file( $archive ) ) {
				unlink( $archive );
			}
		}
	},
	200
);

$ran_proof_targets = array(
	WP_PLUGIN_DIR . '/' . $ran_proof_plugin_directory,
	WP_PLUGIN_DIR . '/' . $ran_proof_auto_plugin_slug,
	get_theme_root() . '/' . $ran_proof_active_theme,
	get_theme_root() . '/' . $ran_proof_inactive_theme,
);
foreach ( $ran_proof_targets as $directory ) {
	ran_updater_proof_assert( ! file_exists( $directory ) && ! is_link( $directory ), 'A lifecycle proof target already exists.' );
}
foreach ( $ran_proof_targets as $directory ) {
	ran_updater_proof_assert( mkdir( $directory, 0700 ), 'A lifecycle proof target could not be created.' );
}

update_option(
	'ran_updater_lifecycle_proof_state',
	array(
		'original_theme'         => $ran_proof_original_theme,
		'plugin_identifier'      => $ran_proof_plugin_identifier,
		'plugin_directory'       => $ran_proof_plugin_directory,
		'auto_plugin_identifier' => $ran_proof_auto_plugin_identifier,
		'active_theme'           => $ran_proof_active_theme,
		'inactive_theme'         => $ran_proof_inactive_theme,
		'failure_reached'        => false,
		'copy_result_was_empty'  => false,
		'pre_failure_digest'     => null,
	),
	false
);

$ran_proof_plugin_repository = 'RocketsAreNostalgic/' . $ran_proof_plugin_slug;
file_put_contents(
	$ran_proof_plugin_file,
	ran_updater_proof_plugin_header( 'RAN Updater Proof Plugin', $ran_proof_plugin_repository, '1.0.0' )
);
file_put_contents( dirname( $ran_proof_plugin_file ) . '/marker.txt', 'plugin-initial' );

$ran_proof_auto_plugin_repository = 'RocketsAreNostalgic/' . $ran_proof_auto_plugin_slug;
file_put_contents(
	$ran_proof_auto_plugin_file,
	ran_updater_proof_plugin_header( 'RAN Updater Automatic Proof Plugin', $ran_proof_auto_plugin_repository, '2.0.0' )
);
file_put_contents( dirname( $ran_proof_auto_plugin_file ) . '/marker.txt', 'plugin-automatic-initial' );

foreach (
	array(
		$ran_proof_active_theme   => 'active',
		$ran_proof_inactive_theme => 'inactive',
	) as $theme_slug => $marker
) {
	$repository = 'RocketsAreNostalgic/' . $theme_slug;
	$root       = get_theme_root() . '/' . $theme_slug;
	file_put_contents( $root . '/style.css', ran_updater_proof_theme_header( 'RAN Updater Proof ' . ucfirst( $marker ) . ' Theme', $repository, '1.0.0' ) );
	file_put_contents( $root . '/index.php', "<?php\n" );
	file_put_contents( $root . '/marker.txt', $marker . '-initial' );
}

switch_theme( $ran_proof_active_theme );
ran_updater_proof_assert( get_option( 'stylesheet' ) === $ran_proof_active_theme, 'The lifecycle proof theme could not be activated.' );

$ran_proof_plugin_client = new RanUpdaterLifecycleLocalClient();
$ran_proof_plugin_updater = NativePluginUpdater::fromTarget(
	array(
		'pluginFile'           => $ran_proof_plugin_file,
		'repository'           => $ran_proof_plugin_repository,
		'providerRepositoryId' => '700000001',
		'pluginSlug'           => $ran_proof_plugin_slug,
		'channel'              => 'stable',
		'accessToken'          => null,
		'autoUpdatePolicy'     => 'manual',
		'cacheDuration'        => 300,
		'failureCacheDuration' => 60,
	),
	$ran_proof_plugin_client
);
ran_updater_proof_assert( $ran_proof_plugin_updater instanceof NativePluginUpdater, 'The lifecycle proof plugin updater is invalid.' );
$ran_proof_plugin_updater->register();

$ran_proof_plugin_archive = ran_updater_proof_archive(
	$ran_proof_plugin_slug . '-2.0.0.zip',
	array(
		$ran_proof_plugin_slug . '/' . $ran_proof_plugin_slug . '.php' => ran_updater_proof_plugin_header( 'RAN Updater Proof Plugin', $ran_proof_plugin_repository, '2.0.0' ),
		$ran_proof_plugin_slug . '/marker.txt' => 'plugin-manual-renamed',
	)
);
$ran_proof_archives[] = $ran_proof_plugin_archive;
$ran_proof_plugin_client->configure( ran_updater_proof_descriptor( $ran_proof_plugin_repository, '700000001', '2.0.0', 101, $ran_proof_plugin_archive ), $ran_proof_plugin_archive );
ran_updater_proof_offer( $ran_proof_plugin_updater, 'plugin', $ran_proof_plugin_file, $ran_proof_plugin_identifier );
$ran_proof_plugin_result = ( new Plugin_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $ran_proof_plugin_identifier );
ran_updater_proof_assert( true === $ran_proof_plugin_result, 'Plugin_Upgrader did not complete the renamed-directory update.' );
ran_updater_proof_assert( '2.0.0' === ran_updater_proof_read_version( $ran_proof_plugin_file, 'plugin' ), 'The renamed plugin version was not updated.' );
ran_updater_proof_assert( 'plugin-manual-renamed' === file_get_contents( dirname( $ran_proof_plugin_file ) . '/marker.txt' ), 'The renamed plugin source was not mapped authoritatively.' );
$ran_proof_plugin_updater->finalizePendingInstall();

$ran_proof_auto_plugin_client = new RanUpdaterLifecycleLocalClient();
$ran_proof_auto_plugin_updater = NativePluginUpdater::fromTarget(
	array(
		'pluginFile'           => $ran_proof_auto_plugin_file,
		'repository'           => $ran_proof_auto_plugin_repository,
		'providerRepositoryId' => '700000002',
		'pluginSlug'           => $ran_proof_auto_plugin_slug,
		'channel'              => 'stable',
		'accessToken'          => null,
		'autoUpdatePolicy'     => 'automatic',
		'cacheDuration'        => 300,
		'failureCacheDuration' => 60,
	),
	$ran_proof_auto_plugin_client
);
ran_updater_proof_assert( $ran_proof_auto_plugin_updater instanceof NativePluginUpdater, 'The lifecycle proof automatic plugin updater is invalid.' );
$ran_proof_auto_plugin_updater->register();

$ran_proof_activation = activate_plugin( $ran_proof_auto_plugin_identifier, '', false, true );
ran_updater_proof_assert( ! is_wp_error( $ran_proof_activation ) && is_plugin_active( $ran_proof_auto_plugin_identifier ), 'The lifecycle proof automatic plugin could not be activated.' );

$ran_proof_auto_plugin_archive = ran_updater_proof_archive(
	$ran_proof_auto_plugin_slug . '-3.0.0.zip',
	array(
		$ran_proof_auto_plugin_slug . '/' . $ran_proof_auto_plugin_slug . '.php' => ran_updater_proof_plugin_header( 'RAN Updater Automatic Proof Plugin', $ran_proof_auto_plugin_repository, '3.0.0' ),
		$ran_proof_auto_plugin_slug . '/marker.txt' => 'plugin-automatic-success',
	)
);
$ran_proof_archives[] = $ran_proof_auto_plugin_archive;
$ran_proof_auto_plugin_client->configure( ran_updater_proof_descriptor( $ran_proof_auto_plugin_repository, '700000002', '3.0.0', 102, $ran_proof_auto_plugin_archive ), $ran_proof_auto_plugin_archive );
$ran_proof_automatic_offer = ran_updater_proof_offer( $ran_proof_auto_plugin_updater, 'plugin', $ran_proof_auto_plugin_file, $ran_proof_auto_plugin_identifier );
$ran_proof_automatic = new WP_Automatic_Updater();
$ran_proof_automatic_result = ran_updater_proof_scrape(
	false,
	static fn () => $ran_proof_automatic->update( 'plugin', $ran_proof_automatic_offer )
);
ran_updater_proof_finish_automatic( $ran_proof_automatic );
ran_updater_proof_assert( true === $ran_proof_automatic_result, 'WP_Automatic_Updater did not complete the active-plugin update.' );
ran_updater_proof_assert( '3.0.0' === ran_updater_proof_read_version( $ran_proof_auto_plugin_file, 'plugin' ), 'The automatic plugin update did not install the expected version.' );
ran_updater_proof_assert( is_plugin_active( $ran_proof_auto_plugin_identifier ), 'The automatic plugin update changed activation state.' );
$ran_proof_auto_plugin_updater->finalizePendingInstall();

$ran_proof_auto_plugin_updater->refreshCache();
$ran_proof_auto_plugin_archive = ran_updater_proof_archive(
	$ran_proof_auto_plugin_slug . '-4.0.0.zip',
	array(
		$ran_proof_auto_plugin_slug . '/' . $ran_proof_auto_plugin_slug . '.php' => ran_updater_proof_plugin_header( 'RAN Updater Automatic Proof Plugin', $ran_proof_auto_plugin_repository, '4.0.0' ),
		$ran_proof_auto_plugin_slug . '/marker.txt' => 'plugin-automatic-fatal',
	)
);
$ran_proof_archives[] = $ran_proof_auto_plugin_archive;
$ran_proof_auto_plugin_client->configure( ran_updater_proof_descriptor( $ran_proof_auto_plugin_repository, '700000002', '4.0.0', 103, $ran_proof_auto_plugin_archive ), $ran_proof_auto_plugin_archive );
$ran_proof_fatal_offer = ran_updater_proof_offer( $ran_proof_auto_plugin_updater, 'plugin', $ran_proof_auto_plugin_file, $ran_proof_auto_plugin_identifier );
$ran_proof_fatal_automatic = new WP_Automatic_Updater();
$ran_proof_fatal_completion_version = null;
$ran_proof_capture_fatal_completion = static function ( object $upgrader, array $extra ) use ( &$ran_proof_fatal_completion_version, $ran_proof_auto_plugin_file, $ran_proof_auto_plugin_identifier ): void {
	unset( $upgrader );
	if ( $ran_proof_auto_plugin_identifier === ( $extra['plugin'] ?? null ) ) {
		$ran_proof_fatal_completion_version = ran_updater_proof_read_version( $ran_proof_auto_plugin_file, 'plugin' );
	}
};
add_action( 'upgrader_process_complete', $ran_proof_capture_fatal_completion, 1, 2 );
try {
	$ran_proof_fatal_result = ran_updater_proof_scrape(
		true,
		static fn () => $ran_proof_fatal_automatic->update( 'plugin', $ran_proof_fatal_offer )
	);
} finally {
	remove_action( 'upgrader_process_complete', $ran_proof_capture_fatal_completion, 1 );
}
ran_updater_proof_finish_automatic( $ran_proof_fatal_automatic );
ran_updater_proof_assert( is_wp_error( $ran_proof_fatal_result ) && 'plugin_update_fatal_error_rollback_successful' === $ran_proof_fatal_result->get_error_code(), 'Core did not report the active-plugin fatal rollback.' );
ran_updater_proof_assert( '4.0.0' === $ran_proof_fatal_completion_version, 'The proof no longer characterizes upgrader_process_complete occurring before Core automatic fatal rollback.' );
ran_updater_proof_assert( '3.0.0' === ran_updater_proof_read_version( $ran_proof_auto_plugin_file, 'plugin' ), 'Core did not restore the plugin version after the automatic fatal check.' );
ran_updater_proof_assert( 'plugin-automatic-success' === file_get_contents( dirname( $ran_proof_auto_plugin_file ) . '/marker.txt' ), 'Core did not restore the plugin bytes after the automatic fatal check.' );
$ran_proof_auto_plugin_updater->finalizePendingInstall();
$ran_proof_fatal_diagnostics = $ran_proof_auto_plugin_updater->diagnostics();
ran_updater_proof_assert( '4.0.0' === ( $ran_proof_fatal_diagnostics['offered_version'] ?? null ), 'The updater discarded the offer before automatic rollback completed.' );
ran_updater_proof_assert( 'update_completed' !== ( $ran_proof_fatal_diagnostics['code'] ?? null ), 'The updater reported success after automatic rollback restored the prior plugin.' );

$ran_proof_theme_updaters = array();
$ran_proof_theme_clients  = array();
foreach ( array( $ran_proof_active_theme, $ran_proof_inactive_theme ) as $index => $theme_slug ) {
	$repository = 'RocketsAreNostalgic/' . $theme_slug;
	$client     = new RanUpdaterLifecycleLocalClient();
	$updater    = NativePluginUpdater::fromTarget(
		array(
			'targetType'           => 'theme',
			'pluginFile'           => get_theme_root() . '/' . $theme_slug . '/style.css',
			'stylesheet'           => $theme_slug,
			'repository'           => $repository,
			'providerRepositoryId' => (string) ( 700000010 + $index ),
			'pluginSlug'           => $theme_slug,
			'channel'              => 'stable',
			'accessToken'          => null,
			'autoUpdatePolicy'     => 'manual',
			'cacheDuration'        => 300,
			'failureCacheDuration' => 60,
		),
		$client
	);
	ran_updater_proof_assert( $updater instanceof NativePluginUpdater, 'A lifecycle proof theme updater is invalid.' );
	$updater->register();
	$archive = ran_updater_proof_archive(
		$theme_slug . '-2.0.0.zip',
		array(
			$theme_slug . '/style.css' => ran_updater_proof_theme_header( 'RAN Updater Proof Theme', $repository, '2.0.0' ),
			$theme_slug . '/index.php' => "<?php\n",
			$theme_slug . '/marker.txt' => $theme_slug . '-updated',
		)
	);
	$ran_proof_archives[] = $archive;
	$client->configure( ran_updater_proof_descriptor( $repository, (string) ( 700000010 + $index ), '2.0.0', 201 + $index, $archive ), $archive );
	ran_updater_proof_offer( $updater, 'theme', get_theme_root() . '/' . $theme_slug . '/style.css', $theme_slug );
	$result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $theme_slug );
	ran_updater_proof_assert( true === $result, 'Theme_Upgrader did not complete a lifecycle proof update.' );
	ran_updater_proof_assert( '2.0.0' === ran_updater_proof_read_version( get_theme_root() . '/' . $theme_slug . '/style.css', 'theme' ), 'A lifecycle proof theme version was not updated.' );
	$updater->finalizePendingInstall();
	$ran_proof_theme_updaters[ $theme_slug ] = $updater;
	$ran_proof_theme_clients[ $theme_slug ]  = $client;
}
ran_updater_proof_assert( get_option( 'stylesheet' ) === $ran_proof_active_theme, 'Theme_Upgrader changed the active theme selection.' );

$ran_proof_failure_updater = $ran_proof_theme_updaters[ $ran_proof_inactive_theme ];
$ran_proof_failure_client  = $ran_proof_theme_clients[ $ran_proof_inactive_theme ];
$ran_proof_failure_updater->refreshCache();
$ran_proof_failure_repository = 'RocketsAreNostalgic/' . $ran_proof_inactive_theme;
$ran_proof_failure_archive = ran_updater_proof_archive(
	$ran_proof_inactive_theme . '-3.0.0.zip',
	array(
		$ran_proof_inactive_theme . '/style.css' => ran_updater_proof_theme_header( 'RAN Updater Proof Theme', $ran_proof_failure_repository, '3.0.0' ),
		$ran_proof_inactive_theme . '/marker.txt' => 'theme-partial-copy',
		$ran_proof_inactive_theme . '/zz-copy-failure.php' => "<?php\n",
	)
);
$ran_proof_archives[] = $ran_proof_failure_archive;
$ran_proof_failure_client->configure( ran_updater_proof_descriptor( $ran_proof_failure_repository, '700000011', '3.0.0', 203, $ran_proof_failure_archive ), $ran_proof_failure_archive );
ran_updater_proof_offer( $ran_proof_failure_updater, 'theme', get_theme_root() . '/' . $ran_proof_inactive_theme . '/style.css', $ran_proof_inactive_theme );
$ran_proof_state                       = get_option( 'ran_updater_lifecycle_proof_state' );
$ran_proof_state['pre_failure_digest'] = ran_updater_proof_directory_digest( get_theme_root() . '/' . $ran_proof_inactive_theme );
update_option( 'ran_updater_lifecycle_proof_state', $ran_proof_state, false );

$ran_proof_core_result = null;
$ran_proof_completion = static function ( object $upgrader, array $extra ) use ( &$ran_proof_core_result, $ran_proof_inactive_theme ): void {
	if ( $ran_proof_inactive_theme === ( $extra['theme'] ?? null ) ) {
		$ran_proof_core_result = $upgrader->result;
	}
};
$ran_proof_filesystem = null;
$ran_proof_arm_failure = static function ( mixed $response, array $extra ) use ( &$ran_proof_filesystem, $ran_proof_inactive_theme ): mixed {
	if ( $ran_proof_inactive_theme === ( $extra['theme'] ?? null ) ) {
		$ran_proof_filesystem       = $GLOBALS['wp_filesystem'];
		$GLOBALS['wp_filesystem'] = new RanUpdaterLifecycleFailingFilesystem( $ran_proof_inactive_theme );
	}
	return $response;
};
add_action( 'upgrader_process_complete', $ran_proof_completion, 1, 2 );
add_filter( 'upgrader_pre_install', $ran_proof_arm_failure, PHP_INT_MAX - 1, 2 );
try {
	$ran_proof_failure_result = ( new Theme_Upgrader( new Automatic_Upgrader_Skin() ) )->upgrade( $ran_proof_inactive_theme );
} finally {
	remove_action( 'upgrader_process_complete', $ran_proof_completion, 1 );
	remove_filter( 'upgrader_pre_install', $ran_proof_arm_failure, PHP_INT_MAX - 1 );
	if ( is_object( $ran_proof_filesystem ) ) {
		$GLOBALS['wp_filesystem'] = $ran_proof_filesystem;
	}
}

ran_updater_proof_assert( is_wp_error( $ran_proof_failure_result ) && 'copy_failed_copy_dir' === $ran_proof_failure_result->get_error_code(), 'Core did not return the injected destination-copy failure.' );
ran_updater_proof_assert( array() === $ran_proof_core_result, 'The proof no longer characterizes Core leaving WP_Upgrader::$result empty on an early copy failure.' );
ran_updater_proof_assert( '3.0.0' === ran_updater_proof_read_version( get_theme_root() . '/' . $ran_proof_inactive_theme . '/style.css', 'theme' ), 'The injected copy failure did not expose the partially copied new header.' );
$ran_proof_failure_updater->finalizePendingInstall();
$ran_proof_failure_diagnostics = $ran_proof_failure_updater->diagnostics();
ran_updater_proof_assert( '3.0.0' === ( $ran_proof_failure_diagnostics['offered_version'] ?? null ), 'The updater discarded the offer before Core restored the failed theme update.' );
ran_updater_proof_assert( 'update_completed' !== ( $ran_proof_failure_diagnostics['code'] ?? null ), 'The updater reported success for an early Core copy failure.' );

$ran_proof_state                          = get_option( 'ran_updater_lifecycle_proof_state' );
$ran_proof_state['failure_reached']       = true;
$ran_proof_state['copy_result_was_empty'] = array() === $ran_proof_core_result;
update_option( 'ran_updater_lifecycle_proof_state', $ran_proof_state, false );

WP_CLI::success( 'Real plugin, theme, automatic-update and rollback lifecycle operations reached the shutdown restoration boundary.' );
