<?php
/**
 * Tests for the request-local package broker and target facade.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

// PHPUnit test filenames follow the repository's test naming convention.
// phpcs:disable WordPress.Files.FileName

namespace Tests;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use Tests\Support\WordPressState;

/**
 * Exercise bootstrap behavior in isolated PHP processes.
 */
final class BootstrapTest extends TestCase {
	/**
	 * Start each isolated test with empty hook state.
	 */
	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
		unset(
			$GLOBALS['ran_wp_github_release_updater_v1_broker'],
			$GLOBALS['ran_wp_github_release_updater_broker_runtime_targets'],
			$GLOBALS['ran_wp_github_release_updater_v1_target_registrations']
		);
		$GLOBALS['wp_version'] = '6.5';
	}

	/**
	 * The factory retains the V1 named-argument compatibility contract.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testFactoryExposesAllDocumentedNamedOptions(): void {
		$factory    = require dirname( __DIR__ ) . '/bootstrap.php';
		$reflection = new ReflectionFunction( $factory );
		$parameters = array();

		foreach ( $reflection->getParameters() as $parameter ) {
			$parameters[ $parameter->getName() ] = $parameter;
		}

		self::assertSame(
			array(
				'pluginFile',
				'repository',
				'providerRepositoryId',
				'pluginSlug',
				'channel',
				'accessToken',
				'autoUpdatePolicy',
				'cacheDuration',
				'failureCacheDuration',
				'nativeDiscovery',
				'additionalOptions',
			),
			array_keys( $parameters )
		);
		self::assertSame( 'stable', $parameters['channel']->getDefaultValue() );
		self::assertFalse( $parameters['providerRepositoryId']->isOptional() );
		self::assertSame( 'string', (string) $parameters['providerRepositoryId']->getType() );
		self::assertNull( $parameters['accessToken']->getDefaultValue() );
		self::assertSame(
			'site-controlled',
			$parameters['autoUpdatePolicy']->getDefaultValue()
		);
		self::assertSame( 21_600, $parameters['cacheDuration']->getDefaultValue() );
		self::assertSame(
			900,
			$parameters['failureCacheDuration']->getDefaultValue()
		);
		self::assertTrue( $parameters['nativeDiscovery']->getDefaultValue() );
		self::assertTrue( $parameters['additionalOptions']->isVariadic() );
	}

	/**
	 * Runtime loading is deferred and receives one complete plain target.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRegisterDefersRuntimeAndPreservesNamedAndUnknownOptions(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$broker->registerCandidate(
			array(
				'broker_protocol' => 1,
				'package_version' => '9.0.0-dev',
				'php_floor'       => '8.2.0',
				'wordpress_floor' => '6.5',
				'path'            => __DIR__ . '/Support/TargetCaptureRuntime',
				'runtime_file'    => __DIR__ . '/Support/TargetCaptureRuntime/runtime.php',
			)
		);

		$token_resolver = static fn (): ?string => 'never-diagnose-this-token';
		$facade         = $factory(
			pluginFile: '/var/www/wp-content/plugins/renamed/main.php',
			repository: 'RocketsAreNostalgic/example-plugin',
			providerRepositoryId: '123456789',
			pluginSlug: null,
			channel: 'prerelease',
			accessToken: $token_resolver,
			autoUpdatePolicy: 'forced-off',
			cacheDuration: 1_200,
			failureCacheDuration: 120,
			nativeDiscovery: false,
			futureOption: array( 'preserve' => true )
		);

		$facade->register();
		$facade->register();

		self::assertArrayNotHasKey(
			'ran_wp_github_release_updater_broker_runtime_targets',
			$GLOBALS
		);
		self::assertSame( 'registered', $facade->diagnostics()['state'] );
		self::assertStringNotContainsString(
			'never-diagnose-this-token',
			(string) json_encode( $facade->diagnostics() )
		);

		WordPressState::doAction( 'plugins_loaded' );

		$targets = $GLOBALS['ran_wp_github_release_updater_broker_runtime_targets'];
		self::assertCount( 1, $targets );
		self::assertSame(
			'/var/www/wp-content/plugins/renamed/main.php',
			$targets[0]['pluginFile']
		);
		self::assertSame(
			'RocketsAreNostalgic/example-plugin',
			$targets[0]['repository']
		);
		self::assertSame( '123456789', $targets[0]['providerRepositoryId'] );
		self::assertSame( 'example-plugin', $targets[0]['pluginSlug'] );
		self::assertSame( 'prerelease', $targets[0]['channel'] );
		self::assertSame( $token_resolver, $targets[0]['accessToken'] );
		self::assertFalse( $targets[0]['nativeDiscovery'] );
		self::assertSame( array( 'preserve' => true ), $targets[0]['futureOption'] );
		self::assertNotEmpty( $targets[0]['registrationId'] );
		self::assertSame( 'active', $facade->diagnostics()['state'] );
		self::assertSame(
			'runtime_selected',
			$facade->diagnostics()['code']
		);
		self::assertSame(
			'9.0.0-dev',
			$facade->diagnostics()['selected_version']
		);
	}

	/**
	 * Provider targets can register during the normal plugins_loaded window.
	 *
	 * @dataProvider providerPluginLoadOrderProvider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param list<string> $plugins Physical plugin load order.
	 */
	public function testProviderWindowRegistrationPrecedesRuntimeSelection( array $plugins ): void {
		foreach ( $plugins as $plugin ) {
			require __DIR__ . '/Support/ProviderWindow/' . $plugin;
		}

		self::assertArrayHasKey(
			PHP_INT_MAX - 1,
			WordPressState::$actions['plugins_loaded']
		);
		WordPressState::doAction( 'plugins_loaded' );

		$facade = $GLOBALS['ran_wp_github_release_updater_provider_window_facade'];
		self::assertSame(
			'awaiting_runtime',
			$GLOBALS['ran_wp_github_release_updater_provider_window_registration_code']
		);
		self::assertSame( 'active', $facade->diagnostics()['state'] );
		self::assertSame( 'runtime_selected', $facade->diagnostics()['code'] );
		self::assertCount(
			1,
			$GLOBALS['ran_wp_github_release_updater_broker_runtime_targets']
		);
		self::assertSame(
			'/plugins/provider-package/provider-package.php',
			$GLOBALS['ran_wp_github_release_updater_broker_runtime_targets'][0]['pluginFile']
		);
	}

	/**
	 * Physical plugin orders for provider-window registration.
	 *
	 * @return array<string, array{0: list<string>}>
	 */
	public static function providerPluginLoadOrderProvider(): array {
		return array(
			'consumer then provider' => array(
				array( 'consumer-plugin.php', 'external-provider-plugin.php' ),
			),
			'provider then consumer' => array(
				array( 'external-provider-plugin.php', 'consumer-plugin.php' ),
			),
		);
	}

	/**
	 * A target declared after the end-of-window selector remains late.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRegistrationAfterEndOfProviderWindowRemainsLate(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$facade  = $factory(
			pluginFile: '/plugins/late-provider/late-provider.php',
			repository: 'owner/late-provider',
			providerRepositoryId: '123456789'
		);

		WordPressState::addAction(
			'plugins_loaded',
			static function () use ( $facade ): void {
				$facade->register();
			},
			PHP_INT_MAX,
			0
		);
		WordPressState::doAction( 'plugins_loaded' );

		self::assertSame( 'inactive', $facade->diagnostics()['state'] );
		self::assertSame( 'late_registration', $facade->diagnostics()['code'] );
		self::assertArrayNotHasKey(
			'ran_wp_github_release_updater_broker_runtime_targets',
			$GLOBALS
		);
	}

	/**
	 * Mixed copies select the same highest compatible runtime and retain both
	 * target origins regardless of their relative registration order.
	 *
	 * @dataProvider mixedCopyRegistrationOrderProvider
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param list<string> $versions Candidate registration order.
	 */
	public function testMixedCopyRegistrationOrderPreservesTargetStates( array $versions ): void {
		require dirname( __DIR__ ) . '/bootstrap.php';
		$broker          = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$registrationIds = array();
		$runtimePath     = __DIR__ . '/Support/TargetCaptureRuntime';

		foreach ( $versions as $version ) {
			$candidateId                 = $broker->registerCandidate(
				array(
					'broker_protocol' => 1,
					'package_version' => $version,
					'php_floor'       => '8.2.0',
					'wordpress_floor' => '6.5',
					'path'            => $runtimePath,
					'runtime_file'    => $runtimePath . '/runtime.php',
				)
			);
			$registrationIds[ $version ] = $broker->allocateRegistrationId( $candidateId );
			self::assertTrue(
				$broker->registerTarget(
					array(
						'registrationId' => $registrationIds[ $version ],
						'pluginFile'     => '/plugins/copy-' . $version . '/main.php',
						'repository'     => 'owner/copy-' . $version,
					)
				)
			);
		}

		WordPressState::doAction( 'plugins_loaded' );

		$targets = $GLOBALS['ran_wp_github_release_updater_broker_runtime_targets'];
		self::assertCount( 2, $targets );
		self::assertEqualsCanonicalizing(
			array(
				'/plugins/copy-1.4.0-beta.1/main.php',
				'/plugins/copy-9.0.0-dev/main.php',
			),
			array_column( $targets, 'pluginFile' )
		);
		foreach ( $registrationIds as $minimumVersion => $registrationId ) {
			$diagnostics = $broker->diagnostics( $registrationId, true );
			self::assertSame( 'active', $diagnostics['state'] );
			self::assertSame( 'runtime_selected', $diagnostics['code'] );
			self::assertSame( '9.0.0-dev', $diagnostics['selected_version'] );
			self::assertSame( $minimumVersion, $diagnostics['minimum_runtime_version'] );
		}
	}

	/**
	 * @return array<string, array{list<string>}>
	 */
	public static function mixedCopyRegistrationOrderProvider(): array {
		return array(
			'older then newer' => array( array( '1.4.0-beta.1', '9.0.0-dev' ) ),
			'newer then older' => array( array( '9.0.0-dev', '1.4.0-beta.1' ) ),
		);
	}

	/**
	 * A second facade retains every explicit V1 option independently.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testFacadeCarriesExplicitOptionValues(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$broker->registerCandidate(
			array(
				'broker_protocol' => 1,
				'package_version' => '9.0.0-dev',
				'php_floor'       => '8.2.0',
				'wordpress_floor' => '6.5',
				'path'            => __DIR__ . '/Support/TargetCaptureRuntime',
				'runtime_file'    => __DIR__ . '/Support/TargetCaptureRuntime/runtime.php',
			)
		);

		$facade = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/repository',
			providerRepositoryId: '123456789',
			pluginSlug: 'canonical-plugin',
			channel: 'stable',
			accessToken: 'secret',
			autoUpdatePolicy: 'forced-on',
			cacheDuration: 3_600,
			failureCacheDuration: 300,
			nativeDiscovery: false
		);
		$facade->register();
		WordPressState::doAction( 'plugins_loaded' );

		$target = $GLOBALS['ran_wp_github_release_updater_broker_runtime_targets'][0];
		self::assertSame( 'canonical-plugin', $target['pluginSlug'] );
		self::assertSame( 'forced-on', $target['autoUpdatePolicy'] );
		self::assertSame( 3_600, $target['cacheDuration'] );
		self::assertSame( 300, $target['failureCacheDuration'] );
		self::assertFalse( $target['nativeDiscovery'] );
		self::assertStringNotContainsString(
			'secret',
			(string) json_encode( $facade->diagnostics() )
		);
	}

	/**
	 * Optional consumers can detect only this package's accepted registrations
	 * by canonical WordPress target identity.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testTargetRegistrationSignalIsSpecificToAcceptedPluginAndThemeTargets(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';

		self::assertTrue(
			function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' )
		);
		self::assertFalse(
			ran_wp_github_release_updater_v1_has_registered_target(
				'plugin',
				'example/example.php'
			)
		);

		$plugin = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);
		$theme  = $factory(
			pluginFile: '/themes/example-theme/style.css',
			repository: 'owner/example-theme',
			providerRepositoryId: '987654321',
			targetType: 'theme',
			stylesheet: 'locally-renamed-theme'
		);

		$plugin->register();
		$theme->register();

		self::assertTrue(
			ran_wp_github_release_updater_v1_has_registered_target(
				'plugin',
				'example/example.php'
			)
		);
		self::assertTrue(
			ran_wp_github_release_updater_v1_has_registered_target(
				'theme',
				'locally-renamed-theme'
			)
		);
		self::assertFalse(
			ran_wp_github_release_updater_v1_has_registered_target(
				'plugin',
				'locally-renamed-theme'
			)
		);
		self::assertFalse(
			ran_wp_github_release_updater_v1_has_registered_target(
				'unsupported',
				'example/example.php'
			)
		);
	}

	/**
	 * The signal uses WordPress's canonical plugin identity when it is
	 * available, including a mapped plugin path.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testTargetRegistrationSignalUsesWordPressCanonicalPluginBasename(): void {
		$pluginFile                                     = '/plugins/relocated-package/main.php';
		WordPressState::$pluginBasenames[ $pluginFile ] = 'mapped-package/main.php';
		$factory                                        = require dirname( __DIR__ ) . '/bootstrap.php';
		$facade = $factory(
			pluginFile: $pluginFile,
			repository: 'owner/mapped-package',
			providerRepositoryId: '123456789'
		);

		$facade->register();

		self::assertTrue(
			ran_wp_github_release_updater_v1_has_registered_target(
				'plugin',
				'mapped-package/main.php'
			)
		);
		self::assertFalse(
			ran_wp_github_release_updater_v1_has_registered_target(
				'plugin',
				'relocated-package/main.php'
			)
		);
	}

	/**
	 * A late registration remains unavailable to optional consumers because the
	 * broker did not accept it for this request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testTargetRegistrationSignalExcludesLateTargets(): void {
		WordPressState::doAction( 'plugins_loaded' );
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$facade  = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);

		$facade->register();

		self::assertFalse(
			ran_wp_github_release_updater_v1_has_registered_target(
				'plugin',
				'example/example.php'
			)
		);
	}

	/**
	 * The signal is safe when a consumer loads its bootstrap before WordPress
	 * has defined plugin_basename().
	 */
	public function testTargetRegistrationSignalDoesNotRequirePluginBasenameDuringBootstrap(): void {
		$script = __DIR__ . '/Support/MinimalBootstrapSmoke/target-registration.php';
		$output = array();
		$status = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Runs the bootstrap without PHPUnit's WordPress function stubs.
		exec(
			escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ),
			$output,
			$status
		);

		self::assertSame( 0, $status, implode( "\n", $output ) );
		self::assertSame( array( 'Minimal bootstrap target registration passed.' ), $output );
	}

	/**
	 * A bootstrap first loaded after plugins_loaded is rejected for this request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testLateBootstrapRejectsTargetWithoutLoadingRuntime(): void {
		WordPressState::doAction( 'plugins_loaded' );
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$facade  = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);

		$facade->register();
		$facade->register();
		$diagnostics = $facade->diagnostics();

		self::assertTrue( $diagnostics['registered'] );
		self::assertSame( 'inactive', $diagnostics['state'] );
		self::assertSame( 'late_registration', $diagnostics['code'] );
		self::assertTrue( $diagnostics['selection_fixed'] );
		self::assertFalse(
			ran_wp_github_release_updater_v1_has_registered_target(
				'plugin',
				'example/example.php'
			)
		);
		self::assertArrayNotHasKey(
			'ran_wp_github_release_updater_broker_runtime_targets',
			$GLOBALS
		);
	}

	/**
	 * A partial foreign object cannot impersonate the guarded V1 broker.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testIncompleteForeignBrokerIsSafelyReplaced(): void {
		$GLOBALS['ran_wp_github_release_updater_v1_broker'] = new class() {
			/**
			 * Pretend to expose only one broker method.
			 *
			 * @param array<string, mixed> $candidate Candidate declaration.
			 */
			public function registerCandidate( array $candidate ): string {
				unset( $candidate );
				return 'foreign';
			}
		};

		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$facade  = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);
		$facade->register();

		self::assertSame( 1, $broker->protocolVersion() );
		self::assertSame( 'registered', $facade->diagnostics()['state'] );
		self::assertSame( 1, WordPressState::hookCount( 'plugins_loaded' ) );
	}

	/**
	 * A selected runtime failure remains target-local and request-safe.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRuntimeFailureIsContainedAndReportedPassively(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$broker->registerCandidate(
			array(
				'broker_protocol' => 1,
				'package_version' => '99.0.0-dev',
				'php_floor'       => '8.2.0',
				'wordpress_floor' => '6.5',
				'path'            => __DIR__ . '/Support/FailingRuntime',
				'runtime_file'    => __DIR__ . '/Support/FailingRuntime/runtime.php',
			)
		);
		$facade = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);
		$facade->register();

		WordPressState::doAction( 'plugins_loaded' );

		self::assertSame( 'inactive', $facade->diagnostics()['state'] );
		self::assertSame(
			'runtime_load_failed',
			$facade->diagnostics()['code']
		);
		self::assertNull( $facade->diagnostics()['selected_version'] );
	}

	/**
	 * A bootstrap failure has one generic, redacted notice on either relevant
	 * WordPress update surface, without relying on the unavailable runtime.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRuntimeLoadFailureNoticeIsScopedRedactedAndDeduplicated(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$broker->registerCandidate(
			array(
				'broker_protocol' => 1,
				'package_version' => '99.0.0-dev',
				'php_floor'       => '8.2.0',
				'wordpress_floor' => '6.5',
				'path'            => __DIR__ . '/Support/FailingRuntime',
				'runtime_file'    => __DIR__ . '/Support/FailingRuntime/runtime.php',
			)
		);
		$facade = $factory(
			pluginFile: '/private/<script>alert(1)</script>/example.php',
			repository: 'owner/secret-token',
			providerRepositoryId: '123456789',
			pluginSlug: '<script>secret-token</script>',
			accessToken: 'secret-token'
		);
		$facade->register();
		$theme = $factory(
			pluginFile: '/private/second-theme/style.css',
			repository: 'owner/second-secret-token',
			providerRepositoryId: '987654321',
			pluginSlug: 'second-theme',
			targetType: 'theme',
			stylesheet: 'second-theme'
		);
		$theme->register();

		WordPressState::doAction( 'plugins_loaded' );

		self::assertSame( 1, WordPressState::hookCount( 'admin_notices' ) );
		self::assertSame( 1, WordPressState::hookCount( 'network_admin_notices' ) );
		self::assertSame( 'runtime_load_failed', $theme->diagnostics()['code'] );

		WordPressState::$currentUserCan = false;
		ob_start();
		WordPressState::doAction( 'admin_notices' );
		self::assertSame( '', ob_get_clean() );

		WordPressState::$currentUserCan = true;
		WordPressState::$screenBase     = 'dashboard';
		ob_start();
		WordPressState::doAction( 'admin_notices' );
		self::assertSame( '', ob_get_clean() );

		WordPressState::$screenBase = 'plugins-network';
		ob_start();
		WordPressState::doAction( 'network_admin_notices' );
		self::assertSame( '', ob_get_clean() );

		WordPressState::$multisite  = true;
		WordPressState::$screenBase = 'plugins-network';
		ob_start();
		WordPressState::doAction( 'network_admin_notices' );
		$output = ob_get_clean();
		self::assertIsString( $output );
		self::assertStringContainsString( 'notice-error', $output );
		self::assertStringContainsString( 'could not load its GitHub release updater', $output );
		self::assertStringNotContainsString( '<script>', $output );
		self::assertStringNotContainsString( 'secret-token', $output );
		self::assertStringNotContainsString( '/private/', $output );

		ob_start();
		WordPressState::doAction( 'admin_notices' );
		self::assertSame( '', ob_get_clean() );
	}

	/**
	 * Only an actual selected-runtime load failure creates the bootstrap notice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testBootstrapFailureNoticeIsAbsentForNoCompatibleAndLateStates(): void {
		$GLOBALS['wp_version'] = '0.1';
		$factory               = require dirname( __DIR__ ) . '/bootstrap.php';
		$success               = $factory(
			pluginFile: '/plugins/success/success.php',
			repository: 'owner/success',
			providerRepositoryId: '123456789'
		);
		$success->register();
		WordPressState::doAction( 'plugins_loaded' );

		self::assertSame( 0, WordPressState::hookCount( 'admin_notices' ) );
		self::assertSame( 0, WordPressState::hookCount( 'network_admin_notices' ) );
		self::assertSame( 'no_compatible_runtime', $success->diagnostics()['code'] );

		$late = $factory(
			pluginFile: '/plugins/late/late.php',
			repository: 'owner/late',
			providerRepositoryId: '123456789'
		);
		$late->register();
		self::assertSame( 'late_registration', $late->diagnostics()['code'] );
		self::assertSame( 0, WordPressState::hookCount( 'admin_notices' ) );
		self::assertSame( 0, WordPressState::hookCount( 'network_admin_notices' ) );
	}

	/**
	 * A selected working runtime does not create a bootstrap failure notice.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testBootstrapFailureNoticeIsAbsentForSuccessfulRuntimeSelection(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$broker->registerCandidate(
			array(
				'broker_protocol' => 1,
				'package_version' => '99.0.0-dev',
				'php_floor'       => '8.2.0',
				'wordpress_floor' => '6.5',
				'path'            => __DIR__ . '/Support/TargetCaptureRuntime',
				'runtime_file'    => __DIR__ . '/Support/TargetCaptureRuntime/runtime.php',
			)
		);
		$facade = $factory(
			pluginFile: '/plugins/success/success.php',
			repository: 'owner/success',
			providerRepositoryId: '123456789'
		);
		$facade->register();
		WordPressState::doAction( 'plugins_loaded' );

		self::assertSame( 'runtime_selected', $facade->diagnostics()['code'] );
		self::assertSame( 0, WordPressState::hookCount( 'admin_notices' ) );
		self::assertSame( 0, WordPressState::hookCount( 'network_admin_notices' ) );
	}

	/**
	 * Runtime diagnostics are passive, bounded and allowlisted.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRuntimeDiagnosticsProviderIsPassiveAndRedacted(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$facade  = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);
		$facade->register();

		$registrationId = $this->firstRegistrationId( $broker );
		$calls          = 0;
		self::assertTrue(
			$broker->attachDiagnosticsProvider(
				$registrationId,
				static function () use ( &$calls ): array {
					++$calls;
					return array(
						'state'            => 'update_available',
						'code'             => 'offer_ready',
						'repository'       => 'owner/example',
						'channel'          => 'stable',
						'plugin'           => 'example/example.php',
						'offered_version'  => '1.2.3-alpha.1',
						'last_check'       => 1_000,
						'private_support'  => false,
						'signing_support'  => false,
						'absolute_path'    => '/private/tmp/secret.zip',
						'accessToken'      => 'secret-token',
						'message'          => 'secret-token',
						'unbounded_object' => new \stdClass(),
					);
				}
			)
		);
		self::assertSame( 0, $calls );

		$diagnostics = $facade->diagnostics();

		self::assertSame( 1, $calls );
		self::assertSame( 'update_available', $diagnostics['state'] );
		self::assertSame( 'offer_ready', $diagnostics['code'] );
		self::assertSame( 'owner/example', $diagnostics['repository'] );
		self::assertSame( 'example/example.php', $diagnostics['plugin'] );
		self::assertSame( '1.2.3-alpha.1', $diagnostics['offered_version'] );
		self::assertArrayNotHasKey( 'absolute_path', $diagnostics );
		self::assertArrayNotHasKey( 'accessToken', $diagnostics );
		self::assertArrayNotHasKey( 'message', $diagnostics );
		self::assertStringNotContainsString(
			'secret-token',
			(string) json_encode( $diagnostics )
		);
		self::assertFalse(
			$broker->attachDiagnosticsProvider(
				$registrationId,
				static fn (): array => array( 'state' => 'replaced' )
			)
		);
	}

	/**
	 * Candidate validation diagnostics retain only their bounded display verdict.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testCandidateValidationDiagnosticsAreBoundedAndAllowlisted(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$valid   = $factory(
			pluginFile: '/plugins/valid/valid.php',
			repository: 'owner/valid',
			providerRepositoryId: '123456789'
		);
		$unsafe  = $factory(
			pluginFile: '/plugins/unsafe/unsafe.php',
			repository: 'owner/unsafe',
			providerRepositoryId: '987654321'
		);
		$valid->register();
		$validRegistrationId = $this->firstRegistrationId( $broker );

		self::assertTrue(
			$broker->attachDiagnosticsProvider(
				$validRegistrationId,
				static fn (): array => array(
					'candidate_validation' => array(
						'state'                  => 'blocked',
						'code'                   => 'release_version_mismatch',
						'release_tag'            => 'v1.2.3',
						'release_version'        => '1.2.3',
						'package_header_version' => '1.2.2',
						'identity'               => array(
							'release_id'   => 42,
							'zip_asset_id' => 99,
							'sha256'       => 'secret-digest',
						),
						'package_url'            => 'https://example.test/secret.zip',
					),
				)
			)
		);

		$unsafe->register();
		$unsafeRegistrationId = array_key_last( $this->registrationIds( $broker ) );
		self::assertIsString( $unsafeRegistrationId );
		self::assertTrue(
			$broker->attachDiagnosticsProvider(
				$unsafeRegistrationId,
				static fn (): array => array(
					'candidate_validation' => array(
						'state'                  => 'blocked',
						'code'                   => 'release_version_mismatch',
						'release_tag'            => "v1.2.3\nsecret",
						'release_version'        => '1.2.3',
						'package_header_version' => null,
					),
				)
			)
		);

		$validDiagnostics = $valid->diagnostics();
		self::assertSame(
			array(
				'state'                  => 'blocked',
				'code'                   => 'release_version_mismatch',
				'release_tag'            => 'v1.2.3',
				'release_version'        => '1.2.3',
				'package_header_version' => '1.2.2',
			),
			$validDiagnostics['candidate_validation']
		);
		self::assertStringNotContainsString( 'secret-digest', (string) json_encode( $validDiagnostics ) );
		self::assertArrayNotHasKey( 'candidate_validation', $unsafe->diagnostics() );
	}

	/**
	 * Prerelease verdicts use the same bounded diagnostics projection as stable releases.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testCandidateValidationDiagnosticsRetainCanonicalPrereleaseVersions(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$facade  = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);
		$facade->register();
		$registrationId = $this->firstRegistrationId( $broker );
		$releaseVersion = '1.2.3-beta.2';

		self::assertTrue(
			$broker->attachDiagnosticsProvider(
				$registrationId,
				static function () use ( &$releaseVersion ): array {
					return array(
						'candidate_validation' => array(
							'state'                  => 'ready',
							'code'                   => 'release_identity_verified',
							'release_tag'            => 'v1.2.3-beta.2',
							'release_version'        => $releaseVersion,
							'package_header_version' => '1.2.3-beta.2',
						),
					);
				}
			)
		);

		self::assertSame(
			'1.2.3-beta.2',
			$facade->diagnostics()['candidate_validation']['release_version']
		);

		$releaseVersion = '1..2';
		self::assertArrayNotHasKey( 'candidate_validation', $facade->diagnostics() );
	}

	/**
	 * Provider exceptions cannot escape through passive diagnostics.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testRuntimeDiagnosticsProviderFailureIsContained(): void {
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$broker  = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
		$facade  = $factory(
			pluginFile: '/plugins/example/example.php',
			repository: 'owner/example',
			providerRepositoryId: '123456789'
		);
		$facade->register();

		self::assertTrue(
			$broker->attachDiagnosticsProvider(
				$this->firstRegistrationId( $broker ),
				static function (): array {
					throw new \RuntimeException( 'Do not expose this exception.' );
				}
			)
		);

		$diagnostics = $facade->diagnostics();
		self::assertSame( 'inactive', $diagnostics['state'] );
		self::assertSame( 'diagnostics_provider_failed', $diagnostics['code'] );
		self::assertStringNotContainsString(
			'Do not expose',
			(string) json_encode( $diagnostics )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testFacadeRefreshClearsCachesWithoutRemoteWork(): void {
		$plugin_file                                     = dirname( __DIR__ ) . '/fixtures/dummy-plugin/dummy-plugin.php';
		WordPressState::$pluginBasenames[ $plugin_file ] = 'booster-fixture-plugin/booster-fixture-plugin.php';
		WordPressState::$pluginData[ $plugin_file ]      = array(
			'Name'        => 'Booster Fixture Plugin',
			'Version'     => '0.1.0',
			'UpdateURI'   => 'https://github.com/RocketsAreNostalgic/booster-fixture-plugin',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.2',
		);
		$factory = require dirname( __DIR__ ) . '/bootstrap.php';
		$facade  = $factory(
			pluginFile: $plugin_file,
			repository: 'RocketsAreNostalgic/booster-fixture-plugin',
			providerRepositoryId: '123456789',
			pluginSlug: 'booster-fixture-plugin'
		);
		$facade->register();
		WordPressState::doAction( 'plugins_loaded' );
		WordPressState::$siteTransients['update_plugins'] = array( 'checked' => true );

		self::assertTrue( $facade->refresh() );
		self::assertArrayNotHasKey( 'update_plugins', WordPressState::$siteTransients );
		self::assertSame( array(), WordPressState::$httpRequests );
	}

	/**
	 * Read the single registration ID without adding a production accessor.
	 *
	 * @param object $broker Request-local anonymous broker.
	 */
	private function firstRegistrationId( object $broker ): string {
		$registrationIds = $this->registrationIds( $broker );
		$registrationId  = array_key_first( $registrationIds );

		self::assertIsString( $registrationId );
		return $registrationId;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function registrationIds( object $broker ): array {
		$reflection = new \ReflectionObject( $broker );
		$property   = $reflection->getProperty( 'targets' );
		$targets    = $property->getValue( $broker );

		self::assertIsArray( $targets );
		return $targets;
	}
}
