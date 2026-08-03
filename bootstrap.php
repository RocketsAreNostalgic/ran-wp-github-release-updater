<?php
/**
 * Request-local bootstrap for the RAN WordPress GitHub Release Updater.
 *
 * A consuming plugin explicitly requires this file. No production class is
 * autoloaded until WordPress has loaded all active plugin main files and the
 * request-local broker has selected one compatible package candidate.
 *
 * @package RAN_WP_GitHub_Release_Updater
 */

declare(strict_types=1);

// Public named arguments and the guarded broker protocol intentionally use
// the documented camelCase compatibility contract.
// phpcs:disable WordPress.NamingConventions.ValidVariableName
// phpcs:disable WordPress.NamingConventions.ValidFunctionName

$ran_wp_github_release_updater_candidate = array(
	'broker_protocol' => 1,
	'package_version' => '2.0.0-beta.1', // x-release-please-version
	'php_floor'       => '8.2.0',
	'wordpress_floor' => '6.5',
	'path'            => __DIR__,
	'runtime_file'    => __DIR__ . '/runtime.php',
);

$ran_wp_github_release_updater_existing_broker      =
	$GLOBALS['ran_wp_github_release_updater_v1_broker'] ?? null;
$ran_wp_github_release_updater_broker_methods       = array(
	'protocolVersion',
	'registerCandidate',
	'allocateRegistrationId',
	'registerTarget',
	'attachDiagnosticsProvider',
	'diagnostics',
);
$ran_wp_github_release_updater_broker_is_compatible =
	is_object( $ran_wp_github_release_updater_existing_broker );

foreach ( $ran_wp_github_release_updater_broker_methods as $ran_wp_github_release_updater_broker_method ) {
	if (
		! $ran_wp_github_release_updater_broker_is_compatible
		|| ! is_callable(
			array(
				$ran_wp_github_release_updater_existing_broker,
				$ran_wp_github_release_updater_broker_method,
			)
		)
	) {
		$ran_wp_github_release_updater_broker_is_compatible = false;
		break;
	}
}

if ( $ran_wp_github_release_updater_broker_is_compatible ) {
	try {
		$ran_wp_github_release_updater_protocol_callback    = array(
			$ran_wp_github_release_updater_existing_broker,
			'protocolVersion',
		);
		$ran_wp_github_release_updater_broker_is_compatible =
			is_callable( $ran_wp_github_release_updater_protocol_callback )
			&& 1 === $ran_wp_github_release_updater_protocol_callback();
	} catch ( Throwable ) {
		$ran_wp_github_release_updater_broker_is_compatible = false;
	}
}

