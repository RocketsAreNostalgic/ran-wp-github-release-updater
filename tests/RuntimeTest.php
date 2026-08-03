<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\WordPressState;

final class RuntimeTest extends TestCase {

	private string $plugin_file;

	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
		$GLOBALS['wp_version'] = '6.6';

		$this->plugin_file                                     = dirname( __DIR__ ) . '/fixtures/dummy-plugin/dummy-plugin.php';
		WordPressState::$pluginBasenames[ $this->plugin_file ] =
			'booster-fixture-plugin/booster-fixture-plugin.php';
		WordPressState::$pluginData[ $this->plugin_file ]      = array(
			'Name'        => 'Booster Fixture Plugin',
			'Version'     => '0.1.0',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/booster-fixture-plugin',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.2',
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['ran_wp_github_release_updater_v1_broker'],
			$GLOBALS['wp_version']
		);
		parent::tearDown();
	}

	public function testSelectedRuntimePublishesExactProspectiveApiVersion(): void {
		require dirname( __DIR__ ) . '/runtime.php';
		$class = 'RAN\\WPGitHubReleaseUpdater\\V1\\WordPress\\ReleaseCandidatePreflight';

		self::assertTrue( class_exists( $class, false ) );
		self::assertSame( 4, constant( $class . '::PROSPECTIVE_API_VERSION' ) );
		self::assertTrue( method_exists( $class, 'fromProspectiveTarget' ) );
		$factory = new \ReflectionMethod( $class, 'fromProspectiveTarget' );
		self::assertSame( 1, $factory->getNumberOfParameters() );
		self::assertTrue( method_exists( $class, 'discover' ) );
		self::assertTrue( method_exists( $class, 'listCandidates' ) );
		self::assertTrue( method_exists( $class, 'inspectExact' ) );
		self::assertTrue( method_exists( $class, 'acquireExact' ) );
		self::assertFalse( defined( $class . '::REINSTALL_API_VERSION' ) );
		self::assertTrue( defined( $class . '::CORE_REINSTALL_HANDOFF_FILTER' ) );
		self::assertFalse( method_exists( $class, 'inspectInstalledVersion' ) );
		self::assertFalse( method_exists( $class, 'acquireInstalledVersion' ) );
	}

	public function testBootstrapDefersAndLoadsTheRealRuntimeEndToEnd(): void {
		$create_updater = require dirname( __DIR__ ) . '/bootstrap.php';
		$updater        = $create_updater(
			pluginFile: $this->plugin_file,
			repository: 'RocketsAreNostalgic/booster-fixture-plugin',
			providerRepositoryId: '123456789',
			pluginSlug: 'booster-fixture-plugin',
			channel: 'stable',
			accessToken: null,
			autoUpdatePolicy: 'site-controlled',
			cacheDuration: 21_600,
			failureCacheDuration: 900,
		);

		$updater->register();
		self::assertSame( 0, WordPressState::hookCount( 'update_plugins_github.com' ) );

		do_action( 'plugins_loaded' );

		self::assertSame( 1, WordPressState::hookCount( 'update_plugins_github.com' ) );
		self::assertSame( 1, WordPressState::hookCount( 'upgrader_pre_download' ) );
		self::assertSame( array(), WordPressState::$httpRequests );

		$diagnostics = $updater->diagnostics();
		self::assertTrue( $diagnostics['registered'] );
		self::assertSame( '2.0.0-beta.3', $diagnostics['selected_version'] ); // x-release-please-version
		self::assertSame( 'not_checked', $diagnostics['code'] );
		self::assertSame( 'RocketsAreNostalgic/booster-fixture-plugin', $diagnostics['repository'] );
	}

	public function testBootstrapSelectsRuntimeWithoutNativeDiscoveryHooks(): void {
		$create_updater = require dirname( __DIR__ ) . '/bootstrap.php';
		$updater        = $create_updater(
			pluginFile: $this->plugin_file,
			repository: 'RocketsAreNostalgic/booster-fixture-plugin',
			providerRepositoryId: '123456789',
			pluginSlug: 'booster-fixture-plugin',
			nativeDiscovery: false
		);

		$updater->register();
		do_action( 'plugins_loaded' );

		foreach (
			array(
				'update_plugins_github.com',
				'plugins_api',
				'auto_update_plugin',
				'upgrader_pre_download',
				'upgrader_source_selection',
				'upgrader_process_complete',
				'admin_notices',
				'network_admin_notices',
			) as $hook
		) {
			self::assertSame( 0, WordPressState::hookCount( $hook ), $hook );
		}
		self::assertSame( array(), WordPressState::$httpRequests );
		self::assertFalse( $updater->refresh() );

		$diagnostics = $updater->diagnostics();
		self::assertTrue( $diagnostics['registered'] );
		self::assertSame( '2.0.0-beta.3', $diagnostics['selected_version'] ); // x-release-please-version
		self::assertSame( 'inactive', $diagnostics['state'] );
		self::assertSame( 'native_discovery_disabled', $diagnostics['code'] );
	}

	public function testRegistersAValidTargetAndAttachesPassiveDiagnostics(): void {
		$broker = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint = require dirname( __DIR__ ) . '/runtime.php';

		$entrypoint( array( $this->target( 'target-1' ) ) );

		self::assertSame( 1, WordPressState::hookCount( 'update_plugins_github.com' ) );
		self::assertSame( 1, WordPressState::hookCount( 'plugins_api' ) );
		self::assertSame( 1, WordPressState::hookCount( 'auto_update_plugin' ) );
		self::assertSame( 1, WordPressState::hookCount( 'upgrader_pre_download' ) );
		self::assertSame( 1, WordPressState::hookCount( 'upgrader_process_complete' ) );
		self::assertArrayHasKey( 'target-1', $broker->providers );

		$diagnostics = ( $broker->providers['target-1'] )();
		self::assertTrue( $diagnostics['registered'] );
		self::assertSame( 'RocketsAreNostalgic/booster-fixture-plugin', $diagnostics['repository'] );
		self::assertArrayNotHasKey( 'accessToken', $diagnostics );
	}

	/**
	 * @dataProvider assuranceRegistrationOrderProvider
	 */
	public function testAssuranceRegistrationWorksInEitherPluginLoadOrder( bool $listenBeforeRuntime ): void {
		$broker = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$seen     = null;
		$listener = static function ( object $assurance ) use ( &$seen ): void {
			$seen = $assurance;
			self::assertTrue( $assurance->register( static fn (): null => null ) );
		};
		if ( $listenBeforeRuntime ) {
			add_action(
				'ran_wp_github_release_updater_v1_assurance_registration',
				$listener
			);
		}
		$entrypoint = require dirname( __DIR__ ) . '/runtime.php';
		if ( ! $listenBeforeRuntime ) {
			add_action(
				'ran_wp_github_release_updater_v1_assurance_registration',
				$listener
			);
		}

		$entrypoint( array( $this->target( 'target-1' ) ) );

		self::assertIsObject( $seen );
		self::assertSame(
			1,
			WordPressState::didAction(
				'ran_wp_github_release_updater_v1_assurance_registration'
			)
		);
		self::assertFalse( $seen->register( static fn (): null => null ) );
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function assuranceRegistrationOrderProvider(): array {
		return array(
			'consumer before updater runtime' => array( true ),
			'updater runtime before consumer' => array( false ),
		);
	}

	public function testRuntimeOnlyTargetSkipsValidationNoticesAndAllNativeHooks(): void {
		$broker = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint                = require dirname( __DIR__ ) . '/runtime.php';
		$target                    = $this->target( 'target-1' );
		$target['repository']      = 'not-a-valid-repository';
		$target['nativeDiscovery'] = false;

		$entrypoint( array( $target ) );

		self::assertSame( array(), WordPressState::$actions );
		self::assertSame( array(), WordPressState::$filters );
		self::assertSame( array(), WordPressState::$httpRequests );
		self::assertSame(
			array(
				'state' => 'inactive',
				'code'  => 'native_discovery_disabled',
			),
			( $broker->providers['target-1'] )()
		);
	}

	public function testRegistersAThemeOnNativeThemeHooksWithoutCredentialWork(): void {
		$theme_file                                = dirname( __DIR__ ) . '/fixtures/dummy-theme/style.css';
		WordPressState::$pluginData[ $theme_file ] = array(
			'Name'        => 'Booster Fixture Theme',
			'Version'     => '0.1.0',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/booster-fixture-theme',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.2',
		);
		$broker                                    = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint  = require dirname( __DIR__ ) . '/runtime.php';
		$resolutions = 0;

		$entrypoint(
			array(
				array(
					'registrationId'       => 'theme-1',
					'targetType'           => 'theme',
					'pluginFile'           => $theme_file,
					'stylesheet'           => 'locally-renamed-theme',
					'repository'           => 'RocketsAreNostalgic/booster-fixture-theme',
					'providerRepositoryId' => '987654321',
					'pluginSlug'           => 'booster-fixture-theme',
					'channel'              => 'stable',
					'accessToken'          => static function () use ( &$resolutions ): string {
						++$resolutions;
						return 'never-resolved-at-registration';
					},
					'autoUpdatePolicy'     => 'manual',
					'cacheDuration'        => 21600,
					'failureCacheDuration' => 900,
				),
			)
		);

		self::assertSame( 1, WordPressState::hookCount( 'update_themes_github.com' ) );
		self::assertSame( 1, WordPressState::hookCount( 'auto_update_theme' ) );
		self::assertSame( 0, WordPressState::hookCount( 'plugins_api' ) );
		self::assertSame( 0, $resolutions );
		$diagnostics = ( $broker->providers['theme-1'] )();
		self::assertSame( 'theme', $diagnostics['type'] );
		self::assertSame( 'locally-renamed-theme', $diagnostics['package'] );
		self::assertTrue( $diagnostics['authentication_configured'] );
		self::assertSame( 0, $resolutions );
	}

	public function testConflictingBasenamesDisableOnlyThoseTargets(): void {
		$broker = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint = require dirname( __DIR__ ) . '/runtime.php';

		$entrypoint(
			array(
				$this->target( 'target-1' ),
				$this->target( 'target-2' ),
			)
		);

		self::assertSame( 0, WordPressState::hookCount( 'update_plugins_github.com' ) );
		self::assertSame(
			'conflicting_plugin_target',
			( $broker->providers['target-1'] )()['code']
		);
		self::assertSame(
			'conflicting_plugin_target',
			( $broker->providers['target-2'] )()['code']
		);
	}

	public function testDistinctPluginTargetsWithTheSameCanonicalSlugAreBothInactive(): void {
		$second_file = $this->secondPluginFile();
		$broker      = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint = require dirname( __DIR__ ) . '/runtime.php';
		$first      = $this->target( 'target-1' );
		$second     = $this->target( 'target-2', $second_file );

		$entrypoint( array( $second, $first ) );

		self::assertSame( 0, WordPressState::hookCount( 'update_plugins_github.com' ) );
		self::assertSame( 0, WordPressState::hookCount( 'plugins_api' ) );
		self::assertSame(
			array(
				'state' => 'inactive',
				'code'  => 'conflicting_plugin_slug',
			),
			( $broker->providers['target-1'] )()
		);
		self::assertSame(
			array(
				'state' => 'inactive',
				'code'  => 'conflicting_plugin_slug',
			),
			( $broker->providers['target-2'] )()
		);
	}

	public function testDistinctPluginTargetsWithUniqueSlugsRegisterNormally(): void {
		$second_file = $this->secondPluginFile();
		$broker      = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint           = require dirname( __DIR__ ) . '/runtime.php';
		$second               = $this->target( 'target-2', $second_file );
		$second['pluginSlug'] = 'second-plugin';

		$entrypoint( array( $this->target( 'target-1' ), $second ) );

		self::assertSame( 2, WordPressState::hookCount( 'update_plugins_github.com' ) );
		self::assertSame( 2, WordPressState::hookCount( 'plugins_api' ) );
		self::assertTrue( ( $broker->providers['target-1'] )()['registered'] );
		self::assertTrue( ( $broker->providers['target-2'] )()['registered'] );
	}

	public function testPrivateCredentialRegistersWithoutResolutionOrDisclosure(): void {
		$broker = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint            = require dirname( __DIR__ ) . '/runtime.php';
		$target                = $this->target( 'target-1' );
		$resolutions           = 0;
		$target['accessToken'] = static function () use ( &$resolutions ): string {
			++$resolutions;
			return 'must-not-be-exposed';
		};

		$entrypoint( array( $target ) );

		self::assertSame( 1, WordPressState::hookCount( 'update_plugins_github.com' ) );
		self::assertSame( 0, $resolutions );
		$diagnostics = ( $broker->providers['target-1'] )();
		self::assertTrue( $diagnostics['private_support'] );
		self::assertSame( 0, $resolutions );
		self::assertStringNotContainsString(
			'must-not-be-exposed',
			serialize( $diagnostics )
		);
	}

	public function testInvalidConfigurationRegistersAnActionableSanitizedNotice(): void {
		$broker = new RuntimeDiagnosticsBroker();
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = $broker;
		$entrypoint           = require dirname( __DIR__ ) . '/runtime.php';
		$target               = $this->target( 'target-1' );
		$target['repository'] = 'RocketsAreNostalgic/wrong-repository';

		$entrypoint( array( $target ) );

		self::assertSame(
			'github_updater_invalid_update_uri',
			( $broker->providers['target-1'] )()['code']
		);
		self::assertSame( 1, WordPressState::hookCount( 'admin_notices' ) );
		self::assertSame( 1, WordPressState::hookCount( 'network_admin_notices' ) );

		ob_start();
		do_action( 'admin_notices' );
		$output = ob_get_clean();
		self::assertIsString( $output );
		self::assertStringContainsString(
			'Booster Fixture Plugin has an invalid GitHub updater configuration.',
			$output
		);
		self::assertStringNotContainsString( $this->plugin_file, $output );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function target( string $registration_id, ?string $plugin_file = null ): array {
		return array(
			'registrationId'       => $registration_id,
			'pluginFile'           => $plugin_file ?? $this->plugin_file,
			'repository'           => 'RocketsAreNostalgic/booster-fixture-plugin',
			'providerRepositoryId' => '123456789',
			'pluginSlug'           => 'booster-fixture-plugin',
			'channel'              => 'stable',
			'accessToken'          => null,
			'autoUpdatePolicy'     => 'site-controlled',
			'cacheDuration'        => 21600,
			'failureCacheDuration' => 900,
		);
	}

	private function secondPluginFile(): string {
		$plugin_file                                     = dirname( __DIR__ ) . '/fixtures/dummy-theme/style.css';
		WordPressState::$pluginBasenames[ $plugin_file ] = 'second-plugin/second-plugin.php';
		WordPressState::$pluginData[ $plugin_file ]      = array(
			'Name'        => 'Second Plugin',
			'Version'     => '1.0.0',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/booster-fixture-plugin',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.2',
		);
		return $plugin_file;
	}
}

final class RuntimeDiagnosticsBroker {

	/** @var array<string, callable(): array<string, mixed>> */
	public array $providers = array();

	public function attachDiagnosticsProvider( string $registration_id, callable $provider ): bool {
		$this->providers[ $registration_id ] = $provider;
		return true;
	}
}
