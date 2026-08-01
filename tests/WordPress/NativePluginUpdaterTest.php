<?php

declare(strict_types=1);

namespace Tests\WordPress;

use PHPUnit\Framework\TestCase;
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
use RAN\WPGitHubReleaseUpdater\V1\WordPress\CandidateValidation;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\NativePluginUpdater;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseArtifactClient;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseAssurance;
use Tests\Support\WordPressState;

spl_autoload_register(
	static function ( string $className ): void {
		$prefix = 'RAN\\WPGitHubReleaseUpdater\\V1\\';
		if ( ! str_starts_with( $className, $prefix ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/src/'
			. str_replace( '\\', '/', substr( $className, strlen( $prefix ) ) )
			. '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);

/**
 * Native WordPress adapter contract tests.
 */
final class NativePluginUpdaterTest extends TestCase {

	private const PLUGIN_BASENAME = 'example-plugin/example-plugin.php';

	private string $pluginFile;
	private int $now = 1000;

	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
		$GLOBALS['wp_version'] = '6.5';
		$this->now             = 1000;
		$this->pluginFile      = dirname( __DIR__, 2 )
			. '/fixtures/dummy-plugin/dummy-plugin.php';
		WordPressState::$pluginBasenames[ $this->pluginFile ] = self::PLUGIN_BASENAME;
		WordPressState::$pluginData[ $this->pluginFile ]      = array(
			'Name'        => 'Example Plugin',
			'Version'     => '1.0.0',
			'Description' => 'Example description.',
			'Author'      => 'RAN',
			'PluginURI'   => 'https://example.test/plugin',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.0',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/example-plugin',
		);
	}

	public function testRegistersOnlyTheBetaNativeHooksIdempotently(): void {
		$updater = $this->updater( new FakeReleaseArtifactClient( $this->descriptor() ) );

		$updater->register();
		$updater->register();

		self::assertSame( 1, WordPressState::hookCount( 'update_plugins_github.com' ) );
		self::assertSame( 1, WordPressState::hookCount( 'plugins_api' ) );
		self::assertSame( 1, WordPressState::hookCount( 'auto_update_plugin' ) );
		self::assertSame( 1, WordPressState::hookCount( 'upgrader_pre_download' ) );
		self::assertSame( 1, WordPressState::hookCount( 'upgrader_source_selection' ) );
		self::assertSame( 1, WordPressState::hookCount( 'upgrader_process_complete' ) );
		self::assertSame( 1, WordPressState::hookCount( 'admin_notices' ) );
		self::assertSame( 1, WordPressState::hookCount( 'network_admin_notices' ) );
		self::assertArrayHasKey(
			PHP_INT_MAX,
			WordPressState::$filters['upgrader_pre_download']
		);
		self::assertArrayHasKey(
			PHP_INT_MAX,
			WordPressState::$filters['upgrader_source_selection']
		);
		self::assertSame(
			8,
			array_sum(
				array_map(
					array( WordPressState::class, 'hookCount' ),
					array(
						'update_plugins_github.com',
						'plugins_api',
						'auto_update_plugin',
						'upgrader_pre_download',
						'upgrader_source_selection',
						'upgrader_process_complete',
						'admin_notices',
						'network_admin_notices',
					)
				)
			)
		);
	}

	public function testThemeUsesNativeResponseShapeAndAllThreePolicies(): void {
		$target = $this->themeTarget();
		WordPressState::$pluginData[ $target['pluginFile'] ] = array(
			'Name'        => 'Example Theme',
			'Version'     => '1.0.0',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.2',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/example-theme',
		);
		$client  = new FakeReleaseArtifactClient( $this->themeDescriptor() );
		$updater = NativePluginUpdater::fromTarget(
			$target,
			$client,
			fn (): int => $this->now
		);
		self::assertInstanceOf( NativePluginUpdater::class, $updater );
		$updater->register();

		$update = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			'locally-renamed-theme',
			array()
		);
		self::assertIsArray( $update );
		self::assertSame( 'locally-renamed-theme', $update['theme'] );
		self::assertSame( '1.2.3', $update['version'] );
		self::assertArrayNotHasKey( 'plugin', $update );
		self::assertArrayNotHasKey( 'new_version', $update );
		self::assertSame( 1, WordPressState::hookCount( 'update_themes_github.com' ) );
		self::assertSame( 1, WordPressState::hookCount( 'auto_update_theme' ) );
		self::assertSame( 0, WordPressState::hookCount( 'plugins_api' ) );
		self::assertNull(
			$updater->filterAutoUpdate(
				null,
				(object) array( 'theme' => 'locally-renamed-theme' )
			)
		);

		$target['autoUpdatePolicy'] = 'automatic';
		$automatic                  = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( NativePluginUpdater::class, $automatic );
		self::assertTrue(
			$automatic->filterAutoUpdate(
				false,
				(object) array( 'theme' => 'locally-renamed-theme' )
			)
		);

		$target['autoUpdatePolicy'] = 'disabled';
		$disabled                   = NativePluginUpdater::fromTarget(
			$target,
			new FakeReleaseArtifactClient( $this->themeDescriptor() )
		);
		self::assertInstanceOf( NativePluginUpdater::class, $disabled );
		self::assertFalse(
			$disabled->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				'locally-renamed-theme',
				array()
			)
		);
		self::assertSame( 'release_available_disabled', $disabled->diagnostics()['code'] );
	}

	public function testThemeIdentityUsesPublicTargetAndCanonicalSlugAcrossNativeLifecycle(): void {
		$target = $this->themeTarget();
		WordPressState::$pluginData[ $target['pluginFile'] ] = array(
			'Name'        => 'Example Theme',
			'Version'     => '1.0.0',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.2',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/example-theme',
		);
		$client                 = new FakeReleaseArtifactClient( $this->themeDescriptor() );
		$client->archiveEntries = array(
			'example-theme/style.css' => "/*\nTheme Name: Example Theme\nVersion: 1.2.3\nUpdate URI: https://github.com/RocketsAreNostalgic/example-theme\nRequires PHP: 8.2\nRequires at least: 6.5\n*/",
		);
		$updater                = NativePluginUpdater::fromTarget( $target, $client, fn (): int => $this->now );
		self::assertInstanceOf( NativePluginUpdater::class, $updater );

		$first = $updater->filterUpdate( false, array( 'Version' => '1.0.0' ), 'locally-renamed-theme', array() );
		self::assertIsArray( $first );
		$second = $updater->filterUpdate( false, array( 'Version' => '1.0.0' ), 'locally-renamed-theme', array() );
		self::assertIsArray( $second );
		self::assertSame( 1, $client->listCalls );
		self::assertSame( 1, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );

		$path = $updater->filterPreDownload( false, $first['package'], null, array( 'theme' => 'locally-renamed-theme' ) );
		self::assertIsString( $path );
		self::assertNull( $updater->filterPreUnzipFile( null, $path, '/stage', array(), 1024.0 ) );
		$GLOBALS['wp_filesystem']                                     = new FakeWordPressFilesystem( '/stage/', 'example-theme', 'style.css' );
		WordPressState::$pluginData['/stage/example-theme/style.css'] = array(
			'Name'        => 'Example Theme',
			'Version'     => '1.2.3',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.2',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/example-theme',
		);
		self::assertSame(
			'/stage/locally-renamed-theme/',
			$updater->filterSourceSelection( '/stage/example-theme/', '/stage/', null, array( 'theme' => 'locally-renamed-theme' ) )
		);

		WordPressState::$pluginData[ $target['pluginFile'] ]['Version'] = '1.2.3';
		$updater->observeCompletion(
			(object) array( 'result' => true ),
			array(
				'action' => 'update',
				'type'   => 'theme',
				'theme'  => 'locally-renamed-theme',
			)
		);
		self::assertSame( 'update_completed', $updater->diagnostics()['code'] );
		self::assertNull( $updater->diagnostics()['offered_version'] );
		self::assertArrayNotHasKey(
			'offer',
			array_values( WordPressState::$siteTransients )[0]
		);
		wp_delete_file( $path );
	}

	public function testPublishesANewerExactReleaseAndCachesNormalizedState(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );

		$update = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array( 'en_GB' )
		);

		self::assertIsArray( $update );
		self::assertSame( '1.2.3', $update['version'] );
		self::assertSame( 'example-plugin', $update['slug'] );
		self::assertSame( self::PLUGIN_BASENAME, $update['plugin'] );
		self::assertSame( '6.5', $update['requires'] );
		self::assertSame( '8.0', $update['requires_php'] );
		self::assertArrayNotHasKey( 'tested', $update );
		self::assertSame(
			'https://api.github.com/repos/RocketsAreNostalgic/example-plugin/releases/assets/101',
			$update['package']
		);
		self::assertSame( 1, $client->listCalls );
		self::assertSame( 1, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );

		$cached = array_values( WordPressState::$siteTransients )[0] ?? null;
		self::assertIsArray( $cached );
		self::assertArrayNotHasKey( 'descriptor', $cached );
		self::assertArrayNotHasKey( 'path', $cached );
		self::assertSame( str_repeat( 'a', 64 ), $cached['offer']['sha256'] );

		$updater->filterUpdate( false, array( 'Version' => '1.0.0' ), self::PLUGIN_BASENAME, array() );
		self::assertSame( 1, $client->listCalls );
		self::assertSame( 1, $client->describeCalls );
	}

	public function testCurrentReleaseIsCachedWithoutRemainingAnOffer(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$state = array_values( WordPressState::$siteTransients )[0] ?? null;
		self::assertIsArray( $state );
		self::assertSame( 'current', $state['status'] );
		self::assertArrayNotHasKey( 'offer', $state );
		self::assertSame( '1.2.3', $state['current']['version'] );
		self::assertSame( 'up_to_date', $updater->diagnostics()['code'] );
		self::assertNull( $updater->diagnostics()['offered_version'] );
		self::assertSame( 0, $client->acquireCalls );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 1, $client->listCalls );
		self::assertSame( 1, $client->describeCalls );
	}

	public function testCurrentCacheIsNotReusedAfterInstalledPluginVersionMovesBackward(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 0, $client->acquireCalls );

		$update = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $update );
		self::assertSame( '1.2.3', $update['version'] );
		self::assertSame( 2, $client->listCalls );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );
		self::assertSame( '1.2.3', $updater->diagnostics()['offered_version'] );
	}

	public function testValidatedCurrentCacheBecomesAnOfferAfterInstalledPluginVersionMovesBackward(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$update = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $update );
		self::assertSame( '1.2.3', $update['version'] );
		self::assertSame( 1, $client->listCalls );
		self::assertSame( 1, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );
	}

	public function testCurrentDescriptorSurvivesRepeatedNotModifiedRevalidation(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		foreach ( array( 22_601, 44_202 ) as $now ) {
			$this->now              = $now;
			$client->nextListResult = new ReleaseListResult(
				array(),
				new ConditionalState(),
				new RateLimit(),
				true
			);
			self::assertFalse(
				$updater->filterUpdate(
					false,
					array( 'Version' => '1.2.3' ),
					self::PLUGIN_BASENAME,
					array()
				)
			);
		}

		self::assertSame( 3, $client->listCalls );
		self::assertSame( 3, $client->describeCalls );
		self::assertSame( 0, $client->acquireCalls );
		$state = array_values( WordPressState::$siteTransients )[0];
		self::assertSame( 'current', $state['status'] );
		self::assertSame( 44_202, $state['checked_at'] );
		self::assertSame( '1.2.3', $state['current']['version'] );
	}

	public function testNotModifiedCurrentDescriptorBecomesOfferAfterLocalDowngrade(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$this->now              = 22_601;
		$client->nextListResult = new ReleaseListResult(
			array(),
			new ConditionalState(),
			new RateLimit(),
			true
		);
		$update                 = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $update );
		self::assertSame( '1.2.3', $update['version'] );
		self::assertSame( 2, $client->listCalls );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );
	}

	public function testUnusableNotModifiedResponseDiscardsValidators(): void {
		$client                 = new FakeReleaseArtifactClient( $this->descriptor() );
		$client->nextListResult = new ReleaseListResult(
			array(),
			new ConditionalState( '"orphaned"', 'Thu, 24 Jul 2026 12:00:00 GMT' ),
			new RateLimit(),
			true
		);
		$updater                = $this->updater( $client );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		$state = array_values( WordPressState::$siteTransients )[0];
		self::assertSame(
			array(
				'etag'          => null,
				'last_modified' => null,
			),
			$state['conditional']
		);

		$this->now = 1901;
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( array(), $client->lastListQuery->conditional()->requestHeaders() );
	}

	public function testPrereleaseChannelOffersBetaProgressionAndStablePromotion(): void {
		$betaClient  = new FakeReleaseArtifactClient(
			$this->descriptor( true, '1.2.3-beta.2' )
		);
		$betaUpdater = $this->updater( $betaClient, 'site-controlled', 'prerelease' );
		$beta        = $betaUpdater->filterUpdate(
			false,
			array( 'Version' => '1.2.3-beta.1' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $beta );
		self::assertSame( '1.2.3-beta.2', $beta['version'] );

		WordPressState::$siteTransients = array();
		$stableClient                   = new FakeReleaseArtifactClient( $this->descriptor() );
		$stableUpdater                  = $this->updater(
			$stableClient,
			'site-controlled',
			'prerelease'
		);
		$stable                         = $stableUpdater->filterUpdate(
			false,
			array( 'Version' => '1.2.3-beta.2' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $stable );
		self::assertSame( '1.2.3', $stable['version'] );
	}

	public function testCachedPrereleaseOfferRehydratesWithoutRemoteWork(): void {
		$client  = new FakeReleaseArtifactClient(
			$this->descriptor( true, '1.2.3-beta.2' )
		);
		$updater = $this->updater( $client, 'site-controlled', 'prerelease' );

		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$update = $updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3-beta.1' ),
				self::PLUGIN_BASENAME,
				array()
			);
			self::assertIsArray( $update );
			self::assertSame( '1.2.3-beta.2', $update['version'] );
		}

		self::assertSame( 1, $client->listCalls );
		self::assertSame( 1, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );
	}

	public function testInconsistentCachedCandidateIdentityIsRejectedAndRefetched(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );

		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		$cacheKey = array_key_first( WordPressState::$siteTransients );
		self::assertIsString( $cacheKey );
		WordPressState::$siteTransients[ $cacheKey ]['offer']['candidate_validation']['identity']['tag'] =
			'v1.2.2';

		self::assertNull( $updater->diagnostics()['candidate_validation'] );
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 2, $client->listCalls );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( 2, $client->acquireCalls );
	}

	public function testCurrentThemeDoesNotAcquireItsReleaseArchive(): void {
		$target = $this->themeTarget();
		WordPressState::$pluginData[ $target['pluginFile'] ] = array(
			'Name'      => 'Example Theme',
			'Version'   => '1.2.3',
			'UpdateURI' => 'https://github.com/RocketsAreNostalgic/example-theme',
		);
		$client  = new FakeReleaseArtifactClient( $this->themeDescriptor() );
		$updater = NativePluginUpdater::fromTarget( $target, $client, fn (): int => $this->now );
		self::assertInstanceOf( NativePluginUpdater::class, $updater );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.2.3' ),
				'locally-renamed-theme',
				array()
			)
		);

		self::assertSame( 1, $client->listCalls );
		self::assertSame( 1, $client->describeCalls );
		self::assertSame( 0, $client->acquireCalls );
		self::assertSame( 'up_to_date', $updater->diagnostics()['code'] );
	}

	public function testStableAdapterRejectsAPrereleaseDefensively(): void {
		$descriptor = $this->descriptor( true );
		$updater    = $this->updater( new FakeReleaseArtifactClient( $descriptor ) );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
	}

	public function testThemeHeaderMismatchSuppressesTheNativeOfferBeforeDownload(): void {
		$target = $this->themeTarget();
		WordPressState::$pluginData[ $target['pluginFile'] ] = array(
			'Name'      => 'Example Theme',
			'Version'   => '1.0.0',
			'UpdateURI' => 'https://github.com/RocketsAreNostalgic/example-theme',
		);
		$client                 = new FakeReleaseArtifactClient( $this->themeDescriptor() );
		$client->archiveEntries = array(
			'example-theme/style.css' => "/*\nTheme Name: Example\nVersion: 2.0.0\n*/",
		);
		$updater                = NativePluginUpdater::fromTarget(
			$target,
			$client,
			fn (): int => $this->now
		);
		self::assertInstanceOf( NativePluginUpdater::class, $updater );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				'locally-renamed-theme',
				array()
			)
		);
		self::assertSame( CandidateValidation::VERSION_MISMATCH, $updater->diagnostics()['code'] );
		self::assertSame( '2.0.0', $updater->diagnostics()['package_header_version'] );
		self::assertSame( 1, $client->acquireCalls );
		self::assertFalse(
			$updater->filterUpdate( false, array( 'Version' => '1.0.0' ), 'locally-renamed-theme', array() )
		);
		self::assertSame( 1, $client->acquireCalls );
		$_GET['force-check'] = '1';
		self::assertFalse(
			$updater->filterUpdate( false, array( 'Version' => '1.0.0' ), 'locally-renamed-theme', array() )
		);
		self::assertSame( 2, $client->acquireCalls );
	}

	public function testRejectedCandidatePreservesIncomingFilterAndPriorVerifiedOffer(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$this->now              = 22601;
		$client->archiveEntries = array(
			'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 2.0.0\n*/",
		);
		$incoming               = array( 'source' => 'prior-host-filter' );
		$result                 = $updater->filterUpdate(
			$incoming,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( $incoming, $result );
		self::assertSame( CandidateValidation::VERSION_MISMATCH, $updater->diagnostics()['code'] );
		self::assertSame( '1.2.3', $updater->diagnostics()['offered_version'] );
		self::assertSame( 2, $client->acquireCalls );
	}

	public function testNewestBrokenTrustContractDoesNotDowngradeToAnOlderRelease(): void {
		$client = new FakeReleaseArtifactClient( $this->descriptor() );
		array_unshift(
			$client->releases,
			new ReleaseSummary(
				99,
				'v2.0.0',
				'2.0.0'
			)
		);
		$client->descriptions[99] = new \WP_Error(
			'github_updater_missing_asset_digest',
			'The newest release has a broken trust contract.'
		);
		$updater                  = $this->updater( $client );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 1, $client->describeCalls );
		self::assertSame(
			'github_updater_missing_asset_digest',
			$updater->diagnostics()['code']
		);
	}

	public function testIncompatibleNewestReleaseFallsBackToOlderValidRelease(): void {
		$client = new FakeReleaseArtifactClient( $this->descriptor() );
		array_unshift(
			$client->releases,
			new ReleaseSummary( 99, 'v2.0.0', '2.0.0' )
		);
		$client->descriptions[99]              = $this->descriptor(
			false,
			'2.0.0',
			'example-plugin',
			99
		);
		$client->archiveEntriesByReleaseId[99] = array(
			'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: 2.0.0\nUpdate URI: https://github.com/RocketsAreNostalgic/example-plugin\nRequires PHP: 99.0\nRequires at least: 6.5\n*/",
		);
		$updater                               = $this->updater( $client );

		$update = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $update );
		self::assertSame( '1.2.3', $update['version'] );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( 2, $client->acquireCalls );
	}

	public function testStableChannelSkipsNewestPrereleaseAndSelectsOlderStableRelease(): void {
		$client     = new FakeReleaseArtifactClient( $this->descriptor() );
		$prerelease = $this->descriptor( true, '2.0.0' );
		array_unshift(
			$client->releases,
			new ReleaseSummary( 99, $prerelease->tag(), $prerelease->version() )
		);
		$client->descriptions[99] = $prerelease;
		$updater                  = $this->updater( $client );

		$update = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $update );
		self::assertSame( '1.2.3', $update['version'] );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );
	}

	public function testRateLimitCooldownPreservesIncomingFilterAndCachedOffer(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$first   = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $first );

		$this->now              = 22601;
		$client->nextListResult = new ReleaseListResult(
			array(),
			new ConditionalState(),
			new RateLimit( RateLimit::LIMITED, 0, null, 120 )
		);
		$incoming               = array( 'source' => 'prior-host-filter' );
		$limited                = $updater->filterUpdate(
			$incoming,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( $incoming, $limited );
		self::assertSame( 'rate_limited', $updater->diagnostics()['code'] );
		self::assertSame( '1.2.3', $updater->diagnostics()['offered_version'] );
		self::assertSame( 2, $client->listCalls );
		self::assertSame( 21720, array_values( WordPressState::$siteTransientExpirations )[0] );
	}

	public function testDiscoveryAuthenticationFailurePreservesIncomingFilterTruth(): void {
		$client            = new FakeReleaseArtifactClient( $this->descriptor() );
		$client->listError = new \WP_Error(
			'github_updater_github_authentication_failed',
			'Authentication failed.'
		);
		$updater           = $this->updater( $client );
		$incoming          = array( 'source' => 'prior-host-filter' );

		$result = $updater->filterUpdate(
			$incoming,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( $incoming, $result );
		self::assertSame(
			'github_updater_github_authentication_failed',
			$updater->diagnostics()['code']
		);
	}

	public function testNoEligibleReleaseAndMalformedLocalVersionPreserveIncomingFilter(): void {
		$client           = new FakeReleaseArtifactClient( $this->descriptor() );
		$client->releases = array();
		$updater          = $this->updater( $client );
		$incoming         = array( 'source' => 'prior-host-filter' );

		self::assertSame(
			$incoming,
			$updater->filterUpdate(
				$incoming,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 'no_eligible_release', $updater->diagnostics()['code'] );

		$otherUpdater = $this->updater( new FakeReleaseArtifactClient( $this->descriptor() ) );
		self::assertSame(
			$incoming,
			$otherUpdater->filterUpdate(
				$incoming,
				array( 'Version' => str_repeat( '1', 101 ) ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 'invalid_installed_version', $otherUpdater->diagnostics()['code'] );
	}

	public function testAcquisitionRateLimitPreservesCooldownAndIncomingFilter(): void {
		$client               = new FakeReleaseArtifactClient( $this->descriptor() );
		$client->acquireError = new \WP_Error(
			'github_updater_rate_limited',
			'Rate limited.',
			array( 'cooldown' => 300 )
		);
		$updater              = $this->updater( $client );
		$incoming             = array( 'source' => 'prior-host-filter' );

		$result = $updater->filterUpdate(
			$incoming,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( $incoming, $result );
		self::assertSame( 'rate_limited', $updater->diagnostics()['code'] );
		self::assertSame( 1300, $updater->diagnostics()['next_check'] );
	}

	public function testCompatibilityFallbackIsCappedAtEightDescriptions(): void {
		$client = new FakeReleaseArtifactClient( $this->descriptor() );
		for ( $index = 1; $index <= 7; ++$index ) {
			$releaseId = 100 + $index;
			array_unshift(
				$client->releases,
				new ReleaseSummary( $releaseId, 'v2.0.' . $index, '2.0.' . $index )
			);
			$client->descriptions[ $releaseId ]              = $this->descriptor(
				false,
				'2.0.' . $index,
				'example-plugin',
				$releaseId
			);
			$client->archiveEntriesByReleaseId[ $releaseId ] =
				$this->incompatiblePluginArchive( '2.0.' . $index );
		}
		$updater = $this->updater( $client );

		$result = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $result );
		self::assertInstanceOf( ReleaseQuery::class, $client->lastListQuery );
		self::assertSame( ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS, $client->lastListQuery->candidateLimit() );
		self::assertSame( 8, $client->describeCalls );
	}

	public function testExhaustedCompatibilitySearchPreservesIncomingFilter(): void {
		$client   = new FakeReleaseArtifactClient( $this->descriptor() );
		$releases = array();
		for ( $index = 1; $index <= ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS; ++$index ) {
			$releaseId                                       = 100 + $index;
			$releases[]                                      = new ReleaseSummary(
				$releaseId,
				'v2.0.' . $index,
				'2.0.' . $index
			);
			$client->descriptions[ $releaseId ]              = $this->descriptor(
				false,
				'2.0.' . $index,
				'example-plugin',
				$releaseId
			);
			$client->archiveEntriesByReleaseId[ $releaseId ] =
				$this->incompatiblePluginArchive( '2.0.' . $index );
		}
		$client->nextListResult = new ReleaseListResult(
			$releases,
			new ConditionalState(),
			new RateLimit(),
			false,
			true
		);
		$updater                = $this->updater( $client );
		$incoming               = array( 'source' => 'prior-host-filter' );

		$result = $updater->filterUpdate(
			$incoming,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( $incoming, $result );
		self::assertSame( ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS, $client->describeCalls );
		self::assertSame(
			'github_updater_release_search_budget_exhausted',
			$updater->diagnostics()['code']
		);
	}

	public function testNotModifiedRevalidatesExactReleaseBeforeReusingCachedOffer(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$first   = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $first );
		self::assertSame( 108000, array_values( WordPressState::$siteTransientExpirations )[0] );

		$this->now              = 22601;
		$client->nextListResult = new ReleaseListResult(
			array(),
			new ConditionalState(),
			new RateLimit(),
			true
		);
		$second                 = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $second );
		self::assertSame( 2, $client->listCalls );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( 2, $client->acquireCalls );
		$state = array_values( WordPressState::$siteTransients )[0];
		self::assertSame( 22601, $state['checked_at'] );
		self::assertSame( '"etag"', $state['conditional']['etag'] );
		self::assertSame(
			'Thu, 24 Jul 2026 12:00:00 GMT',
			$state['conditional']['last_modified']
		);
	}

	public function testMalformedPersistedConditionalValidatorsBecomeUnconditional(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$cacheKey = array_key_first( WordPressState::$siteTransients );
		self::assertIsString( $cacheKey );
		$state                                       = WordPressState::$siteTransients[ $cacheKey ];
		$state['conditional']['etag']                = "\"cached\r\nX-Injected: yes\"";
		$state['conditional']['last_modified']       = "Thu, 24 Jul 2026 12:00:00 GMT\r\nX-Injected: yes";
		WordPressState::$siteTransients[ $cacheKey ] = $state;
		$this->now                                   = 22_601;

		$updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( 2, $client->listCalls );
		self::assertInstanceOf( ReleaseQuery::class, $client->lastListQuery );
		self::assertSame(
			array(),
			$client->lastListQuery->conditional()->requestHeaders()
		);
	}

	public function testNotModifiedPurgesOfferWhenExactReleaseMoved(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$this->now                = 22601;
		$client->nextListResult   = new ReleaseListResult(
			array(),
			new ConditionalState(),
			new RateLimit(),
			true
		);
		$client->descriptions[42] = $this->descriptor( true );
		$result                   = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertFalse( $result );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( '1.2.3', $updater->diagnostics()['offered_version'] );
		self::assertSame( 'github_updater_release_changed', $updater->diagnostics()['code'] );
	}

	public function testAuthorizedForceCheckBypassesFreshCacheButNotActiveCooldown(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$_GET['force-check'] = '1';
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 2, $client->listCalls );

		$client->nextListResult = new ReleaseListResult(
			array(),
			new ConditionalState(),
			new RateLimit( RateLimit::LIMITED, 0, null, 120 )
		);
		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 3, $client->listCalls );
		self::assertSame( 'rate_limited', $updater->diagnostics()['code'] );
		self::assertSame( '1.2.3', $updater->diagnostics()['offered_version'] );

		self::assertFalse(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 3, $client->listCalls );
	}

	public function testPreservesOtherPluginsAndAppliesAllAutoUpdatePolicies(): void {
		$client = new FakeReleaseArtifactClient( $this->descriptor() );
		self::assertSame(
			array( 'version' => '9.9.9' ),
			$this->updater( $client )->filterUpdate(
				array( 'version' => '9.9.9' ),
				array( 'Version' => '1.0.0' ),
				'other/other.php',
				array()
			)
		);
		self::assertSame( 0, $client->listCalls );

		self::assertNull(
			$this->updater( $client, 'site-controlled' )->filterAutoUpdate(
				null,
				(object) array( 'plugin' => self::PLUGIN_BASENAME )
			)
		);
		self::assertFalse(
			$this->updater( $client, 'forced-off' )->filterAutoUpdate(
				true,
				(object) array( 'plugin' => self::PLUGIN_BASENAME )
			)
		);
		self::assertTrue(
			$this->updater( $client, 'forced-on' )->filterAutoUpdate(
				false,
				(object) array( 'plugin' => self::PLUGIN_BASENAME )
			)
		);
		self::assertTrue(
			$this->updater( $client, 'forced-off' )->filterAutoUpdate(
				true,
				(object) array( 'plugin' => 'other/other.php' )
			)
		);
	}

	public function testReturnsLeanDetailsForTheExactSlugOnly(): void {
		$updater = $this->updater( new FakeReleaseArtifactClient( $this->descriptor() ) );
		$prior   = (object) array( 'unchanged' => true );

		self::assertSame(
			$prior,
			$updater->filterPluginInformation(
				$prior,
				'plugin_information',
				(object) array( 'slug' => 'other-plugin' )
			)
		);

		$details = $updater->filterPluginInformation(
			false,
			'plugin_information',
			(object) array( 'slug' => 'example-plugin' )
		);
		self::assertIsObject( $details );
		self::assertSame( 'Example Plugin', $details->name );
		self::assertSame( '1.2.3', $details->version );
		self::assertSame( 'Example description.', $details->sections['description'] );
		self::assertStringStartsWith( 'https://api.github.com/repos/', $details->download_link );
	}

	public function testPreDownloadPreservesShortCircuitsAndClaimsExactArtifact(): void {
		$descriptor = $this->descriptor();
		$client     = new FakeReleaseArtifactClient( $descriptor );
		$updater    = $this->updater( $client );
		$update     = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		self::assertSame( 1, $client->describeCalls );
		self::assertSame( 1, $client->acquireCalls );

		$prior = new \WP_Error( 'prior', 'Prior short circuit.' );
		self::assertSame(
			$prior,
			$updater->filterPreDownload(
				$prior,
				$update['package'],
				null,
				array( 'plugin' => self::PLUGIN_BASENAME )
			)
		);
		self::assertSame( 1, $client->acquireCalls );

		$unverified = $updater->filterPreDownload(
			'/tmp/unverified-package.zip',
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertInstanceOf( \WP_Error::class, $unverified );
		self::assertSame(
			'github_updater_unverified_pre_download_result',
			$unverified->get_error_code()
		);
		self::assertSame(
			'/tmp/other-plugin.zip',
			$updater->filterPreDownload(
				'/tmp/other-plugin.zip',
				'https://example.test/other.zip',
				null,
				array( 'plugin' => 'other/other.php' )
			)
		);

		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );
		self::assertFileExists( $path );
		self::assertSame( 2, $client->describeCalls );
		self::assertSame( 2, $client->acquireCalls );
		self::assertCount( 1, WordPressState::$deletedFiles );
		self::assertSame( 1, WordPressState::hookCount( 'pre_unzip_file' ) );
		self::assertArrayHasKey(
			PHP_INT_MAX,
			WordPressState::$filters['pre_unzip_file']
		);

		wp_delete_file( $path );
		self::assertFileDoesNotExist( $path );
	}

	public function testPreDownloadRerunsAssuranceAgainstTheFreshInstallationZip(): void {
		$checks    = 0;
		$assurance = new ReleaseAssurance();
		self::assertTrue(
			$assurance->register(
				static function () use ( &$checks ): ?\WP_Error {
					++$checks;
					return 1 === $checks
						? null
						: new \WP_Error( 'fixture_fresh_assurance_rejected', 'Rejected.' );
				}
			)
		);
		$assurance->seal();

		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client, assurance: $assurance );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		self::assertSame( 1, $checks );

		$result = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'fixture_fresh_assurance_rejected', $result->get_error_code() );
		self::assertSame( 2, $checks );
		self::assertSame( 2, $client->acquireCalls );
		self::assertCount( 2, WordPressState::$deletedFiles );
	}

	public function testExactVerifiedArchiveUsesCoreReportedExtractionCeiling(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );

		self::assertNull(
			$updater->filterPreUnzipFile(
				null,
				$path,
				'/tmp/staging',
				array(),
				268435456.0
			)
		);
		self::assertSame( 0, WordPressState::hookCount( 'pre_unzip_file' ) );
		wp_delete_file( $path );

		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );
		$oversize = $updater->filterPreUnzipFile(
			null,
			$path,
			'/tmp/staging',
			array(),
			268435457.0
		);
		self::assertInstanceOf( \WP_Error::class, $oversize );
		self::assertSame( 'github_updater_extraction_too_large', $oversize->get_error_code() );
		self::assertSame( 0, WordPressState::hookCount( 'pre_unzip_file' ) );

		wp_delete_file( $path );
	}

	public function testNativeClaimRejectsArchiveMutationBeforeExtraction(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );
		file_put_contents( $path, 'substituted archive bytes' );

		$result = $updater->filterPreUnzipFile(
			null,
			$path,
			'/tmp/staging',
			array(),
			1024.0
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_artifact_identity_changed', $result->get_error_code() );
		self::assertSame( 0, WordPressState::hookCount( 'pre_unzip_file' ) );
		wp_delete_file( $path );
	}

	/**
	 * @dataProvider coreHandoffRegistrationOrderProvider
	 */
	public function testPreDownloadAdmitsOnlyTheScopedCoreReinstallHandoff( bool $updaterFirst ): void {
		$updater = $this->updater( new FakeReleaseArtifactClient( $this->descriptor() ) );
		$path    = '/tmp/core-reinstall-artifact.zip';
		$extra   = array(
			'plugin' => self::PLUGIN_BASENAME,
			'type'   => 'plugin',
			'action' => 'update',
		);

		$rejected = $updater->filterPreDownload( $path, $path, null, $extra );
		self::assertInstanceOf( \WP_Error::class, $rejected );
		self::assertSame( 'github_updater_unverified_pre_download_result', $rejected->get_error_code() );

		$registerCore = static function () use ( $path ): void {
			add_filter(
				'upgrader_pre_download',
				static fn ( mixed $reply ): mixed => false === $reply ? $path : $reply,
				10,
				4
			);
			add_filter(
				\RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight::CORE_REINSTALL_HANDOFF_FILTER,
				static function ( mixed $admitted, mixed $reply, string $package, array $hookExtra, string $type, string $identifier ) use ( $path ): mixed {
					return $path === $reply
						&& $path === $package
						&& self::PLUGIN_BASENAME === ( $hookExtra['plugin'] ?? null )
						&& 'plugin' === $type
						&& self::PLUGIN_BASENAME === $identifier
						? true
						: $admitted;
				},
				10,
				7
			);
		};
		if ( $updaterFirst ) {
			$updater->register();
			$registerCore();
		} else {
			$registerCore();
			$updater->register();
		}

		self::assertInstanceOf(
			\WP_Error::class,
			$updater->filterPreDownload( $path, '/tmp/other-artifact.zip', null, $extra )
		);
		self::assertInstanceOf(
			\WP_Error::class,
			$updater->filterPreDownload(
				$path,
				$path,
				null,
				array(
					'plugin' => self::PLUGIN_BASENAME,
					'type'   => 'plugin',
					'action' => 'install',
				)
			)
		);
		self::assertSame(
			$path,
			apply_filters( 'upgrader_pre_download', false, $path, null, $extra )
		);
		self::assertSame( 1, WordPressState::hookCount( 'pre_unzip_file' ) );
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function coreHandoffRegistrationOrderProvider(): array {
		return array(
			'updater then Core' => array( true ),
			'Core then updater' => array( false ),
		);
	}

	/**
	 * @dataProvider invalidExtractionSpaceProvider
	 */
	public function testInvalidExtractionSpaceFailsClosed( float $requiredSpace ): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );

		$result = $updater->filterPreUnzipFile(
			true,
			$path,
			'/stage',
			array(),
			$requiredSpace
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_extraction_too_large', $result->get_error_code() );
		self::assertSame( 0, WordPressState::hookCount( 'pre_unzip_file' ) );
		wp_delete_file( $path );
	}

	/**
	 * @return array<string, array{0: float}>
	 */
	public static function invalidExtractionSpaceProvider(): array {
		return array(
			'negative'     => array( -1.0 ),
			'positive inf' => array( INF ),
			'not a number' => array( NAN ),
		);
	}

	public function testCustomExtractorKeepsPendingIdentityForSourceValidation(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );
		self::assertTrue(
			$updater->filterPreUnzipFile( true, $path, '/stage', array(), 1024.0 )
		);

		$GLOBALS['wp_filesystem'] = new FakeWordPressFilesystem( '/stage/' );
		WordPressState::$pluginData['/stage/example-plugin/example-plugin.php'] = array(
			'Name'        => 'Example Plugin',
			'Version'     => '1.2.3',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.0',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/example-plugin',
		);
		self::assertSame(
			'/stage/example-plugin/',
			$updater->filterSourceSelection(
				'/stage/example-plugin/',
				'/stage/',
				null,
				array( 'plugin' => self::PLUGIN_BASENAME )
			)
		);
		wp_delete_file( $path );
	}

	public function testPriorExtractionErrorClearsPendingInstall(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );
		$prior = new \WP_Error( 'prior_extraction_failure', 'Extraction aborted.' );
		self::assertSame(
			$prior,
			$updater->filterPreUnzipFile( $prior, $path, '/stage', array(), 1024.0 )
		);

		$GLOBALS['wp_filesystem'] = new FakeWordPressFilesystem( '/stage/' );
		self::assertSame(
			'/stage/example-plugin/',
			$updater->filterSourceSelection(
				'/stage/example-plugin/',
				'/stage/',
				null,
				array( 'plugin' => self::PLUGIN_BASENAME )
			)
		);
		wp_delete_file( $path );
	}

	public function testNonMatchingArchiveDoesNotConsumeExtractionState(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );

		self::assertTrue(
			$updater->filterPreUnzipFile(
				true,
				'/tmp/different.zip',
				'/stage',
				array(),
				268435457.0
			)
		);
		self::assertSame( 1, WordPressState::hookCount( 'pre_unzip_file' ) );
		self::assertNull(
			$updater->filterPreUnzipFile( null, $path, '/stage', array(), 1024.0 )
		);
		wp_delete_file( $path );
	}

	public function testStagedIdentityIsValidatedAndRenamedDirectoryIsMapped(): void {
		WordPressState::$pluginBasenames[ $this->pluginFile ] = 'locally-renamed/example-plugin.php';
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			'locally-renamed/example-plugin.php',
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => 'locally-renamed/example-plugin.php' )
		);
		self::assertIsString( $path );
		self::assertNull(
			$updater->filterPreUnzipFile( null, $path, '/stage', array(), 1024.0 )
		);

		$filesystem               = new FakeWordPressFilesystem( '/stage/' );
		$GLOBALS['wp_filesystem'] = $filesystem;
		WordPressState::$pluginData['/stage/example-plugin/example-plugin.php'] = array(
			'Name'        => 'Example Plugin',
			'Version'     => '1.2.3',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.0',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/example-plugin',
		);

		$source = $updater->filterSourceSelection(
			'/stage/example-plugin/',
			'/stage/',
			null,
			array( 'plugin' => 'locally-renamed/example-plugin.php' )
		);
		self::assertSame( '/stage/locally-renamed/', $source );
		self::assertSame(
			array( '/stage/example-plugin', '/stage/locally-renamed', false ),
			$filesystem->moves[0] ?? null
		);
		wp_delete_file( $path );
	}

	public function testStagedIdentityMismatchFailsBeforeDestinationMutation(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertIsString( $path );
		self::assertNull(
			$updater->filterPreUnzipFile( null, $path, '/stage', array(), 1024.0 )
		);
		$GLOBALS['wp_filesystem'] = new FakeWordPressFilesystem( '/stage/' );
		WordPressState::$pluginData['/stage/example-plugin/example-plugin.php'] = array(
			'Name'        => 'Example Plugin',
			'Version'     => '9.9.9',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.0',
		);

		$result = $updater->filterSourceSelection(
			'/stage/example-plugin/',
			'/stage/',
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_release_version_mismatch', $result->get_error_code() );
		self::assertSame( array(), $GLOBALS['wp_filesystem']->moves );
		wp_delete_file( $path );
	}

	public function testStagedUpdateUriMismatchFailsBeforeDestinationMutation(): void {
		WordPressState::$pluginBasenames[ $this->pluginFile ] = 'locally-renamed/example-plugin.php';
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$update  = $updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			'locally-renamed/example-plugin.php',
			array()
		);
		self::assertIsArray( $update );
		$path = $updater->filterPreDownload(
			false,
			$update['package'],
			null,
			array( 'plugin' => 'locally-renamed/example-plugin.php' )
		);
		self::assertIsString( $path );
		self::assertNull(
			$updater->filterPreUnzipFile( null, $path, '/stage', array(), 1024.0 )
		);

		$filesystem               = new FakeWordPressFilesystem( '/stage/' );
		$GLOBALS['wp_filesystem'] = $filesystem;
		WordPressState::$pluginData['/stage/example-plugin/example-plugin.php'] = array(
			'Name'        => 'Example Plugin',
			'Version'     => '1.2.3',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.0',
			'UpdateURI'   => 'https://github.com/example/wrong',
		);
		$result = $updater->filterSourceSelection(
			'/stage/example-plugin/',
			'/stage/',
			null,
			array( 'plugin' => 'locally-renamed/example-plugin.php' )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_staged_update_uri_mismatch', $result->get_error_code() );
		self::assertSame( array(), $filesystem->moves );
		wp_delete_file( $path );
	}

	public function testPreDownloadRejectsAnAssetOutsideTheCachedExactOffer(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		$result = $updater->filterPreDownload(
			false,
			'https://example.test/not-the-offer.zip',
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_unverified_update', $result->get_error_code() );
		self::assertSame( 1, $client->acquireCalls );
	}

	public function testCompletionClearsOfferAndDiagnosticsStayPassive(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$updater->register();
		$updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertSame( 1, $client->listCalls );

		$before = $updater->diagnostics();
		self::assertSame( '1.2.3', $before['offered_version'] );
		self::assertSame( 1, $client->listCalls );

		WordPressState::$pluginData[ $this->pluginFile ]['Version'] = '1.2.3';
		$updater->observeCompletion(
			(object) array( 'result' => array( 'irrelevant' => 'bulk-last-result' ) ),
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( self::PLUGIN_BASENAME ),
			)
		);
		$after = $updater->diagnostics();
		self::assertNull( $after['offered_version'] );
		self::assertSame( 'update_completed', $after['code'] );
		self::assertSame( 1, $client->listCalls );
	}

	public function testNonBulkCoreFailureRetainsOfferAndExactDiagnostic(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$updater->observeCompletion(
			(object) array(
				'result' => new \WP_Error(
					'filesystem_credentials_unavailable',
					'Filesystem credentials are unavailable.'
				),
			),
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => self::PLUGIN_BASENAME,
			)
		);

		self::assertSame( 'filesystem_credentials_unavailable', $updater->diagnostics()['code'] );
		self::assertSame( '1.2.3', $updater->diagnostics()['offered_version'] );
	}

	public function testPluginCompletionAcceptsTwoPartHeaderVersion(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor( false, '2.1.0' ) );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate( false, array( 'Version' => '1.0.0' ), self::PLUGIN_BASENAME, array() )
		);

		WordPressState::$pluginData[ $this->pluginFile ]['Version'] = '2.1';
		$updater->observeCompletion(
			(object) array( 'result' => true ),
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => self::PLUGIN_BASENAME,
			)
		);

		self::assertSame( 'update_completed', $updater->diagnostics()['code'] );
		self::assertNull( $updater->diagnostics()['offered_version'] );
	}

	public function testThemeCompletionAcceptsTwoPartHeaderVersion(): void {
		$target = $this->themeTarget();
		WordPressState::$pluginData[ $target['pluginFile'] ] = array(
			'Name'      => 'Example Theme',
			'Version'   => '1.0.0',
			'UpdateURI' => 'https://github.com/RocketsAreNostalgic/example-theme',
		);
		$client                 = new FakeReleaseArtifactClient( $this->themeDescriptor( '2.1.0' ) );
		$client->archiveEntries = array(
			'example-theme/style.css' => "/*\nTheme Name: Example Theme\nVersion: 2.1.0\nUpdate URI: https://github.com/RocketsAreNostalgic/example-theme\nRequires PHP: 8.2\nRequires at least: 6.5\n*/",
		);
		$updater                = NativePluginUpdater::fromTarget( $target, $client, fn (): int => $this->now );
		self::assertInstanceOf( NativePluginUpdater::class, $updater );
		self::assertIsArray(
			$updater->filterUpdate( false, array( 'Version' => '1.0.0' ), 'locally-renamed-theme', array() )
		);

		WordPressState::$pluginData[ $target['pluginFile'] ]['Version'] = '2.1';
		$updater->observeCompletion(
			(object) array( 'result' => true ),
			array(
				'action' => 'update',
				'type'   => 'theme',
				'theme'  => 'locally-renamed-theme',
			)
		);

		self::assertSame( 'update_completed', $updater->diagnostics()['code'] );
		self::assertNull( $updater->diagnostics()['offered_version'] );
	}

	public function testCompletionRetainsOfferForAGenuinelyDifferentVersion(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor( false, '2.1.0' ) );
		$updater = $this->updater( $client );
		self::assertIsArray(
			$updater->filterUpdate( false, array( 'Version' => '1.0.0' ), self::PLUGIN_BASENAME, array() )
		);

		WordPressState::$pluginData[ $this->pluginFile ]['Version'] = '2.1.1';
		$updater->observeCompletion(
			(object) array( 'result' => true ),
			array(
				'action' => 'update',
				'type'   => 'plugin',
				'plugin' => self::PLUGIN_BASENAME,
			)
		);

		self::assertSame( 'release_available', $updater->diagnostics()['code'] );
		self::assertSame( '2.1.0', $updater->diagnostics()['offered_version'] );
	}

	public function testBulkCompletionDoesNotClearAnOfferWithoutPerTargetPostInstallSuccess(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$updater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		WordPressState::$pluginData[ $this->pluginFile ]['Version'] = '1.0.0';

		$updater->observeCompletion(
			(object) array( 'result' => array( 'destination_name' => 'other-plugin' ) ),
			array(
				'action'  => 'update',
				'type'    => 'plugin',
				'bulk'    => true,
				'plugins' => array( self::PLUGIN_BASENAME, 'other/other.php' ),
			)
		);

		self::assertSame( '1.2.3', $updater->diagnostics()['offered_version'] );
		self::assertSame( 'bulk_update_version_mismatch', $updater->diagnostics()['code'] );
	}

	public function testNoticeIsScreenScopedCapabilityScopedFilterableAndSanitized(): void {
		$client  = new FakeReleaseArtifactClient( $this->descriptor() );
		$updater = $this->updater( $client );
		$updater->register();
		$result = $updater->filterPreDownload(
			false,
			'https://example.test/not-the-offer.zip',
			null,
			array( 'plugin' => self::PLUGIN_BASENAME )
		);
		self::assertInstanceOf( \WP_Error::class, $result );

		add_filter(
			'ran_wp_github_release_updater_notice',
			static function ( array $notice, array $context ): array {
				self::assertSame( 'Example Plugin', $context['name'] );
				self::assertArrayNotHasKey( 'plugin_file', $context );
				$notice['severity']    = 'warning<script>';
				$notice['message']     = '<script>secret()</script> Safe message';
				$notice['remediation'] = '<b>Retry.</b>';
				return $notice;
			},
			10,
			2
		);

		WordPressState::$currentUserCan = false;
		WordPressState::$screenBase     = 'plugins';
		ob_start();
		$updater->renderAdminNotice();
		self::assertSame( '', ob_get_clean() );

		WordPressState::$currentUserCan = true;
		WordPressState::$screenBase     = 'dashboard';
		ob_start();
		$updater->renderAdminNotice();
		self::assertSame( '', ob_get_clean() );

		WordPressState::$screenBase = 'plugins-network';
		ob_start();
		$updater->renderAdminNotice();
		$output = ob_get_clean();
		self::assertIsString( $output );
		self::assertStringContainsString( 'notice-error', $output );
		self::assertStringContainsString( 'Safe message', $output );
		self::assertStringContainsString( 'Retry.', $output );
		self::assertStringNotContainsString( '<script>', $output );
		self::assertStringNotContainsString( '<b>', $output );
	}

	public function testConfigurationAcceptsBetaFeaturesAndRejectsInvalidValuesOrUpdateUri(): void {
		$client                = new FakeReleaseArtifactClient( $this->descriptor() );
		$target                = $this->target();
		$target['accessToken'] = 'secret';

		$result = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( NativePluginUpdater::class, $result );

		$target                         = $this->target();
		$target['providerRepositoryId'] = '123456789';
		$result                         = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( NativePluginUpdater::class, $result );

		$target                         = $this->target();
		$target['providerRepositoryId'] = 'not-numeric';
		$result                         = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_invalid_repository_identity', $result->get_error_code() );

		$target                         = $this->target();
		$target['providerRepositoryId'] = array( 'not-a-string' );
		$result                         = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_invalid_repository', $result->get_error_code() );

		$target                = $this->target();
		$target['accessToken'] = array( 'not-callable' );
		$result                = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_invalid_access_token', $result->get_error_code() );

		$target = $this->target();
		WordPressState::$pluginData[ $this->pluginFile ] = array(
			'UpdateURI' => 'https://github.com/example/wrong',
		);
		$result = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_invalid_update_uri', $result->get_error_code() );

		WordPressState::$pluginData[ $this->pluginFile ]['UpdateURI'] =
			'https://GITHUB.com/rocketsarenostalgic/EXAMPLE-plugin/';
		WordPressState::$pluginBasenames[ $this->pluginFile ]         =
			'locally-renamed/example-plugin.php';
		$result = NativePluginUpdater::fromTarget( $target, $client );
		self::assertInstanceOf( NativePluginUpdater::class, $result );
	}

	public function testPublicAndPrivateTargetsDoNotShareCachedOffers(): void {
		$publicClient                 = new FakeReleaseArtifactClient( $this->descriptor() );
		$privateClient                = new FakeReleaseArtifactClient( $this->descriptor() );
		$public                       = NativePluginUpdater::fromTarget(
			$this->target(),
			$publicClient,
			fn (): int => $this->now
		);
		$privateTarget                = $this->target();
		$privateTarget['accessToken'] = 'request-scoped-secret';
		$private                      = NativePluginUpdater::fromTarget(
			$privateTarget,
			$privateClient,
			fn (): int => $this->now
		);
		self::assertInstanceOf( NativePluginUpdater::class, $public );
		self::assertInstanceOf( NativePluginUpdater::class, $private );

		$public->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		$private->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( 1, $publicClient->listCalls );
		self::assertSame( 1, $privateClient->listCalls );
		self::assertCount( 2, WordPressState::$siteTransients );
		self::assertStringNotContainsString(
			'request-scoped-secret',
			serialize( WordPressState::$siteTransients )
		);
	}

	public function testStableRepositoryIdentitiesDoNotShareCachedOffers(): void {
		$firstClient                          = new FakeReleaseArtifactClient( $this->descriptor() );
		$secondClient                         = new FakeReleaseArtifactClient( $this->descriptor() );
		$firstTarget                          = $this->target();
		$firstTarget['providerRepositoryId']  = '123456789';
		$first                                = NativePluginUpdater::fromTarget(
			$firstTarget,
			$firstClient,
			fn (): int => $this->now
		);
		$secondTarget                         = $this->target();
		$secondTarget['providerRepositoryId'] = '987654321';
		$second                               = NativePluginUpdater::fromTarget(
			$secondTarget,
			$secondClient,
			fn (): int => $this->now
		);
		self::assertInstanceOf( NativePluginUpdater::class, $first );
		self::assertInstanceOf( NativePluginUpdater::class, $second );

		$first->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		$second->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertSame( 1, $firstClient->listCalls );
		self::assertSame( 1, $secondClient->listCalls );
		self::assertCount( 2, WordPressState::$siteTransients );
	}

	public function testOldCacheSchemaIsNotReused(): void {
		$firstClient  = new FakeReleaseArtifactClient( $this->descriptor() );
		$firstUpdater = $this->updater( $firstClient );
		self::assertIsArray(
			$firstUpdater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		$cacheKey = array_key_first( WordPressState::$siteTransients );
		self::assertIsString( $cacheKey );
		WordPressState::$siteTransients[ $cacheKey ]['schema'] = 5;

		$secondClient  = new FakeReleaseArtifactClient( $this->descriptor() );
		$secondUpdater = $this->updater( $secondClient );
		self::assertIsArray(
			$secondUpdater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);
		self::assertSame( 1, $secondClient->listCalls );
		self::assertSame( 1, $secondClient->acquireCalls );
	}

	public function testCorrectedCanonicalPluginSlugDoesNotReuseACachedOffer(): void {
		$firstClient  = new FakeReleaseArtifactClient( $this->descriptor() );
		$firstUpdater = $this->updater( $firstClient );
		$first        = $firstUpdater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);
		self::assertIsArray( $first );

		$correctedTarget               = $this->target();
		$correctedTarget['pluginSlug'] = 'corrected-plugin';
		$correctedClient               = new FakeReleaseArtifactClient(
			$this->descriptor( false, '1.2.4', 'corrected-plugin' )
		);
		$correctedUpdater              = NativePluginUpdater::fromTarget(
			$correctedTarget,
			$correctedClient,
			fn (): int => $this->now
		);
		self::assertInstanceOf( NativePluginUpdater::class, $correctedUpdater );
		$corrected = $correctedUpdater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $corrected );
		self::assertSame( '1.2.4', $corrected['version'] );
		self::assertSame( 1, $correctedClient->listCalls );
		self::assertSame( 1, $correctedClient->acquireCalls );
		self::assertCount( 2, WordPressState::$siteTransients );
	}

	public function testChangedWordPressVersionDoesNotReuseACachedOffer(): void {
		$firstClient  = new FakeReleaseArtifactClient( $this->descriptor() );
		$firstUpdater = $this->updater( $firstClient );
		self::assertIsArray(
			$firstUpdater->filterUpdate(
				false,
				array( 'Version' => '1.0.0' ),
				self::PLUGIN_BASENAME,
				array()
			)
		);

		$GLOBALS['wp_version'] = '6.6';
		$correctedClient       = new FakeReleaseArtifactClient( $this->descriptor( false, '1.2.4' ) );
		$correctedUpdater      = $this->updater( $correctedClient );
		$corrected             = $correctedUpdater->filterUpdate(
			false,
			array( 'Version' => '1.0.0' ),
			self::PLUGIN_BASENAME,
			array()
		);

		self::assertIsArray( $corrected );
		self::assertSame( '1.2.4', $corrected['version'] );
		self::assertSame( 1, $correctedClient->listCalls );
		self::assertSame( '6.6', $correctedClient->lastListQuery->wordpressVersion() );
		self::assertCount( 2, WordPressState::$siteTransients );
	}

	public function testDummyPluginShowsEveryExplicitConstructorOption(): void {
		$fixture = file_get_contents(
			dirname( __DIR__, 2 ) . '/fixtures/dummy-plugin/dummy-plugin.php'
		);
		self::assertIsString( $fixture );
		foreach (
			array(
				'pluginFile:',
				'repository:',
				'pluginSlug:',
				'channel:',
				'accessToken:',
				'autoUpdatePolicy:',
				'cacheDuration:',
				'failureCacheDuration:',
			) as $option
		) {
			self::assertStringContainsString( $option, $fixture );
		}
	}

	private function updater(
		FakeReleaseArtifactClient $client,
		string $policy = 'site-controlled',
		string $channel = 'stable',
		?ReleaseAssurance $assurance = null
	): NativePluginUpdater {
		$target                     = $this->target();
		$target['autoUpdatePolicy'] = $policy;
		$target['channel']          = $channel;
		$updater                    = NativePluginUpdater::fromTarget(
			$target,
			$client,
			fn (): int => $this->now,
			$assurance
		);
		self::assertInstanceOf( NativePluginUpdater::class, $updater );
		return $updater;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function target(): array {
		return array(
			'pluginFile'           => $this->pluginFile,
			'repository'           => 'RocketsAreNostalgic/example-plugin',
			'pluginSlug'           => 'example-plugin',
			'channel'              => 'stable',
			'accessToken'          => null,
			'autoUpdatePolicy'     => 'site-controlled',
			'cacheDuration'        => 21600,
			'failureCacheDuration' => 900,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function themeTarget(): array {
		return array(
			'targetType'           => 'theme',
			'pluginFile'           => dirname( __DIR__, 2 ) . '/fixtures/dummy-theme/style.css',
			'stylesheet'           => 'locally-renamed-theme',
			'repository'           => 'RocketsAreNostalgic/example-theme',
			'pluginSlug'           => 'example-theme',
			'channel'              => 'stable',
			'accessToken'          => null,
			'autoUpdatePolicy'     => 'manual',
			'cacheDuration'        => 21600,
			'failureCacheDuration' => 900,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function incompatiblePluginArchive( string $version ): array {
		return array(
			'example-plugin/example-plugin.php' => "<?php\n/*\nPlugin Name: Example Plugin\nVersion: "
				. $version
				. "\nUpdate URI: https://github.com/RocketsAreNostalgic/example-plugin"
				. "\nRequires PHP: 99.0\nRequires at least: 6.5\n*/",
		);
	}

	private function descriptor(
		bool $prerelease = false,
		string $version = '1.2.3',
		string $pluginRoot = 'example-plugin',
		int $releaseId = 42
	): ArtifactDescriptor {
		$repository = Repository::fromString( 'RocketsAreNostalgic/example-plugin' );
		self::assertInstanceOf( Repository::class, $repository );
		$version = $prerelease && ! str_contains( $version, '-' )
			? $version . '-beta.1'
			: $version;
		$tag     = 'v' . $version;
		$query   = new ReleaseQuery(
			$repository,
			ReleaseQuery::STABLE
		);

		return new ArtifactDescriptor(
			$query,
			$repository,
			$releaseId,
			$tag,
			$version,
			str_repeat( '1', 40 ),
			$prerelease,
			'https://github.com/RocketsAreNostalgic/example-plugin/releases/tag/' . $tag,
			new ReleaseAsset(
				101,
				$pluginRoot . '-' . $version . '.zip',
				18,
				str_repeat( 'a', 64 )
			),
			true
		);
	}

	private function themeDescriptor( string $version = '1.2.3' ): ArtifactDescriptor {
		$repository = Repository::fromString( 'RocketsAreNostalgic/example-theme' );
		self::assertInstanceOf( Repository::class, $repository );
		$query = new ReleaseQuery(
			$repository,
			ReleaseQuery::STABLE
		);

		return new ArtifactDescriptor(
			$query,
			$repository,
			43,
			'v' . $version,
			$version,
			str_repeat( '2', 40 ),
			false,
			'https://github.com/RocketsAreNostalgic/example-theme/releases/tag/v' . $version,
			new ReleaseAsset(
				201,
				'example-theme-' . $version . '.zip',
				18,
				str_repeat( 'b', 64 )
			),
			true
		);
	}
}

/**
 * Deterministic artifact service fake for adapter-only tests.
 */
final class FakeReleaseArtifactClient implements ReleaseArtifactClient {

	public int $listCalls     = 0;
	public int $describeCalls = 0;
	public int $acquireCalls  = 0;

	/** @var list<ReleaseSummary> */
	public array $releases;

	/** @var array<int, ArtifactDescriptor|\WP_Error> */
	public array $descriptions;

	public ?ReleaseListResult $nextListResult = null;

	public ?\WP_Error $listError = null;

	public ?\WP_Error $acquireError = null;

	/** @var array<string, string>|null */
	public ?array $archiveEntries = null;

	/** @var array<int, array<string, string>> */
	public array $archiveEntriesByReleaseId = array();

	public ?ReleaseQuery $lastListQuery = null;

	public function __construct( private ArtifactDescriptor $descriptor ) {
		$this->releases     = array(
			new ReleaseSummary(
				$this->descriptor->releaseId(),
				$this->descriptor->tag(),
				$this->descriptor->version()
			),
		);
		$this->descriptions = array(
			$this->descriptor->releaseId() => $this->descriptor,
		);
	}

	public function listReleases( ReleaseQuery $query ) {
		$this->lastListQuery = $query;
		++$this->listCalls;
		if ( null !== $this->listError ) {
			return $this->listError;
		}
		if ( null !== $this->nextListResult ) {
			$result               = $this->nextListResult;
			$this->nextListResult = null;
			return $result;
		}
		return new ReleaseListResult(
			$this->releases,
			new ConditionalState( '"etag"', 'Thu, 24 Jul 2026 12:00:00 GMT' ),
			new RateLimit()
		);
	}

	public function describeExact( ExactReleaseRequest $request ) {
		++$this->describeCalls;
		return $this->descriptions[ $request->releaseId() ]
			?? new \WP_Error( 'not_found', 'Release not found.' );
	}

	public function acquireDescribed( ArtifactDescriptor $descriptor ) {
		++$this->acquireCalls;
		if ( null !== $this->acquireError ) {
			return $this->acquireError;
		}
		$temporaryFiles = new WordPressTemporaryFileFactory();
		$path           = $temporaryFiles->create( $descriptor->zipAsset()->name() );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'zip_failed', 'Test ZIP creation failed.' );
		}
		$entries = $this->archiveEntriesByReleaseId[ $descriptor->releaseId() ]
			?? $this->archiveEntries
			?? $this->defaultArchiveEntries( $descriptor );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();
		$bytes = file_get_contents( $path );
		if ( ! is_string( $bytes ) ) {
			return new \WP_Error( 'zip_read_failed', 'Test ZIP read failed.' );
		}
		chmod( $path, 0600 );
		$identity = VerifiedArtifact::fileIdentity( $path );
		if ( null === $identity ) {
			return new \WP_Error( 'identity_failed', 'File identity unavailable.' );
		}

		return new VerifiedArtifact(
			$path,
			hash( 'sha256', $bytes ),
			$temporaryFiles,
			$identity
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function defaultArchiveEntries( ArtifactDescriptor $descriptor ): array {
		$isTheme = str_ends_with( $descriptor->repository()->canonical(), '/example-theme' );
		$root    = $isTheme
			? 'example-theme'
			: preg_replace(
				'/-' . preg_quote( $descriptor->version(), '/' ) . '\.zip$/',
				'',
				$descriptor->zipAsset()->name()
			);
		if ( ! is_string( $root ) || '' === $root ) {
			$root = 'example-plugin';
		}
		$headerFile = $isTheme ? 'style.css' : 'example-plugin.php';
		$nameHeader = $isTheme ? 'Theme Name' : 'Plugin Name';

		return array(
			$root . '/' . $headerFile => "<?php\n/*\n"
				. $nameHeader
				. ": Example Package\nVersion: "
				. $descriptor->version()
				. "\nUpdate URI: https://github.com/"
				. $descriptor->repository()->canonical()
				. "\nRequires PHP: "
				. ( $isTheme ? '8.2' : '8.0' )
				. "\nRequires at least: 6.5\n*/\n",
		);
	}
}

/**
 * Small staging-only WP_Filesystem stand-in.
 */
final class FakeWordPressFilesystem {

	/** @var list<array{string, string, bool}> */
	public array $moves = array();

	/** @var array<string, true> */
	private array $directories;

	/** @var array<string, true> */
	private array $files;

	public function __construct(
		private string $remoteRoot,
		private string $packageRoot = 'example-plugin',
		private string $mainFile = 'example-plugin.php'
	) {
		$this->remoteRoot  = rtrim( $this->remoteRoot, '/' ) . '/';
		$this->directories = array(
			$this->remoteRoot                            => true,
			$this->remoteRoot . $this->packageRoot . '/' => true,
		);
		$this->files       = array(
			$this->remoteRoot . $this->packageRoot . '/' . $this->mainFile => true,
		);
	}

	/**
	 * @return array<string, array{type: string}>|false
	 */
	public function dirlist( string $path ) {
		if ( rtrim( $path, '/' ) . '/' !== $this->remoteRoot ) {
			return false;
		}

		return array( $this->packageRoot => array( 'type' => 'd' ) );
	}

	public function is_dir( string $path ): bool {
		return isset( $this->directories[ rtrim( $path, '/' ) . '/' ] );
	}

	public function is_file( string $path ): bool {
		return isset( $this->files[ $path ] );
	}

	public function move( string $source, string $destination, bool $overwrite = false ): bool {
		$this->moves[] = array( $source, $destination, $overwrite );
		if ( ! $this->is_dir( $source ) || $this->is_dir( $destination ) ) {
			return false;
		}

		unset( $this->directories[ rtrim( $source, '/' ) . '/' ] );
		$this->directories[ rtrim( $destination, '/' ) . '/' ] = true;
		return true;
	}
}