if ( ! $ran_wp_github_release_updater_broker_is_compatible ) {
	$GLOBALS['ran_wp_github_release_updater_v1_broker'] = new class() {
		/**
		 * Registered package candidates, keyed by request-local ID.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private array $candidates = array();

		/**
		 * Candidate origin for every allocated target registration.
		 *
		 * @var array<string, string>
		 */
		private array $registrationOrigins = array();

		/**
		 * Registered updater targets, keyed by opaque registration ID.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private array $targets = array();

		/**
		 * Per-target passive diagnostics.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		private array $targetDiagnostics = array();

		/**
		 * Passive runtime diagnostic providers, keyed by registration ID.
		 *
		 * @var array<string, callable(): array<string, mixed>>
		 */
		private array $diagnosticsProviders = array();

		/**
		 * Whether candidate selection has finished for this request.
		 *
		 * @var bool
		 */
		private bool $selectionFixed = false;

		/**
		 * Whether the broker was first loaded after plugins_loaded.
		 *
		 * @var bool
		 */
		private bool $loadedLate = false;

		/**
		 * Selected package version, when a runtime was loaded.
		 *
		 * @var string|null
		 */
		private ?string $selectedVersion = null;

		/**
		 * Additional compatible copies sharing the selected version.
		 *
		 * @var int
		 */
		private int $duplicateVersionCount = 0;

		/**
		 * Structurally rejected candidate declarations.
		 *
		 * @var int
		 */
		private int $rejectedCandidateCount = 0;

		/**
		 * Candidate and target counters used only for opaque request-local IDs.
		 *
		 * @var int
		 */
		private int $candidateSequence = 0;

		/**
		 * Target counter.
		 *
		 * @var int
		 */
		private int $targetSequence = 0;

		/**
		 * Create the request-local broker and defer selection.
		 */
		public function __construct() {
			$this->loadedLate = function_exists( 'did_action' )
				&& did_action( 'plugins_loaded' ) > 0;

			if ( $this->loadedLate ) {
				$this->selectionFixed = true;
				return;
			}

			if ( function_exists( 'add_action' ) ) {
				add_action( 'plugins_loaded', array( $this, 'selectAndBoot' ), PHP_INT_MIN, 0 );
			}
		}

		/**
		 * Return the guarded broker protocol implemented by this object.
		 */
		public function protocolVersion(): int {
			return 1;
		}

		/**
		 * Register one physical package copy as a runtime candidate.
		 *
		 * Unknown candidate fields are retained for a later compatible broker.
		 *
		 * @param array<string, mixed> $candidate Candidate declaration.
		 * @return string Opaque request-local candidate ID.
		 */
		public function registerCandidate( array $candidate ): string {
			$candidateId = 'candidate-' . ++$this->candidateSequence;

			if ( $this->selectionFixed ) {
				return $candidateId;
			}

			$candidate = $this->normalizeCandidate( $candidate );
			if ( null === $candidate ) {
				++$this->rejectedCandidateCount;
				return $candidateId;
			}

			$candidate['candidate_id']        = $candidateId;
			$this->candidates[ $candidateId ] = $candidate;

			return $candidateId;
		}

		/**
		 * Allocate an opaque target registration ID for one facade.
		 *
		 * @param string $candidateId Candidate that created the facade.
		 * @return string Opaque request-local registration ID.
		 */
		public function allocateRegistrationId( string $candidateId ): string {
			$registrationId                               = $candidateId . '-target-' . ++$this->targetSequence;
			$this->registrationOrigins[ $registrationId ] = $candidateId;
			return $registrationId;
		}

		/**
		 * Submit one plain target record before runtime selection.
		 *
		 * @param array<string, mixed> $target Plain target record.
		 * @return bool Whether the target joined this request.
		 */
		public function registerTarget( array $target ): bool {
			$registrationId = $target['registrationId'] ?? null;

			if ( ! is_string( $registrationId ) || '' === $registrationId ) {
				return false;
			}

			if ( isset( $this->targets[ $registrationId ] ) ) {
				return true;
			}

			if ( $this->selectionFixed ) {
				$this->targetDiagnostics[ $registrationId ] = array(
					'code'  => 'late_registration',
					'state' => 'inactive',
				);

				return false;
			}

			$candidateId = $this->registrationOrigins[ $registrationId ] ?? null;
			$candidate   = is_string( $candidateId )
				? ( $this->candidates[ $candidateId ] ?? null )
				: null;
			if ( ! is_array( $candidate ) ) {
				$this->targetDiagnostics[ $registrationId ] = array(
					'code'  => 'invalid_origin_candidate',
					'state' => 'inactive',
				);
				return false;
			}

			$target['originCandidateId']                = $candidateId;
			$target['minimumRuntimeVersion']            = $candidate['package_version'];
			$this->targets[ $registrationId ]           = $target;
			$this->targetDiagnostics[ $registrationId ] = array(
				'code'  => 'awaiting_runtime',
				'state' => 'registered',
			);

			return true;
		}

		/**
		 * Attach one passive runtime diagnostic provider to a known target.
		 *
		 * @param string   $registrationId Opaque target registration ID.
		 * @param callable $provider Passive provider invoked by diagnostics().
		 */
		public function attachDiagnosticsProvider(
			string $registrationId,
			callable $provider
		): bool {
			if (
				! isset( $this->targets[ $registrationId ] )
				|| isset( $this->diagnosticsProviders[ $registrationId ] )
			) {
				return false;
			}

			$this->diagnosticsProviders[ $registrationId ] = $provider;
			return true;
		}

		/**
		 * Return bounded request-local diagnostics without doing work.
		 *
		 * @param string $registrationId Facade registration ID.
		 * @param bool   $facadeRegistered Whether register() was called.
		 * @return array<string, mixed> Safe passive diagnostics.
		 */
		public function diagnostics( string $registrationId, bool $facadeRegistered ): array {
			$targetState = $this->targetDiagnostics[ $registrationId ] ?? array(
				'code'  => $this->loadedLate ? 'late_bootstrap' : 'not_registered',
				'state' => 'inactive',
			);

			$diagnostics = array(
				'registered'               => $facadeRegistered,
				'state'                    => $targetState['state'],
				'code'                     => $targetState['code'],
				'selection_fixed'          => $this->selectionFixed,
				'selected_version'         => $this->selectedVersion,
				'candidate_count'          => count( $this->candidates ),
				'duplicate_version_count'  => $this->duplicateVersionCount,
				'rejected_candidate_count' => $this->rejectedCandidateCount,
			);

			$minimumRuntimeVersion =
				$this->targets[ $registrationId ]['minimumRuntimeVersion'] ?? null;
			if ( is_string( $minimumRuntimeVersion ) ) {
				$diagnostics['minimum_runtime_version'] = $minimumRuntimeVersion;
			}

			$provider = $this->diagnosticsProviders[ $registrationId ] ?? null;
			if ( ! is_callable( $provider ) ) {
				return $diagnostics;
			}

			try {
				$providerDiagnostics = $provider();
				if ( ! is_array( $providerDiagnostics ) ) {
					return $diagnostics;
				}

				return array_merge(
					$diagnostics,
					$this->sanitizeProviderDiagnostics( $providerDiagnostics )
				);
			} catch ( Throwable ) {
				$diagnostics['state'] = 'inactive';
				$diagnostics['code']  = 'diagnostics_provider_failed';
				return $diagnostics;
			}
		}

		/**
		 * Select one compatible runtime and synchronously hand it all targets.
		 *
		 * This is public only because WordPress invokes it as an action.
		 */
		public function selectAndBoot(): void {
			if ( $this->selectionFixed ) {
				return;
			}

			$this->selectionFixed = true;
			$candidate            = $this->selectCandidate();

			if ( null === $candidate ) {
				$this->markTargets( 'no_compatible_runtime', 'inactive' );
				return;
			}

			$this->selectedVersion = $candidate['package_version'];
			$runtimeTargets        = array();
			$runtimeTargetIds      = array();

			foreach ( $this->targets as $registrationId => $target ) {
				$minimumRuntimeVersion = $target['minimumRuntimeVersion'] ?? null;
				if (
					! is_string( $minimumRuntimeVersion )
					|| self::compareSemanticVersions(
						(string) $candidate['package_version'],
						$minimumRuntimeVersion
					) < 0
				) {
					$this->targetDiagnostics[ $registrationId ] = array(
						'code'  => 'runtime_minimum_unsatisfied',
						'state' => 'inactive',
					);
					continue;
				}
				$runtimeTargets[]   = $target;
				$runtimeTargetIds[] = $registrationId;
			}

			if ( array() === $runtimeTargets ) {
				return;
			}

			try {
				$runtimeFile = $candidate['runtime_file'];
				$entrypoint  = require $runtimeFile;

				if ( ! is_callable( $entrypoint ) ) {
					$this->markTargetIds(
						$runtimeTargetIds,
						'invalid_runtime_entrypoint',
						'inactive'
					);
					return;
				}

				$entrypoint( $runtimeTargets );
				$this->markTargetIds( $runtimeTargetIds, 'runtime_selected', 'active' );
			} catch ( Throwable ) {
				$this->selectedVersion = null;
				$this->markTargetIds( $runtimeTargetIds, 'runtime_load_failed', 'inactive' );
			}
		}

		/**
		 * Normalize one structurally safe candidate declaration.
		 *
		 * @param array<string, mixed> $candidate Candidate declaration.
		 * @return array<string, mixed>|null
		 */
		private function normalizeCandidate( array $candidate ): ?array {
			$packageVersion = $candidate['package_version'] ?? null;
			$phpFloor       = $candidate['php_floor'] ?? null;
			$wordpressFloor = $candidate['wordpress_floor'] ?? null;
			$path           = $candidate['path'] ?? null;
			$runtimeFile    = $candidate['runtime_file'] ?? null;

			if (
				1 !== ( $candidate['broker_protocol'] ?? null )
				|| ! is_string( $packageVersion )
				|| strlen( $packageVersion ) > 64
				|| 1 !== preg_match(
					'/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-(?:(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/',
					$packageVersion
				)
				|| ! is_string( $phpFloor )
				|| strlen( $phpFloor ) > 32
				|| 1 !== preg_match( '/^[0-9]+(?:\.[0-9]+){1,3}$/', $phpFloor )
				|| ! is_string( $wordpressFloor )
				|| strlen( $wordpressFloor ) > 32
				|| 1 !== preg_match( '/^[0-9]+(?:\.[0-9]+){1,3}$/', $wordpressFloor )
				|| ! is_string( $path )
				|| ! is_string( $runtimeFile )
			) {
				return null;
			}

			$normalizedPath        = realpath( $path );
			$normalizedRuntimeFile = realpath( $runtimeFile );
			if (
				false === $normalizedPath
				|| false === $normalizedRuntimeFile
				|| ! is_dir( $normalizedPath )
				|| ! is_readable( $normalizedPath )
				|| ! is_file( $normalizedRuntimeFile )
				|| ! is_readable( $normalizedRuntimeFile )
			) {
				return null;
			}

			$normalizedPath        = rtrim(
				str_replace( '\\', '/', $normalizedPath ),
				'/'
			);
			$normalizedRuntimeFile = str_replace(
				'\\',
				'/',
				$normalizedRuntimeFile
			);
			if (
				'' === $normalizedPath
				|| 'runtime.php' !== basename( $normalizedRuntimeFile )
				|| dirname( $normalizedRuntimeFile ) !== $normalizedPath
				|| ! str_starts_with(
					$normalizedRuntimeFile,
					$normalizedPath . '/'
				)
			) {
				return null;
			}

			$candidate['path']         = $normalizedPath;
			$candidate['runtime_file'] = $normalizedRuntimeFile;

			return $candidate;
		}

		/**
		 * Revalidate a registered candidate immediately before selection.
		 *
		 * @param array<string, mixed> $candidate Registered candidate.
		 */
		private function isCandidateAvailable( array $candidate ): bool {
			$path        = $candidate['path'] ?? null;
			$runtimeFile = $candidate['runtime_file'] ?? null;
			if ( ! is_string( $path ) || ! is_string( $runtimeFile ) ) {
				return false;
			}

			$currentPath        = realpath( $path );
			$currentRuntimeFile = realpath( $runtimeFile );

			return false !== $currentPath
				&& false !== $currentRuntimeFile
				&& str_replace( '\\', '/', $currentPath ) === $path
				&& str_replace( '\\', '/', $currentRuntimeFile ) === $runtimeFile
				&& is_readable( $currentRuntimeFile );
		}

		/**
		 * Select the highest compatible candidate deterministically.
		 *
		 * V1 retains one broker protocol. Version/path comparison makes
		 * mixed copies load-order independent and same-version copies
		 * deterministic without loading more than one runtime.
		 *
		 * @return array<string, mixed>|null
		 */
		private function selectCandidate(): ?array {
			$compatible = array_filter(
				$this->candidates,
				array( $this, 'isCandidateCompatible' )
			);

			if ( array() === $compatible ) {
				return null;
			}

			usort(
				$compatible,
				static function ( array $left, array $right ): int {
					$versionComparison = self::compareSemanticVersions(
						(string) $right['package_version'],
						(string) $left['package_version']
					);

					if ( 0 !== $versionComparison ) {
						return $versionComparison;
					}

					return strcmp(
						(string) $left['path'],
						(string) $right['path']
					);
				}
			);

			$selected = $compatible[0];
			$version  = (string) $selected['package_version'];

			$this->duplicateVersionCount = max(
				0,
				count(
					array_filter(
						$compatible,
						static fn ( array $candidate ): bool =>
							0 === self::compareSemanticVersions(
								$version,
								(string) $candidate['package_version']
							)
					)
				) - 1
			);

			return $selected;
		}

		/**
		 * Compare strict semantic versions while ignoring build metadata.
		 */
		private static function compareSemanticVersions(
			string $left,
			string $right
		): int {
			$leftBuild  = strpos( $left, '+' );
			$rightBuild = strpos( $right, '+' );
			$left       = false === $leftBuild ? $left : substr( $left, 0, $leftBuild );
			$right      = false === $rightBuild ? $right : substr( $right, 0, $rightBuild );

			list( $leftCore, $leftPrerelease )   = array_pad(
				explode( '-', $left, 2 ),
				2,
				null
			);
			list( $rightCore, $rightPrerelease ) = array_pad(
				explode( '-', $right, 2 ),
				2,
				null
			);

			$leftCoreParts  = explode( '.', $leftCore );
			$rightCoreParts = explode( '.', $rightCore );
			foreach ( array_keys( $leftCoreParts ) as $index ) {
				$coreComparison = self::compareNumericStrings(
					$leftCoreParts[ $index ],
					$rightCoreParts[ $index ]
				);
				if ( 0 !== $coreComparison ) {
					return $coreComparison;
				}
			}

			if ( null === $leftPrerelease || null === $rightPrerelease ) {
				return ( null === $leftPrerelease )
					<=> ( null === $rightPrerelease );
			}

			$leftIdentifiers  = explode( '.', $leftPrerelease );
			$rightIdentifiers = explode( '.', $rightPrerelease );
			$identifierCount  = min(
				count( $leftIdentifiers ),
				count( $rightIdentifiers )
			);

			for ( $index = 0; $index < $identifierCount; ++$index ) {
				$leftIdentifier  = $leftIdentifiers[ $index ];
				$rightIdentifier = $rightIdentifiers[ $index ];
				$leftNumeric     = ctype_digit( $leftIdentifier );
				$rightNumeric    = ctype_digit( $rightIdentifier );

				if ( $leftNumeric && $rightNumeric ) {
					$numericComparison = self::compareNumericStrings(
						$leftIdentifier,
						$rightIdentifier
					);
					if ( 0 !== $numericComparison ) {
						return $numericComparison;
					}
					continue;
				}

				if ( $leftNumeric !== $rightNumeric ) {
					return $leftNumeric ? -1 : 1;
				}

				$identifierComparison = strcmp(
					$leftIdentifier,
					$rightIdentifier
				);
				if ( 0 !== $identifierComparison ) {
					return $identifierComparison;
				}
			}

			return count( $leftIdentifiers ) <=> count( $rightIdentifiers );
		}

		/**
		 * Compare non-negative integer strings without platform integer limits.
		 */
		private static function compareNumericStrings(
			string $left,
			string $right
		): int {
			$lengthComparison = strlen( $left ) <=> strlen( $right );
			return 0 === $lengthComparison
				? strcmp( $left, $right )
				: $lengthComparison;
		}

		/**
		 * Test PHP and WordPress compatibility for one candidate.
		 *
		 * @param array<string, mixed> $candidate Candidate declaration.
		 */
		private function isCandidateCompatible( array $candidate ): bool {
			if ( ! $this->isCandidateAvailable( $candidate ) ) {
				return false;
			}

			if ( version_compare( PHP_VERSION, (string) $candidate['php_floor'], '<' ) ) {
				return false;
			}

			$wpVersion = $GLOBALS['wp_version'] ?? null;

			return ! is_string( $wpVersion )
				|| version_compare( $wpVersion, (string) $candidate['wordpress_floor'], '>=' );
		}

		/**
		 * Set one bounded diagnostic for every registered target.
		 *
		 * @param string $code Diagnostic code.
		 * @param string $state Target state.
		 */
		private function markTargets( string $code, string $state ): void {
			$this->markTargetIds( array_keys( $this->targets ), $code, $state );
		}

		/**
		 * Set one bounded diagnostic for selected target IDs.
		 *
		 * @param list<string> $registrationIds Target registration IDs.
		 * @param string       $code Diagnostic code.
		 * @param string       $state Target state.
		 */
		private function markTargetIds(
			array $registrationIds,
			string $code,
			string $state
		): void {
			foreach ( $registrationIds as $registrationId ) {
				$this->targetDiagnostics[ $registrationId ] = array(
					'code'  => $code,
					'state' => $state,
				);
			}
		}

		/**
		 * Retain only bounded, path-free diagnostic fields from the runtime.
		 *
		 * @param array<string, mixed> $diagnostics Provider diagnostics.
		 * @return array<string, array<string, string|null>|bool|int|string|null>
		 */
		private function sanitizeProviderDiagnostics( array $diagnostics ): array {
			$safe = array();

			foreach ( array( 'code', 'state' ) as $field ) {
				$value = $diagnostics[ $field ] ?? null;
				if (
					is_string( $value )
					&& 1 === preg_match( '/^[a-z0-9_.:-]{1,80}$/', $value )
				) {
					$safe[ $field ] = $value;
				}
			}

			$repository = $diagnostics['repository'] ?? null;
			if (
				is_string( $repository )
				&& 1 === preg_match(
					'/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/',
					$repository
				)
			) {
				$safe['repository'] = $repository;
			}

			$channel = $diagnostics['channel'] ?? null;
			if ( 'stable' === $channel || 'prerelease' === $channel ) {
				$safe['channel'] = $channel;
			}

			$plugin = $diagnostics['plugin'] ?? null;
			if (
				is_string( $plugin )
				&& ! str_contains( $plugin, '..' )
				&& 1 === preg_match(
					'/^(?:[A-Za-z0-9_.-]{1,100}\/)?[A-Za-z0-9_.-]{1,100}\.php$/',
					$plugin
				)
			) {
				$safe['plugin'] = $plugin;
			}

			$type = $diagnostics['type'] ?? null;
			if ( 'plugin' === $type || 'theme' === $type ) {
				$safe['type'] = $type;
			}

			$package = $diagnostics['package'] ?? null;
			if (
				is_string( $package )
				&& ! str_contains( $package, '..' )
				&& 1 === preg_match(
					'/^(?:[A-Za-z0-9_.-]{1,100}\/)?[A-Za-z0-9_.-]{1,100}(?:\.php)?$/',
					$package
				)
			) {
				$safe['package'] = $package;
			}

			$offeredVersion = $diagnostics['offered_version'] ?? null;
			if (
				null === $offeredVersion
				|| (
					is_string( $offeredVersion )
					&& 1 === preg_match( '/^[0-9A-Za-z.+-]{1,64}$/', $offeredVersion )
				)
			) {
				$safe['offered_version'] = $offeredVersion;
			}

			$lastCheck = $diagnostics['last_check'] ?? null;
			if ( null === $lastCheck || ( is_int( $lastCheck ) && $lastCheck >= 0 ) ) {
				$safe['last_check'] = $lastCheck;
			}

			$nextCheck = $diagnostics['next_check'] ?? null;
			if ( null === $nextCheck || ( is_int( $nextCheck ) && $nextCheck >= 0 ) ) {
				$safe['next_check'] = $nextCheck;
			}

			$installedVersion = $diagnostics['installed_version'] ?? null;
			if (
				is_string( $installedVersion )
				&& 1 === preg_match( '/^[0-9A-Za-z.+-]{1,64}$/', $installedVersion )
			) {
				$safe['installed_version'] = $installedVersion;
			}

			$candidateValidation = $diagnostics['candidate_validation'] ?? null;
			if (
				is_array( $candidateValidation )
				&& in_array( $candidateValidation['state'] ?? null, array( 'ready', 'blocked' ), true )
				&& is_string( $candidateValidation['code'] ?? null )
				&& 1 === preg_match( '/\A[a-z_]{1,80}\z/D', $candidateValidation['code'] )
				&& is_string( $candidateValidation['release_tag'] ?? null )
				&& strlen( $candidateValidation['release_tag'] ) <= 100
				&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $candidateValidation['release_tag'] )
				&& is_string( $candidateValidation['release_version'] ?? null )
				&& 1 === preg_match(
					'/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'
						. '(?:-(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)'
						. '(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*)?\z/D',
					$candidateValidation['release_version']
				)
				&& array_key_exists( 'package_header_version', $candidateValidation )
				&& (
					null === $candidateValidation['package_header_version']
					|| (
						is_string( $candidateValidation['package_header_version'] )
						&& strlen( $candidateValidation['package_header_version'] ) <= 32
						&& 1 !== preg_match(
							'/[\x00-\x1F\x7F]/',
							$candidateValidation['package_header_version']
						)
					)
				)
			) {
				$safe['candidate_validation'] = array(
					'state'                  => $candidateValidation['state'],
					'code'                   => $candidateValidation['code'],
					'release_tag'            => $candidateValidation['release_tag'],
					'release_version'        => $candidateValidation['release_version'],
					'package_header_version' => $candidateValidation['package_header_version'],
				);
			}

			foreach (
				array(
					'authentication_configured',
					'signing_configured',
					'private_support',
					'signing_support',
				) as $field
			) {
				if ( is_bool( $diagnostics[ $field ] ?? null ) ) {
					$safe[ $field ] = $diagnostics[ $field ];
				}
			}

			return $safe;
		}
	};
}

/**
 * Report whether this request has accepted a RAN updater registration for one
 * canonical WordPress update target.
 *
 * This intentionally exposes no target configuration, credentials or runtime
 * diagnostics. Optional integrations can use it to avoid registering a
 * second RAN updater for the exact same installed package.
 *
 * Plugin identifiers are the value returned by plugin_basename(), such as
 * "example-plugin/example-plugin.php". Theme identifiers are WordPress
 * stylesheet slugs, such as "example-theme".
 *
 * @param string $targetType "plugin" or "theme".
 * @param string $identifier Canonical WordPress package identifier.
 */
if ( ! function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' ) ) {
	function ran_wp_github_release_updater_v1_has_registered_target(
		string $targetType,
		string $identifier
	): bool {
		if ( ! in_array( $targetType, array( 'plugin', 'theme' ), true ) ) {
			return false;
		}

		$identifier = strtolower( str_replace( '\\', '/', $identifier ) );
		if ( '' === $identifier ) {
			return false;
		}

		$registrations =
			$GLOBALS['ran_wp_github_release_updater_v1_target_registrations'] ?? null;

		return is_array( $registrations )
			&& true === ( $registrations[ $targetType . ':' . $identifier ] ?? false );
	}
}

if (
	! isset( $GLOBALS['ran_wp_github_release_updater_v1_target_registrations'] )
	|| ! is_array( $GLOBALS['ran_wp_github_release_updater_v1_target_registrations'] )
) {
	$GLOBALS['ran_wp_github_release_updater_v1_target_registrations'] = array();
}

$ran_wp_github_release_updater_canonical_plugin_identifier = static function (
	string $pluginFile
): string {
	if ( function_exists( 'plugin_basename' ) ) {
		return plugin_basename( $pluginFile );
	}

	$normalizedFile = str_replace( '\\', '/', $pluginFile );
	$pluginRoots    = array();
	foreach ( array( 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR' ) as $constant ) {
		if ( defined( $constant ) && is_string( constant( $constant ) ) ) {
			$pluginRoots[] = constant( $constant );
		}
	}
	if ( defined( 'ABSPATH' ) && is_string( ABSPATH ) ) {
		$pluginRoots[] = rtrim( ABSPATH, '/\\' ) . '/wp-content/plugins';
	}

	foreach ( $pluginRoots as $pluginRoot ) {
		$normalizedRoot = rtrim( str_replace( '\\', '/', $pluginRoot ), '/' ) . '/';
		if ( str_starts_with( $normalizedFile, $normalizedRoot ) ) {
			return ltrim(
				substr( $normalizedFile, strlen( $normalizedRoot ) ),
				'/'
			);
		}
	}

	$standardPluginMarker = '/wp-content/plugins/';
	$markerPosition       = strpos( $normalizedFile, $standardPluginMarker );
	if ( false !== $markerPosition ) {
		return substr(
			$normalizedFile,
			$markerPosition + strlen( $standardPluginMarker )
		);
	}

	$directory = basename( dirname( $normalizedFile ) );
	$filename  = basename( $normalizedFile );

	return '' === $directory || '.' === $directory
		? $filename
		: $directory . '/' . $filename;
};

$ran_wp_github_release_updater_target_identity = static function (
	array $target
) use ( $ran_wp_github_release_updater_canonical_plugin_identifier ): ?string {
	$targetType = $target['targetType'] ?? 'plugin';
	$pluginFile = $target['pluginFile'] ?? null;

	if (
		! in_array( $targetType, array( 'plugin', 'theme' ), true )
		|| ! is_string( $pluginFile )
		|| '' === $pluginFile
	) {
		return null;
	}

	$identifier = 'plugin' === $targetType
		? $ran_wp_github_release_updater_canonical_plugin_identifier( $pluginFile )
		: (
			is_string( $target['stylesheet'] ?? null )
				? $target['stylesheet']
				: basename( dirname( $pluginFile ) )
		);
	$identifier = strtolower( str_replace( '\\', '/', $identifier ) );

	return '' === $identifier ? null : $targetType . ':' . $identifier;
};

$ran_wp_github_release_updater_broker             = $GLOBALS['ran_wp_github_release_updater_v1_broker'];
$ran_wp_github_release_updater_register_candidate = array(
	$ran_wp_github_release_updater_broker,
	'registerCandidate',
);
$ran_wp_github_release_updater_candidate_id       =
	is_callable( $ran_wp_github_release_updater_register_candidate )
		? $ran_wp_github_release_updater_register_candidate(
			$ran_wp_github_release_updater_candidate
		)
		: 'incompatible-broker';
return static function (
	string $pluginFile,
	string $repository,
	string $providerRepositoryId,
	?string $pluginSlug = null,
	string $channel = 'stable',
	string|callable|null $accessToken = null,
	string $autoUpdatePolicy = 'site-controlled',
	int $cacheDuration = 21_600,
	int $failureCacheDuration = 900,
	bool $nativeDiscovery = true,
	mixed ...$additionalOptions
) use (
	$ran_wp_github_release_updater_broker,
	$ran_wp_github_release_updater_candidate_id,
	$ran_wp_github_release_updater_target_identity
): object {
	$repositorySeparator = strrpos( $repository, '/' );
	$repositoryName      = false === $repositorySeparator
		? $repository
		: substr( $repository, $repositorySeparator + 1 );
	$resolvedPluginSlug  = $pluginSlug ?? $repositoryName;

	$target                   = array_merge(
		$additionalOptions,
		array(
			'pluginFile'           => $pluginFile,
			'repository'           => $repository,
			'providerRepositoryId' => $providerRepositoryId,
			'pluginSlug'           => $resolvedPluginSlug,
			'channel'              => $channel,
			'accessToken'          => $accessToken,
			'autoUpdatePolicy'     => $autoUpdatePolicy,
			'cacheDuration'        => $cacheDuration,
			'failureCacheDuration' => $failureCacheDuration,
			'nativeDiscovery'      => $nativeDiscovery,
		)
	);
	$allocateRegistrationId   = array(
		$ran_wp_github_release_updater_broker,
		'allocateRegistrationId',
	);
	$registrationId           = is_callable( $allocateRegistrationId )
		? $allocateRegistrationId(
			(string) $ran_wp_github_release_updater_candidate_id
		)
		: 'incompatible-broker-target';
	$target['registrationId'] = $registrationId;
	$controlCell              = new class() {
		/** @var (\Closure(): bool)|null */
		private ?Closure $refresh = null;

		public function attach( callable $refresh ): bool {
			if ( null !== $this->refresh ) {
				return false;
			}
			$this->refresh = Closure::fromCallable( $refresh );
			return true;
		}

		public function refresh(): bool {
			if ( null === $this->refresh ) {
				return false;
			}
			try {
				return true === ( $this->refresh )();
			} catch ( Throwable ) {
				return false;
			}
		}
	};
	$target['controlCell']    = $controlCell;

	return new class(
		$ran_wp_github_release_updater_broker,
		$registrationId,
		$target,
		$controlCell,
		$ran_wp_github_release_updater_target_identity
	) {
		/**
		 * Whether this facade has submitted its target.
		 *
		 * @var bool
		 */
		private bool $registered = false;

		/**
		 * Create one candidate-bound target facade.
		 *
		 * @param object               $broker Request-local broker.
		 * @param string               $registrationId Opaque target ID.
		 * @param array<string, mixed> $target Plain target configuration.
		 */
		public function __construct(
			private object $broker,
			private string $registrationId,
			private array $target,
			private object $controlCell,
			private Closure $targetIdentity
		) {
		}

		/**
		 * Idempotently submit this target to the request-local broker.
		 */
		public function register(): void {
			if ( $this->registered ) {
				return;
			}

			$this->registered = true;
			$registerTarget   = array( $this->broker, 'registerTarget' );
			if ( is_callable( $registerTarget ) ) {
				$accepted = $registerTarget( $this->target );
				$identity = ( $this->targetIdentity )( $this->target );
				if ( true === $accepted && is_string( $identity ) ) {
					$GLOBALS['ran_wp_github_release_updater_v1_target_registrations'][ $identity ] = true;
				}
			}
		}

		/**
		 * Return bounded passive state without causing a remote request.
		 *
		 * @return array<string, mixed>
		 */
		public function diagnostics(): array {
			$diagnostics = array( $this->broker, 'diagnostics' );

			if ( ! is_callable( $diagnostics ) ) {
				return array(
					'registered' => $this->registered,
					'state'      => 'inactive',
					'code'       => 'incompatible_broker',
				);
			}

			return (array) $diagnostics(
				$this->registrationId,
				$this->registered
			);
		}

		/**
		 * Clear only this target's updater caches.
		 *
		 * The next normal WordPress update check performs discovery.
		 */
		public function refresh(): bool {
			$refresh = array( $this->controlCell, 'refresh' );
			if ( ! $this->registered || ! is_callable( $refresh ) ) {
				return false;
			}

			return true === $refresh();
		}
	};
};
