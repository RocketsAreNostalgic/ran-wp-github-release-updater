<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\AccessToken;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ClaimedArtifact;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ConditionalState;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseVersion;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;

/**
 * Maps one configured plugin or theme target onto WordPress Core's native
 * update seams.
 *
 * @phpstan-type Offer array{
 *     provider_repository_id: ?string,
 *     release_id: int,
 *     tag: string,
 *     version: string,
 *     commit: string,
 *     zip_asset_id: int,
 *     zip_name: string,
 *     immutable: bool,
 *     sha256: string,
 *     size: int,
 *     requires: ?string,
 *     requires_php: ?string,
 *     details_url: string,
 *     package_url: string,
 *     automatic_profile?: string,
 *     candidate_validation?: array{state: string, code: string, release_tag: string, release_version: string, package_header_version: ?string, identity: array{release_id: int, tag: string, zip_asset_id: int, sha256: string, package_type: string, header_file: string}}
 * }
 */
final class NativePluginUpdater {

	private const CACHE_SCHEMA = 8;

	private const PACKAGE_HEADER_BYTES = 8192;

	private const MAX_STAGED_METADATA_FILE_BYTES = 1048576;

	private bool $registered = false;

	private bool $noticeRendered = false;

	private ?string $pendingArchive = null;

	private ?ClaimedArtifact $pendingClaim = null;

	private ?ReleaseOperationClaim $pendingOperationClaim = null;

	private ?ReleaseOperationClaim $activeDiscoveryClaim = null;

	/** @var Offer|null */
	private ?array $pendingOffer = null;

	private ?string $pendingExpectedVersion = null;

	private bool $pendingCoreHandoff = false;

	private bool $pendingInstallResultCaptured = false;

	private mixed $pendingInstallResult = null;

	private bool $pendingCompletionObserved = false;

	private bool $pendingShutdownScheduled = false;

	private ReleaseCandidateSelector $candidates;

	private ReleaseOperationCoordinator $operations;

	/** @var callable(): int */
	private $clock;

	/** @var array<string, mixed> */
	private array $pluginData;

	/**
	 * @param array<string, mixed> $pluginData Header-derived plugin metadata.
	 * @param callable(): int|null $clock      Injectable clock for focused tests.
	 */
	private function __construct(
		private ReleaseArtifactClient $artifacts,
		private string $targetType,
		private string $pluginFile,
		private string $pluginBasename,
		private string $installedDirectory,
		private Repository $repository,
		private string $pluginSlug,
		private string $channel,
		private AccessToken $accessToken,
		private ReleaseAssurance $assurance,
		private string $autoUpdatePolicy,
		private int $cacheDuration,
		private int $failureCacheDuration,
		array $pluginData,
		?callable $clock = null
	) {
		$this->pluginData = $pluginData;
		$this->clock      = $clock ?? static fn (): int => time();
		$this->candidates = new ReleaseCandidateSelector( $artifacts );
		$this->operations = new ReleaseOperationCoordinator();
	}

	/**
	 * Construct a validated Beta 1 updater from broker target data.
	 *
	 * @param array<string, mixed> $target Plain bootstrap target record.
	 * @return self|\WP_Error
	 */
	public static function fromTarget(
		array $target,
		ReleaseArtifactClient $artifacts,
		?callable $clock = null,
		?ReleaseAssurance $assurance = null
	) {
		$targetType      = $target['targetType'] ?? 'plugin';
		$pluginFile      = $target['pluginFile'] ?? null;
		$repository      = $target['repository'] ?? null;
		$repositoryId    = $target['providerRepositoryId'] ?? null;
		$pluginSlug      = $target['pluginSlug'] ?? null;
		$channel         = $target['channel'] ?? null;
		$policy          = $target['autoUpdatePolicy'] ?? null;
		$cacheDuration   = $target['cacheDuration'] ?? null;
		$failureDuration = $target['failureCacheDuration'] ?? null;

		if ( ! in_array( $targetType, array( 'plugin', 'theme' ), true ) ) {
			return self::configurationError( 'target_type', 'The update target type is invalid.' );
		}
		if ( ! is_string( $pluginFile )
			|| strlen( $pluginFile ) > 4096
			|| 1 !== preg_match( '#\A(?:/|[A-Za-z]:[\\\\/])#D', $pluginFile )
			|| ! is_file( $pluginFile )
		) {
			return self::configurationError( 'plugin_file', 'The consuming plugin file is invalid.' );
		}
		if ( ! is_string( $repository ) ) {
			return self::configurationError( 'repository', 'The GitHub repository is invalid.' );
		}
		if ( ! is_string( $repositoryId ) ) {
			return self::configurationError(
				'repository_identity',
				'A numeric GitHub repository identity is required.'
			);
		}
		$repositoryValue = Repository::fromString( $repository, $repositoryId );
		if ( $repositoryValue instanceof \WP_Error ) {
			return $repositoryValue;
		}
		if ( ! is_string( $pluginSlug )
			|| 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $pluginSlug )
		) {
			return self::configurationError( 'plugin_slug', 'The canonical plugin slug is invalid.' );
		}
		if ( ! is_string( $channel )
			|| ! in_array( $channel, array( ReleaseQuery::STABLE, ReleaseQuery::PRERELEASE ), true )
		) {
			return self::configurationError( 'channel', 'The release channel is invalid.' );
		}
		$policy = self::normalizePolicy( $policy );
		if ( null === $policy ) {
			return self::configurationError( 'auto_update_policy', 'The automatic-update policy is invalid.' );
		}
		if ( ! is_int( $cacheDuration ) || $cacheDuration < 300 || $cacheDuration > 86400 ) {
			return self::configurationError( 'cache_duration', 'The successful discovery cache duration is invalid.' );
		}
		if ( ! is_int( $failureDuration )
			|| $failureDuration < 60
			|| $failureDuration > 3600
			|| $failureDuration > $cacheDuration
		) {
			return self::configurationError( 'failure_cache_duration', 'The failure cache duration is invalid.' );
		}
		$accessToken = AccessToken::fromValue( $target['accessToken'] ?? null );
		if ( $accessToken instanceof \WP_Error ) {
			return $accessToken;
		}

		$pluginData = self::readPackageData( $pluginFile, $targetType );
		if ( $pluginData instanceof \WP_Error ) {
			return $pluginData;
		}
		$expectedUpdateUri = PackageIdentityTarget::normalizeUpdateUri(
			'https://github.com/' . $repositoryValue->canonical()
		);
		$updateUri         = is_string( $pluginData['UpdateURI'] ?? null )
			? PackageIdentityTarget::normalizeUpdateUri( $pluginData['UpdateURI'] )
			: null;
		if ( null === $expectedUpdateUri || null === $updateUri
			|| ! hash_equals( $expectedUpdateUri, $updateUri )
		) {
			return self::configurationError(
				'update_uri',
				'The consuming package Update URI does not match its configured GitHub repository.'
			);
		}

		$pluginBasename = 'plugin' === $targetType
			? str_replace( '\\', '/', plugin_basename( $pluginFile ) )
			: ( $target['stylesheet'] ?? basename( dirname( $pluginFile ) ) );
		if ( ! is_string( $pluginBasename ) ) {
			return self::configurationError( 'target_identity', 'The installed package identity is invalid.' );
		}
		$installedDirectory = 'plugin' === $targetType
			? dirname( $pluginBasename )
			: $pluginBasename;
		if ( '.' === $installedDirectory
			|| 1 !== preg_match( '/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})\z/D', $installedDirectory )
		) {
			return self::configurationError(
				'installed_directory',
				'The installed package directory is invalid.'
			);
		}
		if ( 'theme' === $targetType && 'style.css' !== basename( $pluginFile ) ) {
			return self::configurationError(
				'theme_stylesheet',
				'The consuming theme file must be its style.css.'
			);
		}

		return new self(
			$artifacts,
			$targetType,
			$pluginFile,
			$pluginBasename,
			$installedDirectory,
			$repositoryValue,
			$pluginSlug,
			$channel,
			$accessToken,
			$assurance ?? ReleaseAssurance::selected(),
			$policy,
			$cacheDuration,
			$failureDuration,
			$pluginData,
			$clock
		);
	}

	/**
	 * Idempotently register only the documented native Core hooks.
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		add_filter(
			'plugin' === $this->targetType
				? 'update_plugins_github.com'
				: 'update_themes_github.com',
			array( $this, 'filterUpdate' ),
			10,
			4
		);
		if ( 'plugin' === $this->targetType ) {
			add_filter( 'plugins_api', array( $this, 'filterPluginInformation' ), 10, 3 );
		}
		add_filter(
			'plugin' === $this->targetType ? 'auto_update_plugin' : 'auto_update_theme',
			array( $this, 'filterAutoUpdate' ),
			10,
			2
		);
		add_filter(
			'upgrader_pre_download',
			array( $this, 'filterPreDownload' ),
			PHP_INT_MAX,
			4
		);
		add_filter(
			'upgrader_source_selection',
			array( $this, 'filterSourceSelection' ),
			PHP_INT_MAX,
			4
		);
		add_filter( 'upgrader_pre_install', array( $this, 'filterPreInstall' ), PHP_INT_MAX, 2 );
		add_filter(
			'upgrader_install_package_result',
			array( $this, 'captureInstallPackageResult' ),
			PHP_INT_MAX,
			2
		);
		add_action( 'upgrader_process_complete', array( $this, 'observeCompletion' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'renderAdminNotice' ) );
		add_action( 'network_admin_notices', array( $this, 'renderAdminNotice' ) );
		$this->registered = true;
		$this->debugLog( 'registered' );
	}

	/**
	 * Supply native update metadata for this exact plugin only.
	 *
	 * @param array<string, mixed>|false $update Existing host update.
	 * @param array<string, mixed>       $pluginData Current plugin headers.
	 * @param list<string>               $locales Requested locales.
	 * @return array<string, mixed>|false
	 */
	public function filterUpdate( $update, array $pluginData, string $pluginFile, array $locales ) {
		unset( $locales );
		if ( $this->pluginBasename !== $pluginFile ) {
			return $update;
		}

		$currentVersion = is_string( $pluginData['Version'] ?? null )
			? $pluginData['Version']
			: '';
		if ( null === ReleaseVersion::normalizeHeader( $currentVersion ) ) {
			$this->storeDiagnostic(
				'' === $currentVersion ? 'missing_installed_version' : 'invalid_installed_version',
				'inactive'
			);
			return $update;
		}

		$offer = $this->offer( $currentVersion );
		if ( null === $offer ) {
			return $update;
		}
		if ( ReleaseVersion::RELATIONSHIP_NEWER !== ReleaseVersion::relationship(
			$offer['version'],
			$currentVersion
		) ) {
			$this->storeCurrent( $offer );
			return $update;
		}
		if ( 'disabled' === $this->autoUpdatePolicy ) {
			$this->storeDiagnostic( 'release_available_disabled', 'ready' );
			return false;
		}

		$common = array(
			'id'           => 'https://github.com/' . $this->repository->canonical(),
			'slug'         => $this->pluginSlug,
			'url'          => $offer['details_url'],
			'package'      => $offer['package_url'],
			'requires'     => $offer['requires'],
			'requires_php' => $offer['requires_php'],
			'autoupdate'   => 'automatic' === $this->autoUpdatePolicy,
		);
		if ( 'theme' === $this->targetType ) {
			return array_merge(
				$common,
				array(
					'theme'   => $this->pluginBasename,
					'version' => $offer['version'],
				)
			);
		}

		return array_merge(
			$common,
			array(
				'plugin'  => $this->pluginBasename,
				'version' => $offer['version'],
			)
		);
	}

	/**
	 * Supply a lean native plugin-information response.
	 */
	public function filterPluginInformation( mixed $result, string $action, mixed $arguments ): mixed {
		if ( 'plugin_information' !== $action
			|| ! is_object( $arguments )
			|| ( $arguments->slug ?? null ) !== $this->pluginSlug
		) {
			return $result;
		}

		$offer  = $this->offer();
		$object = new \stdClass();

		$object->name          = $this->header( 'Name', $this->pluginSlug );
		$object->slug          = $this->pluginSlug;
		$object->version       = $offer['version'] ?? $this->header( 'Version', '' );
		$object->author        = $this->header( 'Author', '' );
		$object->homepage      = $this->header( 'PluginURI', 'https://github.com/' . $this->repository->canonical() );
		$object->requires      = $offer['requires'] ?? $this->header( 'RequiresWP', '' );
		$object->tested        = '';
		$object->requires_php  = $offer['requires_php'] ?? $this->header( 'RequiresPHP', '' );
		$object->download_link = $offer['package_url'] ?? '';
		$object->external      = true;
		$object->sections      = array(
			'description' => $this->header( 'Description', '' ),
			'changelog'   => null === $offer
				? ''
				: 'Release ' . $offer['version'] . ' is available from GitHub.',
		);

		return $object;
	}

	/**
	 * Preserve or narrowly override Core's automatic-update decision.
	 */
	public function filterAutoUpdate( ?bool $update, mixed $item ): ?bool {
		if ( ! is_object( $item ) ) {
			return $update;
		}
		$identity = 'plugin' === $this->targetType
			? ( $item->plugin ?? null )
			: ( $item->theme ?? null );
		if ( $identity !== $this->pluginBasename ) {
			return $update;
		}
		if ( in_array( $this->autoUpdatePolicy, array( 'disabled', 'forced-off' ), true ) ) {
			return false;
		}
		if ( 'automatic' === $this->autoUpdatePolicy || true === $update ) {
			$offer = $this->validatedOffer( $this->cachedState()['offer'] ?? null );
			return null !== $offer
				&& ReleaseAssurance::AUTOMATIC_PROFILE_REVISION === ( $offer['automatic_profile'] ?? null );
		}

		return $update;
	}

	/**
	 * Admit only the exact offered asset and return its verified local path.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 */
	public function filterPreDownload(
		mixed $reply,
		string $package,
		mixed $upgrader,
		array $hookExtra
	): mixed {
		unset( $upgrader );
		if ( ! $this->matchesHookExtra( $hookExtra ) ) {
			return $reply;
		}
		if ( $reply instanceof \WP_Error ) {
			return $reply;
		}
		if ( false !== $reply ) {
			$handoff = $this->claimCoreReinstallHandoff( $reply, $package, $hookExtra );
			if ( null !== $handoff ) {
				$blocked = $this->startPendingInstall( $package );
				if ( $blocked instanceof \WP_Error ) {
					$handoff['claim']->discard();
					return $blocked;
				}
				$this->schedulePendingFinalization();
				$this->pendingArchive         = $reply;
				$this->pendingClaim           = $handoff['claim'];
				$this->pendingExpectedVersion = $handoff['expected_version'];
				$this->pendingCoreHandoff     = true;
				add_filter(
					'pre_unzip_file',
					array( $this, 'filterPreUnzipFile' ),
					PHP_INT_MAX,
					5
				);
				$this->debugLog( 'core_reinstall_handoff_admitted' );

				return $reply;
			}

			return $this->downloadError(
				'github_updater_unverified_pre_download_result',
				'An earlier download handler returned an unverified package for this target.'
			);
		}

		$state = $this->cachedState();
		$offer = $this->validatedOffer( $state['offer'] ?? null );
		if ( null === $offer || ! hash_equals( $offer['package_url'], $package ) ) {
			return $this->downloadError(
				'github_updater_unverified_update',
				'The update package does not match the exact offered GitHub Release asset.'
			);
		}
		$blocked = $this->startPendingInstall( $package );
		if ( $blocked instanceof \WP_Error ) {
			return $blocked;
		}
		$this->schedulePendingFinalization();
		if ( null === $this->pendingOperationClaim ) {
			return $this->downloadError(
				'github_updater_operation_fence_lost',
				'The updater installation fence is unavailable.'
			);
		}
		$state = $this->nativeStateFromClaim( $this->pendingOperationClaim );
		$offer = $this->validatedOffer( $state['offer'] ?? null );
		if ( null === $offer || ! hash_equals( $offer['package_url'], $package ) ) {
			return $this->downloadError(
				'github_updater_release_changed',
				'The offered release changed before the installation operation acquired ownership.'
			);
		}
		$query      = $this->query();
		$descriptor = $this->artifacts->describeExact(
			new ExactReleaseRequest( $query, $offer['release_id'], $offer['tag'] )
		);
		if ( $descriptor instanceof \WP_Error
			|| ! $this->descriptorMatchesOffer( $descriptor, $offer )
		) {
			return $this->downloadError(
				'github_updater_release_changed',
				'The offered GitHub Release changed before download.'
			);
		}
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			return $this->downloadError( $blocked->get_error_code(), $blocked->get_error_message() );
		}

		$verified = $this->artifacts->acquireDescribed( $descriptor );
		if ( $verified instanceof \WP_Error ) {
			$this->storeDiagnostic( self::errorCode( $verified ), 'failed' );
			return $verified;
		}
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			$verified->discard();
			return $this->downloadError( $blocked->get_error_code(), $blocked->get_error_message() );
		}
		$validation = $this->validatedCandidateValidation( $offer['candidate_validation'] ?? null );
		if ( null === $validation ) {
			$verified->discard();
			return $this->downloadError(
				'github_updater_candidate_validation_missing',
				'The offered release validation is unavailable.'
			);
		}
		$rejection = ReleaseAssurance::AUTOMATIC_PROFILE_REVISION === ( $offer['automatic_profile'] ?? null )
			? $this->assurance->checkAutomatic( $descriptor, $validation, $verified->sha256() )
			: $this->assurance->check( $descriptor, $validation, $verified->sha256() );
		if ( $rejection instanceof \WP_Error ) {
			$verified->discard();
			$this->storeDiagnostic( self::errorCode( $rejection ), 'failed' );
			return $rejection;
		}
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			$verified->discard();
			return $this->downloadError( $blocked->get_error_code(), $blocked->get_error_message() );
		}
		$claimed = $verified->claim();
		if ( $claimed instanceof \WP_Error ) {
			$this->storeDiagnostic( self::errorCode( $claimed ), 'failed' );
			return $claimed;
		}

		$this->pendingArchive = $claimed->path();
		$this->pendingClaim   = $claimed;
		$this->pendingOffer   = $offer;
		add_filter(
			'pre_unzip_file',
			array( $this, 'filterPreUnzipFile' ),
			PHP_INT_MAX,
			5
		);

		$this->debugLog( 'verified_download', array( 'version' => $offer['version'] ) );
		return $this->pendingArchive;
	}

	/**
	 * Accept only a strict request-local Core capability for its unchanged local
	 * artifact. This remains narrower than a general local-package exception.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 * @return array{claim: ClaimedArtifact, expected_version: string}|null
	 */
	private function claimCoreReinstallHandoff(
		mixed $reply,
		string $package,
		array $hookExtra
	): ?array {
		if ( ! is_string( $reply )
			|| '' === $reply
			|| ! hash_equals( $package, $reply )
			|| 'update' !== ( $hookExtra['action'] ?? null ) ) {
			return null;
		}

		$claim = apply_filters(
			ReleaseCandidatePreflight::CORE_REINSTALL_HANDOFF_FILTER,
			null,
			$reply,
			$package,
			$hookExtra,
			$this->targetType,
			$this->pluginBasename
		);
		if ( ! $claim instanceof ClaimedArtifact ) {
			return null;
		}
		try {
			$expectedVersion = $claim->acceptCoreUpdate(
				$this->targetType,
				$this->pluginBasename,
				(string) $hookExtra['action'],
				$reply
			);
		} catch ( \Throwable ) {
			$claim->discard();
			return null;
		}

		return array(
			'claim'            => $claim,
			'expected_version' => $expectedVersion,
		);
	}

	/**
	 * Enforce the fixed extraction ceiling for the exact verified ZIP only.
	 *
	 * @param list<string> $neededDirs Core-calculated directories.
	 */
	public function filterPreUnzipFile(
		mixed $pre,
		string $file,
		string $destination,
		array $neededDirs,
		float $requiredSpace
	): mixed {
		unset( $destination, $neededDirs );
		if ( null === $this->pendingArchive
			|| ! hash_equals( $this->normalizedPath( $this->pendingArchive ), $this->normalizedPath( $file ) )
		) {
			return $pre;
		}
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $blocked;
		}
		remove_filter(
			'pre_unzip_file',
			array( $this, 'filterPreUnzipFile' ),
			PHP_INT_MAX
		);
		if ( null !== $this->pendingClaim ) {
			try {
				$this->pendingClaim->assertUnchanged();
			} catch ( \Throwable ) {
				return $this->downloadError(
					'github_updater_artifact_identity_changed',
					'The verified update archive changed before extraction.'
				);
			}
		}
		if ( $pre instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $pre;
		}
		if ( ! is_finite( $requiredSpace )
			|| $requiredSpace < 0
			|| $requiredSpace > ReleasePackageIdentityValidator::MAX_EXTRACTION_SPACE
		) {
			return $this->downloadError(
				'github_updater_extraction_too_large',
				'The verified update requires more than 256 MiB of extraction space.'
			);
		}
		if ( true === $pre ) {
			$this->debugLog( 'custom_extraction_admitted' );
			return true;
		}
		if ( null !== $pre ) {
			$this->clearPendingInstall();
			return $pre;
		}

		$this->debugLog( 'extraction_admitted' );
		return null;
	}

	/**
	 * Revalidate ownership at Core's final hook before destination mutation.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 */
	public function filterPreInstall( mixed $response, array $hookExtra ): mixed {
		if ( ! $this->matchesHookExtra( $hookExtra ) || null === $this->pendingArchive ) {
			return $response;
		}
		if ( $response instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $response;
		}
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $blocked;
		}
		return $response;
	}

	/**
	 * Validate Core's staged source and preserve a renamed installed directory.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 * @return string|\WP_Error
	 */
	public function filterSourceSelection(
		mixed $source,
		string $remoteSource,
		mixed $upgrader,
		array $hookExtra
	) {
		unset( $upgrader );
		if ( ! $this->matchesHookExtra( $hookExtra )
			|| null === $this->pendingArchive
		) {
			return $source;
		}
		if ( $source instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $source;
		}
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $blocked;
		}
		if ( ! is_string( $source ) || '' === $source ) {
			return $this->sourceError(
				'github_updater_invalid_staged_source',
				'WordPress did not provide a valid staged update source.'
			);
		}
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem )
			|| ! is_callable( array( $wp_filesystem, 'dirlist' ) )
			|| ! is_callable( array( $wp_filesystem, 'is_dir' ) )
			|| ! is_callable( array( $wp_filesystem, 'is_file' ) )
			|| ! is_callable( array( $wp_filesystem, 'size' ) )
			|| ! is_callable( array( $wp_filesystem, 'get_contents' ) )
		) {
			return $this->sourceError(
				'github_updater_filesystem_unavailable',
				'WordPress filesystem access is unavailable for staged package validation.'
			);
		}

		$remoteRoot = $this->withTrailingSlash( $remoteSource );
		$canonical  = $remoteRoot . $this->pluginSlug . '/';
		$dirlist    = $wp_filesystem->dirlist( $remoteRoot );
		if ( ! is_array( $dirlist )
			|| 1 !== count( $dirlist )
			|| ! array_key_exists( $this->pluginSlug, $dirlist )
			|| ! $wp_filesystem->is_dir( $canonical )
			|| $this->normalizedPath( $canonical ) !== $this->normalizedPath( $source )
		) {
			return $this->sourceError(
				'github_updater_invalid_staged_root',
				'The staged update does not contain the expected canonical package root.'
			);
		}

		$mainFile = $canonical . (
			'plugin' === $this->targetType
				? basename( $this->pluginBasename )
				: basename( $this->pluginFile )
		);
		if ( ! $wp_filesystem->is_file( $mainFile ) ) {
			return $this->sourceError(
				'github_updater_missing_staged_main_file',
				'The staged update does not contain the expected package entry file.'
			);
		}

		$pluginData = self::readStagedPackageData( $mainFile, $this->targetType, $wp_filesystem );
		if ( $pluginData instanceof \WP_Error ) {
			return $this->sourceError(
				'github_updater_staged_identity_mismatch',
				'The staged package metadata does not match the verified release ZIP.'
			);
		}
		$expectedVersion = null !== $this->pendingOffer
			? $this->pendingOffer['version']
			: $this->pendingExpectedVersion;
		$stagedVersion   = $pluginData['Version'] ?? null;
		if ( ! is_string( $stagedVersion )
			|| ! is_string( $expectedVersion )
			|| ! self::versionsEquivalent( $expectedVersion, $stagedVersion )
		) {
			return $this->sourceError(
				'github_updater_release_version_mismatch',
				'The staged package version does not match the verified published release.'
			);
		}
		$stagedUpdateUri = is_string( $pluginData['UpdateURI'] ?? null )
			? PackageIdentityTarget::normalizeUpdateUri( $pluginData['UpdateURI'] )
			: null;
		if ( null === $stagedUpdateUri
			|| ! hash_equals( $this->expectedUpdateUri(), $stagedUpdateUri )
		) {
			return $this->sourceError(
				'github_updater_staged_update_uri_mismatch',
				'The staged package Update URI does not match its configured GitHub repository.'
			);
		}
		$name = $pluginData['Name'] ?? null;
		if ( ! is_string( $name )
			|| '' === trim( $name )
			|| ( null !== $this->pendingOffer
				&& ! $this->stagedMetadataMatches( $pluginData, $this->pendingOffer ) )
		) {
			return $this->sourceError(
				'github_updater_staged_identity_mismatch',
				'The staged package metadata does not match the verified release ZIP.'
			);
		}

		if ( $this->installedDirectory === $this->pluginSlug ) {
			$blocked = $this->renewPendingInstall();
			if ( $blocked instanceof \WP_Error ) {
				$this->clearPendingInstall();
				return $blocked;
			}
			$this->debugLog( 'staged_identity_verified' );
			return $canonical;
		}
		if ( ! is_callable( array( $wp_filesystem, 'move' ) ) ) {
			return $this->sourceError(
				'github_updater_directory_mapping_unavailable',
				'WordPress cannot safely preserve the installed package directory.'
			);
		}

		$mapped  = $remoteRoot . $this->installedDirectory . '/';
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $blocked;
		}
		if ( $wp_filesystem->is_dir( $mapped )
			|| true !== $wp_filesystem->move(
				rtrim( $canonical, '/' ),
				rtrim( $mapped, '/' ),
				false
			)
		) {
			return $this->sourceError(
				'github_updater_unsafe_directory_mapping',
				'WordPress could not safely map the staged package to the installed directory.'
			);
		}
		$blocked = $this->renewPendingInstall();
		if ( $blocked instanceof \WP_Error ) {
			$this->clearPendingInstall();
			return $blocked;
		}

		$this->debugLog( 'staged_directory_mapped' );
		return $mapped;
	}

	/**
	 * Capture Core's authoritative per-target installation result.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 */
	public function captureInstallPackageResult( mixed $result, array $hookExtra ): mixed {
		if ( null !== $this->pendingArchive && $this->matchesHookExtra( $hookExtra ) ) {
			$this->pendingInstallResultCaptured = true;
			$this->pendingInstallResult         = $result;
		}

		return $result;
	}

	/**
	 * Correlate Core's process-complete signal without treating it as success.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 */
	public function observeCompletion( mixed $upgrader, array $hookExtra ): void {
		unset( $upgrader );
		if ( null === $this->pendingArchive
			|| 'update' !== ( $hookExtra['action'] ?? null )
			|| ( $hookExtra['type'] ?? null ) !== $this->targetType
		) {
			return;
		}
		$targets = 'plugin' === $this->targetType
			? ( $hookExtra['plugins'] ?? array( $hookExtra['plugin'] ?? null ) )
			: ( $hookExtra['themes'] ?? array( $hookExtra['theme'] ?? null ) );
		if ( ! is_array( $targets )
			|| ! in_array( $this->pluginBasename, $targets, true )
		) {
			return;
		}
		$this->pendingCompletionObserved = true;
	}

	/**
	 * Finalize only after Core's shutdown rollback and backup cleanup handlers.
	 */
	public function finalizePendingInstall(): void {
		if ( null === $this->pendingArchive ) {
			$this->clearPendingInstall();
			return;
		}

		try {
			// Core-owned reinstall handoffs never own or mutate native release state.
			if ( $this->pendingCoreHandoff ) {
				return;
			}
			if ( $this->pendingInstallResultCaptured
				&& ( $this->pendingInstallResult instanceof \WP_Error || false === $this->pendingInstallResult )
			) {
				$this->storeDiagnostic(
					$this->pendingInstallResult instanceof \WP_Error
						? self::errorCode( $this->pendingInstallResult )
						: 'core_update_failed',
					'failed'
				);
				return;
			}
			if ( ! $this->pendingInstallResultCaptured ) {
				$this->storeDiagnostic( 'core_update_install_result_missing', 'failed' );
				return;
			}
			if ( ! $this->pendingCompletionObserved ) {
				$this->storeDiagnostic( 'core_update_completion_missing', 'failed' );
				return;
			}

			$state            = $this->cachedState();
			$offer            = $this->validatedOffer( $state['offer'] ?? null );
			$data             = self::readPackageData( $this->pluginFile, $this->targetType );
			$installedVersion = is_array( $data ) && is_string( $data['Version'] ?? null )
				? $data['Version']
				: '';
			if ( null === $offer || ! self::versionsEquivalent( $offer['version'], $installedVersion ) ) {
				$this->storeDiagnostic( 'core_update_final_version_mismatch', 'failed' );
				return;
			}

			$this->persistNativeState(
				array(
					'schema'      => self::CACHE_SCHEMA,
					'status'      => 'current',
					'checked_at'  => $this->now(),
					'current'     => $offer,
					'conditional' => $this->conditionalToArray(
						$this->conditionalFromState( $state )
					),
					'diagnostic'  => array(
						'code'  => 'update_completed',
						'state' => 'current',
					),
				)
			);
			$this->debugLog( 'update_completed' );
		} finally {
			$this->clearPendingInstall();
		}
	}

	/**
	 * Render a filterable, sanitized notice only on Core update surfaces.
	 */
	public function renderAdminNotice(): void {
		if ( ! self::noticeSurfaceAllows( $this->targetType, $this->noticeRendered ) ) {
			return;
		}
		$state  = $this->cachedState();
		$notice = $this->defaultNotice( $state );
		if ( null === $notice ) {
			return;
		}

		$context = array(
			'type'       => $this->targetType,
			'package'    => $this->pluginBasename,
			'name'       => $this->header( 'Name', $this->pluginSlug ),
			'repository' => $this->repository->canonical(),
			'channel'    => $this->channel,
			'code'       => $notice['code'],
		);
		self::renderFilteredNotice( $notice, $context, $this->noticeRendered );
	}

	/**
	 * Register an actionable notice for a target rejected before construction.
	 *
	 * @param array<string, mixed> $target Plain broker target data.
	 */
	public static function registerConfigurationNotice( array $target, string $code ): void {
		$pluginFile = $target['pluginFile'] ?? null;
		$targetType = 'theme' === ( $target['targetType'] ?? null ) ? 'theme' : 'plugin';
		$pluginData = is_string( $pluginFile ) && is_file( $pluginFile )
			? self::readPackageData( $pluginFile, $targetType )
			: array();
		$name       = is_array( $pluginData ) && is_string( $pluginData['Name'] ?? null )
			? $pluginData['Name']
			: ( is_string( $target['pluginSlug'] ?? null ) ? $target['pluginSlug'] : 'Package' );
		$plugin     = 'plugin' === $targetType && is_string( $pluginFile ) && is_file( $pluginFile )
			? str_replace( '\\', '/', plugin_basename( $pluginFile ) )
			: ( is_string( $target['stylesheet'] ?? null ) ? $target['stylesheet'] : '' );
		$repository = is_string( $target['repository'] ?? null )
			&& 1 === preg_match(
				'/\A[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}\z/D',
				$target['repository']
			)
			? $target['repository']
			: '';
		$channel    = in_array( $target['channel'] ?? null, array( 'stable', 'prerelease' ), true )
			? $target['channel']
			: '';
		$code       = substr( sanitize_key( $code ), 0, 80 );
		$rendered   = false;
		$renderer   = static function () use (
			&$rendered,
			$name,
			$plugin,
			$targetType,
			$repository,
			$channel,
			$code
		): void {
			$notice  = array(
				'code'        => $code,
				'severity'    => 'error',
				'message'     => $name . ' has an invalid GitHub updater configuration.',
				'remediation' => 'Review the package update settings and reload this screen.',
			);
			$context = array(
				'type'       => $targetType,
				'package'    => $plugin,
				'name'       => $name,
				'repository' => $repository,
				'channel'    => $channel,
				'code'       => $code,
			);
			self::renderFilteredNotice( $notice, $context, $rendered );
		};

		add_action( 'admin_notices', $renderer );
		add_action( 'network_admin_notices', $renderer );
	}

	/**
	 * Return bounded passive state without initiating remote work.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics(): array {
		$state            = $this->cachedState();
		$offer            = $this->validatedOffer( $state['offer'] ?? null );
		$validation       = $this->validatedCandidateValidation(
			$state['candidate_validation'] ?? ( $offer['candidate_validation'] ?? null )
		);
		$validationArray  = $validation?->toArray();
		$installedVersion = $this->header( 'Version', '' );

		return array(
			'registered'                => $this->registered,
			'code'                      => is_string( $state['diagnostic']['code'] ?? null )
				? $state['diagnostic']['code']
				: 'not_checked',
			'state'                     => is_string( $state['diagnostic']['state'] ?? null )
				? $state['diagnostic']['state']
				: 'idle',
			'repository'                => $this->repository->canonical(),
			'channel'                   => $this->channel,
			'type'                      => $this->targetType,
			'package'                   => $this->pluginBasename,
			'offered_version'           => $offer['version'] ?? null,
			'last_check'                => is_int( $state['checked_at'] ?? null )
				? $state['checked_at']
				: null,
			'next_check'                => is_int( $state['cooldown_until'] ?? null )
				? $state['cooldown_until']
				: null,
			'installed_version'         => $installedVersion,
			'version_relationship'      => null === $validation
				? ReleaseVersion::RELATIONSHIP_INVALID
				: $validation->relationshipTo( $installedVersion ),
			'authentication_configured' => $this->accessToken->isConfigured(),
			'private_support'           => true,
			'candidate_validation'      => $validationArray,
			'release_tag'               => $validation?->releaseTag(),
			'release_version'           => $validation?->releaseVersion(),
			'package_header_version'    => $validation?->packageHeaderVersion(),
			'automatic_profile'         => $offer['automatic_profile'] ?? null,
			'automatic_eligible'        => 'automatic' !== $this->autoUpdatePolicy
				? null
				: null !== $offer,
		);
	}

	/**
	 * Clear this target's package cache and Core's native update transient.
	 *
	 * The next normal Core update check performs discovery. This method does
	 * not make a remote request or invoke an upgrader.
	 */
	public function refreshCache(): bool {
		$claim        = $this->operations->acquire(
			$this->coordinationTargetKey(),
			'native_state:refresh',
			$this->operations->discoveryLeaseSeconds()
		);
		$stateCleared = $claim instanceof ReleaseOperationClaim
			&& true === $this->operations->publish(
				$claim,
				ReleaseOperationCoordinator::NATIVE_STATE,
				array()
			);
		if ( $claim instanceof ReleaseOperationClaim && ! $stateCleared ) {
			$this->operations->release( $claim );
		}
		$coreDeleted = delete_site_transient(
			'plugin' === $this->targetType ? 'update_plugins' : 'update_themes'
		);

		return $stateCleared || $coreDeleted;
	}

	/**
	 * Return a fresh or cached normalized exact offer.
	 *
	 * @return Offer|null
	 */
	private function offer( ?string $installedVersion = null ): ?array {
		$state         = $this->cachedState();
		$offer         = $this->validatedOffer( $state['offer'] ?? null );
		$current       = $this->validatedOffer( $state['current'] ?? null, false );
		$age           = is_int( $state['checked_at'] ?? null )
			? ( $this->now() - $state['checked_at'] )
			: PHP_INT_MAX;
		$cooldownUntil = is_int( $state['cooldown_until'] ?? null )
			? $state['cooldown_until']
			: 0;
		if ( $cooldownUntil > $this->now() ) {
			$this->debugLog( 'cache_cooldown' );
			return null;
		}
		$forceCheck = $this->isForceCheck();
		if ( ! $forceCheck && null !== $offer && $age >= 0 && $age < $this->cacheDuration ) {
			$this->debugLog( 'cache_offer_hit', array( 'version' => $offer['version'] ) );
			return $offer;
		}
		if ( ! $forceCheck
			&& null !== $current
			&& ( null === $installedVersion
				|| ReleaseVersion::RELATIONSHIP_NEWER !== ReleaseVersion::relationship(
					$current['version'],
					$installedVersion
				)
				|| isset( $current['candidate_validation'] ) )
			&& $age >= 0
			&& $age < $this->cacheDuration
		) {
			$this->debugLog( 'cache_current_hit', array( 'version' => $current['version'] ) );
			return $current;
		}
		if ( ! $forceCheck
			&& 'unavailable' === ( $state['status'] ?? null )
			&& is_int( $state['failed_at'] ?? null )
			&& ( $this->now() - $state['failed_at'] ) < $this->failureCacheDuration
		) {
			return null;
		}
		$claim = $this->operations->acquire(
			$this->coordinationTargetKey(),
			'native_discovery:' . $this->cacheKey(),
			$this->operations->discoveryLeaseSeconds()
		);
		if ( $claim instanceof \WP_Error ) {
			$this->debugLog( 'operation_busy' );
			return null;
		}
		$this->activeDiscoveryClaim = $claim;
		$state                      = $this->nativeStateFromClaim( $claim );
		$offer                      = $this->validatedOffer( $state['offer'] ?? null );
		$current                    = $this->validatedOffer( $state['current'] ?? null, false );
		$verifiedCurrent            = $this->validatedOffer( $state['current'] ?? null );

		try {
			$conditional = $this->conditionalFromState( $state );
			$query       = $this->query( $conditional );
			$list        = $this->artifacts->listReleases( $query );
			if ( $list instanceof \WP_Error ) {
				$this->storeRemoteError( $list, $conditional, $state );
				return null;
			}
			$blocked = $this->renewDiscoveryClaim();
			if ( $blocked instanceof \WP_Error ) {
				return null;
			}
			if ( $list->rateLimit()->isLimited() ) {
				$this->storeCooldown(
					$state,
					$list->conditional(),
					$list->rateLimit()->cooldownSeconds() ?? $this->failureCacheDuration
				);
				return null;
			}
			if ( $list->isNotModified() ) {
				$reusable = $offer ?? $current;
				if ( null === $reusable ) {
					$this->storeUnavailable(
						'not_modified_without_cached_offer',
						new ConditionalState(),
						$state
					);
					return null;
				}
				$descriptor = $this->artifacts->describeExact(
					new ExactReleaseRequest( $query, $reusable['release_id'], $reusable['tag'] )
				);
				$blocked    = $this->renewDiscoveryClaim();
				if ( $blocked instanceof \WP_Error ) {
					return null;
				}
				if ( $descriptor instanceof \WP_Error
					|| ! $this->descriptorMatchesOffer( $descriptor, $reusable )
				) {
					$code = $descriptor instanceof \WP_Error
					? self::errorCode( $descriptor )
					: 'github_updater_release_changed';
					if ( $descriptor instanceof \WP_Error ) {
						$this->storeRemoteError( $descriptor, new ConditionalState(), $state );
					} else {
						$this->storeUnavailable( $code, new ConditionalState(), $state );
					}
					return null;
				}
				$validation = $this->reusableCurrentValidation(
					$descriptor,
					$installedVersion,
					$this->validatedOffer( $reusable )
				);
				if ( $validation instanceof \WP_Error ) {
					$this->storeRemoteError( $validation, new ConditionalState(), $state );
					return null;
				}
				return $this->acceptDescriptor(
					$descriptor,
					$this->mergedConditional( $list->conditional(), $conditional ),
					$installedVersion,
					$validation
				);
			}

			$target = $this->identityTarget();
			if ( $target instanceof \WP_Error ) {
				$this->storeRemoteError( $target, $list->conditional(), $state );
				return null;
			}
			$selected = $this->candidates->select(
				$list,
				$query,
				$target,
				$this->assurance,
				$installedVersion,
				fn (): ?\WP_Error => $this->renewDiscoveryClaim()
			);
			if ( $selected instanceof \WP_Error ) {
				$this->storeRemoteError( $selected, $list->conditional(), $state );
				return null;
			}
			if ( null === $selected ) {
				$this->storeUnavailable( 'no_eligible_release', $list->conditional(), $state );
				return null;
			}

			$validation = $selected['validation'];
			if ( null === $validation ) {
				$validation = $this->reusableCurrentValidation(
					$selected['descriptor'],
					$installedVersion,
					$offer ?? $verifiedCurrent
				);
				if ( $validation instanceof \WP_Error ) {
					$this->storeRemoteError( $validation, $list->conditional(), $state );
					return null;
				}
			}

			return $this->acceptDescriptor(
				$selected['descriptor'],
				$list->conditional(),
				$installedVersion,
				$validation
			);
		} finally {
			if ( null !== $this->activeDiscoveryClaim ) {
				$this->operations->release( $this->activeDiscoveryClaim );
				$this->activeDiscoveryClaim = null;
			}
		}
	}

	/**
	 * Acquire and inspect the exact verified ZIP before Core receives an offer.
	 *
	 * @return Offer|null
	 */
	private function acceptDescriptor(
		ArtifactDescriptor $descriptor,
		ConditionalState $conditional,
		?string $installedVersion,
		?CandidateValidation $validation = null
	): ?array {
		$candidate = $this->offerFromDescriptor( $descriptor );
		if ( null !== $installedVersion
			&& ReleaseVersion::RELATIONSHIP_NEWER !== ReleaseVersion::relationship(
				$candidate['version'],
				$installedVersion
			)
		) {
			if ( null !== $validation && ! $validation->isReady() ) {
				$this->storeCandidateRejected( $candidate, $validation, $conditional );
				return null;
			}
			$automaticRejection = $this->assurance->automaticEligibility( $descriptor );
			if ( $automaticRejection instanceof \WP_Error ) {
				if ( 'automatic' === $this->autoUpdatePolicy ) {
					$this->storeRemoteError( $automaticRejection, $conditional, $this->cachedState() );
					return null;
				}
			} else {
				$candidate['automatic_profile'] = ReleaseAssurance::AUTOMATIC_PROFILE_REVISION;
			}
			if ( null !== $validation ) {
				$candidate['requires']             = $validation->requiresWordPress();
				$candidate['requires_php']         = $validation->requiresPhp();
				$candidate['candidate_validation'] = $validation->toArray();
			}
			$this->storeCurrent( $candidate, $conditional );
			return null;
		}

		$validation ??= $this->validateCandidate( $descriptor );
		if ( $validation instanceof \WP_Error ) {
			$this->storeRemoteError( $validation, $conditional, $this->cachedState() );
			return null;
		}
		if ( ! $validation->isReady() ) {
			$this->storeCandidateRejected( $candidate, $validation, $conditional );
			return null;
		}
		$automaticRejection = $this->assurance->automaticEligibility( $descriptor );
		if ( $automaticRejection instanceof \WP_Error ) {
			if ( 'automatic' === $this->autoUpdatePolicy ) {
				$this->storeRemoteError( $automaticRejection, $conditional, $this->cachedState() );
				return null;
			}
		} else {
			$candidate['automatic_profile'] = ReleaseAssurance::AUTOMATIC_PROFILE_REVISION;
		}

		$offer                         = $candidate;
		$offer['requires']             = $validation->requiresWordPress();
		$offer['requires_php']         = $validation->requiresPhp();
		$offer['candidate_validation'] = $validation->toArray();
		if ( ! $this->storeAvailable( $offer, $conditional ) ) {
			return null;
		}
		$this->debugLog( 'release_selected', array( 'version' => $offer['version'] ) );
		return $offer;
	}

	/**
	 * Reuse archive-backed validation only for the same verified current release.
	 * Custom assurance remains request-fresh and therefore revalidates the ZIP.
	 *
	 * @param Offer|null $verifiedRelease
	 * @return CandidateValidation|\WP_Error|null
	 */
	private function reusableCurrentValidation(
		ArtifactDescriptor $descriptor,
		?string $installedVersion,
		?array $verifiedRelease
	) {
		if ( null === $installedVersion
			|| ReleaseVersion::RELATIONSHIP_NEWER === ReleaseVersion::relationship(
				$descriptor->version(),
				$installedVersion
			)
			|| null === $verifiedRelease
			|| ! $this->descriptorMatchesOffer( $descriptor, $verifiedRelease )
		) {
			return null;
		}
		if ( null === $this->assurance->cacheRevision() ) {
			return $this->validateCandidate( $descriptor );
		}

		return $this->validatedCandidateValidation(
			$verifiedRelease['candidate_validation'] ?? null
		);
	}

	/**
	 * @return CandidateValidation|\WP_Error
	 */
	private function validateCandidate( ArtifactDescriptor $descriptor ) {
		$target = $this->identityTarget();
		if ( $target instanceof \WP_Error ) {
			throw new \LogicException( 'A validated release descriptor must have a valid package identity target.' );
		}

		return $this->candidates->validate(
			$descriptor,
			$target,
			$this->assurance,
			null === $this->activeDiscoveryClaim
				? null
				: fn (): ?\WP_Error => $this->renewDiscoveryClaim()
		);
	}

	/**
	 * @return PackageIdentityTarget|\WP_Error
	 */
	private function identityTarget() {
		return 'theme' === $this->targetType
			? PackageIdentityTarget::forTheme( $this->pluginSlug, $this->expectedUpdateUri() )
			: PackageIdentityTarget::forPlugin(
				$this->pluginSlug,
				basename( $this->pluginBasename ),
				$this->expectedUpdateUri()
			);
	}

	private function query( ?ConditionalState $conditional = null ): ReleaseQuery {
		$wpVersion = is_string( $GLOBALS['wp_version'] ?? null )
			? $GLOBALS['wp_version']
			: '6.5';

		return new ReleaseQuery(
			$this->repository,
			$this->channel,
			PHP_VERSION,
			$wpVersion,
			ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS,
			$conditional,
			$this->accessToken
		);
	}

	/**
	 * @return Offer
	 */
	private function offerFromDescriptor( ArtifactDescriptor $descriptor ): array {
		return array(
			'provider_repository_id' => $this->repository->providerRepositoryId(),
			'release_id'             => $descriptor->releaseId(),
			'tag'                    => $descriptor->tag(),
			'version'                => $descriptor->version(),
			'commit'                 => $descriptor->commit(),
			'zip_asset_id'           => $descriptor->zipAsset()->id(),
			'zip_name'               => $descriptor->zipAsset()->name(),
			'immutable'              => $descriptor->isImmutable(),
			'sha256'                 => $descriptor->zipAsset()->sha256(),
			'size'                   => $descriptor->zipAsset()->size(),
			'requires'               => null,
			'requires_php'           => null,
			'details_url'            => $descriptor->detailsUrl(),
			'package_url'            => 'https://api.github.com/repos/'
				. $this->repository->apiPath()
				. '/releases/assets/'
				. $descriptor->zipAsset()->id(),
		);
	}

	/**
	 * @param Offer $offer
	 */
	private function descriptorMatchesOffer( ArtifactDescriptor $descriptor, array $offer ): bool {
		if ( ReleaseQuery::STABLE === $this->channel
			&& ( $descriptor->isPrerelease() || ReleaseVersion::isPrerelease( $descriptor->version() ) )
		) {
			return false;
		}
		$current = $this->offerFromDescriptor( $descriptor );
		foreach (
			array(
				'provider_repository_id',
				'release_id',
				'tag',
				'version',
				'commit',
				'zip_asset_id',
				'zip_name',
				'immutable',
				'sha256',
				'size',
				'package_url',
			) as $key
		) {
			if ( $current[ $key ] !== $offer[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return Offer|null
	 */
	private function validatedOffer( mixed $offer, bool $requiresCandidateValidation = true ): ?array {
		if ( ! is_array( $offer ) ) {
			return null;
		}
		$providerRepositoryId = $offer['provider_repository_id'] ?? null;
		if ( ! array_key_exists( 'provider_repository_id', $offer )
			|| ( null !== $providerRepositoryId && ! is_string( $providerRepositoryId ) )
			|| $this->repository->providerRepositoryId() !== $providerRepositoryId
		) {
			return null;
		}
		$stringFields = array(
			'tag',
			'version',
			'commit',
			'zip_name',
			'sha256',
			'details_url',
			'package_url',
		);
		foreach ( $stringFields as $key ) {
			if ( ! is_string( $offer[ $key ] ?? null ) || '' === $offer[ $key ] ) {
				return null;
			}
		}
		if ( null === ReleaseVersion::normalize( $offer['version'] ) ) {
			return null;
		}
		if ( ReleaseQuery::STABLE === $this->channel
			&& ReleaseVersion::isPrerelease( $offer['version'] )
		) {
			return null;
		}
		foreach ( array( 'release_id', 'zip_asset_id', 'size' ) as $key ) {
			if ( ! is_int( $offer[ $key ] ?? null ) || $offer[ $key ] < 1 ) {
				return null;
			}
		}
		if ( ! is_bool( $offer['immutable'] ?? null )
			|| ( null !== ( $offer['requires'] ?? null ) && ! is_string( $offer['requires'] ) )
			|| ( null !== ( $offer['requires_php'] ?? null ) && ! is_string( $offer['requires_php'] ) )
		) {
			return null;
		}
		$automaticProfile = $offer['automatic_profile'] ?? null;
		if ( null !== $automaticProfile
			&& ( ReleaseAssurance::AUTOMATIC_PROFILE_REVISION !== $automaticProfile
				|| null === $providerRepositoryId
				|| true !== $offer['immutable'] )
		) {
			return null;
		}
		if ( 'automatic' === $this->autoUpdatePolicy
			&& ReleaseAssurance::AUTOMATIC_PROFILE_REVISION !== $automaticProfile
		) {
			return null;
		}
		if ( 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $offer['commit'] )
			|| 1 !== preg_match( '/\A[a-f0-9]{64}\z/D', $offer['sha256'] )
			|| ! str_starts_with( $offer['details_url'], 'https://github.com/' . $this->repository->canonical() . '/releases/' )
			|| ! str_starts_with( $offer['package_url'], 'https://api.github.com/repos/' . $this->repository->apiPath() . '/releases/assets/' )
		) {
			return null;
		}
		$validation = $this->validatedCandidateValidation( $offer['candidate_validation'] ?? null );
		if ( null === $validation ) {
			if ( $requiresCandidateValidation ) {
				return null;
			}
			/** @var Offer $offer */
			return $offer;
		}
		$identity = $validation->identity();
		if ( CandidateValidation::READY !== $validation->state()
			|| $validation->releaseTag() !== $offer['tag']
			|| $validation->releaseVersion() !== $offer['version']
			|| $identity['release_id'] !== $offer['release_id']
			|| $identity['zip_asset_id'] !== $offer['zip_asset_id']
			|| $identity['sha256'] !== $offer['sha256']
			|| $this->targetType !== $identity['package_type']
			|| $identity['header_file'] !== $this->identityHeaderFile()
			|| $validation->requiresWordPress() !== $offer['requires']
			|| $validation->requiresPhp() !== $offer['requires_php']
		) {
			return null;
		}

		/** @var Offer $offer */
		return $offer;
	}

	private function identityHeaderFile(): string {
		return 'theme' === $this->targetType
			? $this->pluginSlug . '/style.css'
			: $this->pluginSlug . '/' . basename( $this->pluginBasename );
	}

	/**
	 * Rehydrate persisted validation through its single canonical boundary.
	 */
	private function validatedCandidateValidation( mixed $validation ): ?CandidateValidation {
		return is_array( $validation ) ? CandidateValidation::fromArray( $validation ) : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function cachedState(): array {
		$state = $this->operations->state(
			$this->coordinationTargetKey(),
			ReleaseOperationCoordinator::NATIVE_STATE
		);
		return is_array( $state )
			&& hash_equals( $this->cacheKey(), is_string( $state['_cache_key'] ?? null ) ? $state['_cache_key'] : '' )
			&& self::CACHE_SCHEMA === ( $state['schema'] ?? null )
			? $state
			: array();
	}

	/** @return array<string, mixed> */
	private function nativeStateFromClaim( ReleaseOperationClaim $claim ): array {
		$state = $claim->results()[ ReleaseOperationCoordinator::NATIVE_STATE ] ?? array();
		return is_array( $state )
			&& hash_equals( $this->cacheKey(), is_string( $state['_cache_key'] ?? null ) ? $state['_cache_key'] : '' )
			&& self::CACHE_SCHEMA === ( $state['schema'] ?? null )
			? $state
			: array();
	}

	/** @param array<string, mixed> $state */
	private function persistNativeState( array $state ): bool {
		$state['_cache_key'] = $this->cacheKey();
		$claim               = $this->activeDiscoveryClaim ?? $this->pendingOperationClaim;
		if ( null === $claim ) {
			$acquired = $this->operations->acquire(
				$this->coordinationTargetKey(),
				'native_state:diagnostic',
				$this->operations->discoveryLeaseSeconds()
			);
			if ( $acquired instanceof \WP_Error ) {
				return false;
			}
			$claim = $acquired;
		}
		$published = $this->operations->publish(
			$claim,
			ReleaseOperationCoordinator::NATIVE_STATE,
			$state
		);
		$completed = true === $published || $this->operations->release( $claim );
		if ( $completed ) {
			if ( $claim === $this->activeDiscoveryClaim ) {
				$this->activeDiscoveryClaim = null;
			}
			if ( $claim === $this->pendingOperationClaim ) {
				$this->pendingOperationClaim = null;
			}
		}
		return true === $published;
	}

	/**
	 * @param Offer $offer
	 */
	private function storeAvailable( array $offer, ConditionalState $conditional ): bool {
		return $this->persistNativeState(
			array(
				'schema'      => self::CACHE_SCHEMA,
				'status'      => 'available',
				'checked_at'  => $this->now(),
				'offer'       => $offer,
				'conditional' => $this->conditionalToArray( $conditional ),
				'diagnostic'  => array(
					'code'  => 'release_available',
					'state' => 'ready',
				),
			)
		);
	}

	/**
	 * Cache a failed candidate verdict by exact release and package identity.
	 *
	 * @param array<string, mixed> $candidate
	 */
	private function storeCandidateRejected(
		array $candidate,
		CandidateValidation $validation,
		ConditionalState $conditional
	): void {
		$validationArray = $validation->toArray();
		$priorState      = $this->cachedState();
		$offer           = $this->validatedOffer( $priorState['offer'] ?? null );
		$this->persistNativeState(
			array(
				'schema'               => self::CACHE_SCHEMA,
				'status'               => 'unavailable',
				'checked_at'           => is_int( $priorState['checked_at'] ?? null )
					? $priorState['checked_at']
					: null,
				'failed_at'            => $this->now(),
				'offer'                => $offer,
				'candidate'            => $candidate,
				'candidate_validation' => $validationArray,
				'conditional'          => $this->conditionalToArray(
					$this->mergedConditional(
						$conditional,
						$this->conditionalFromState( $priorState )
					)
				),
				'diagnostic'           => array(
					'code'  => $validation->code(),
					'state' => CandidateValidation::BLOCKED,
				),
			)
		);
		$this->debugLog( 'candidate_blocked', array( 'code' => $validation->code() ) );
	}

	/**
	 * Retain a fresh verified descriptor for cache reuse without presenting it
	 * as an available update.
	 *
	 * @param Offer $release Current verified release.
	 */
	private function storeCurrent( array $release, ?ConditionalState $conditional = null ): void {
		// Core refreshes update metadata before shutdown finalization. Keep the
		// exact offer and installation fence authoritative until that final readback.
		if ( null !== $this->pendingArchive ) {
			return;
		}
		$state   = $this->cachedState();
		$current = $this->validatedOffer( $state['current'] ?? null, false );
		if ( null === $conditional
			&& 'current' === ( $state['status'] ?? null )
			&& null !== $current
			&& $current === $release
		) {
			return;
		}
		$this->persistNativeState(
			array(
				'schema'      => self::CACHE_SCHEMA,
				'status'      => 'current',
				'checked_at'  => null === $conditional && is_int( $state['checked_at'] ?? null )
					? $state['checked_at']
					: $this->now(),
				'current'     => $release,
				'conditional' => $this->conditionalToArray(
					null === $conditional
						? $this->conditionalFromState( $state )
						: $this->mergedConditional(
							$conditional,
							$this->conditionalFromState( $state )
						)
				),
				'diagnostic'  => array(
					'code'  => 'up_to_date',
					'state' => 'current',
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $priorState Last safe cached state.
	 */
	private function storeUnavailable(
		string $code,
		ConditionalState $conditional,
		array $priorState
	): void {
		$normalizedCode  = substr( sanitize_key( $code ), 0, 80 );
		$diagnosticState = $this->cachedState();
		$offer           = $this->validatedOffer( $priorState['offer'] ?? null );
		$checkedAt       = is_int( $priorState['checked_at'] ?? null )
			? $priorState['checked_at']
			: null;
		$this->persistNativeState(
			array(
				'schema'      => self::CACHE_SCHEMA,
				'status'      => 'unavailable',
				'checked_at'  => $checkedAt,
				'failed_at'   => $this->now(),
				'offer'       => $offer,
				'conditional' => $this->conditionalToArray(
					$this->mergedConditional(
						$conditional,
						$this->conditionalFromState( $priorState )
					)
				),
				'diagnostic'  => array(
					'code'    => $normalizedCode,
					'state'   => 'unavailable',
					'repeats' => $this->diagnosticRepeats(
						$diagnosticState,
						$normalizedCode
					),
				),
			)
		);
		$this->debugLog( 'failure', array( 'code' => $normalizedCode ) );
	}

	/**
	 * Persist a provider cooldown without discarding a still-bounded safe offer.
	 *
	 * @param array<string, mixed> $priorState Last safe cached state.
	 */
	private function storeCooldown(
		array $priorState,
		ConditionalState $conditional,
		int $cooldown
	): void {
		$cooldown = max( 1, min( 86400, $cooldown ) );
		$offer    = $this->validatedOffer( $priorState['offer'] ?? null );
		$repeats  = $this->diagnosticRepeats( $priorState, 'rate_limited' );
		$state    = array(
			'schema'         => self::CACHE_SCHEMA,
			'status'         => 'rate_limited',
			'checked_at'     => is_int( $priorState['checked_at'] ?? null )
				? $priorState['checked_at']
				: null,
			'failed_at'      => $this->now(),
			'cooldown_until' => $this->now() + $cooldown,
			'offer'          => $offer,
			'conditional'    => $this->conditionalToArray(
				$this->mergedConditional(
					$conditional,
					$this->conditionalFromState( $priorState )
				)
			),
			'diagnostic'     => array(
				'code'    => 'rate_limited',
				'state'   => 'cooldown',
				'repeats' => $repeats,
			),
		);
		$this->persistNativeState( $state );
		$this->debugLog( 'failure', array( 'code' => 'rate_limited' ) );
	}

	/**
	 * Preserve exact provider failure and cooldown classification.
	 *
	 * @param array<string, mixed> $priorState Last safe cached state.
	 */
	private function storeRemoteError(
		\WP_Error $error,
		ConditionalState $conditional,
		array $priorState
	): void {
		$code = self::errorCode( $error );
		$data = $error->get_error_data( $code );
		if ( 'github_updater_rate_limited' === $code
			&& is_array( $data )
			&& is_int( $data['cooldown'] ?? null )
		) {
			$this->storeCooldown( $priorState, $conditional, $data['cooldown'] );
			return;
		}

		$this->storeUnavailable( $code, $conditional, $priorState );
	}

	private function storeDiagnostic( string $code, string $diagnosticState ): void {
		$state               = $this->cachedState();
		$normalizedCode      = substr( sanitize_key( $code ), 0, 80 );
		$priorCode           = is_string( $state['diagnostic']['code'] ?? null )
			? $state['diagnostic']['code']
			: '';
		$repeats             = $normalizedCode === $priorCode
			&& is_int( $state['diagnostic']['repeats'] ?? null )
			? min( 3, $state['diagnostic']['repeats'] + 1 )
			: 1;
		$state['schema']     = self::CACHE_SCHEMA;
		$state['diagnostic'] = array(
			'code'    => $normalizedCode,
			'state'   => sanitize_key( $diagnosticState ),
			'repeats' => $repeats,
		);
		$this->persistNativeState( $state );
		if ( in_array( $diagnosticState, array( 'failed', 'unavailable', 'cooldown' ), true ) ) {
			$this->debugLog( 'failure', array( 'code' => $normalizedCode ) );
		}
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function diagnosticRepeats( array $state, string $code ): int {
		$priorCode = is_string( $state['diagnostic']['code'] ?? null )
			? $state['diagnostic']['code']
			: '';
		$prior     = is_int( $state['diagnostic']['repeats'] ?? null )
			? $state['diagnostic']['repeats']
			: 0;

		return $code === $priorCode ? min( 3, $prior + 1 ) : 1;
	}

	/**
	 * @param array<string, mixed> $pluginData
	 * @param Offer                $offer
	 */
	private function stagedMetadataMatches( array $pluginData, array $offer ): bool {
		$name        = $pluginData['Name'] ?? null;
		$version     = $pluginData['Version'] ?? null;
		$requiresWp  = $pluginData['RequiresWP'] ?? null;
		$requiresPhp = $pluginData['RequiresPHP'] ?? null;

		return is_string( $name )
			&& '' !== trim( $name )
			&& is_string( $version )
			&& self::versionsEquivalent( $offer['version'], $version )
			&& is_string( $requiresWp )
			&& is_string( $offer['requires'] )
			&& hash_equals( $offer['requires'], $requiresWp )
			&& is_string( $requiresPhp )
			&& is_string( $offer['requires_php'] )
			&& hash_equals( $offer['requires_php'], $requiresPhp );
	}

	private function sourceError( string $code, string $message ): \WP_Error {
		return $this->downloadError( $code, $message );
	}

	private static function versionsEquivalent( string $releaseVersion, string $headerVersion ): bool {
		$normalizedRelease = ReleaseVersion::normalize( $releaseVersion );
		$normalizedHeader  = ReleaseVersion::normalizeHeader( $headerVersion );
		return null !== $normalizedRelease
			&& null !== $normalizedHeader
			&& hash_equals( $normalizedRelease, $normalizedHeader );
	}

	private function expectedUpdateUri(): string {
		return 'https://github.com/' . strtolower( $this->repository->canonical() );
	}

	private function coordinationTargetKey(): string {
		return implode(
			"\0",
			array(
				$this->targetType,
				$this->pluginSlug,
				'theme' === $this->targetType ? 'style.css' : basename( $this->pluginBasename ),
			)
		);
	}

	private function renewDiscoveryClaim(): ?\WP_Error {
		if ( null === $this->activeDiscoveryClaim ) {
			return new \WP_Error(
				'github_updater_operation_fence_lost',
				'The release-discovery ownership fence was lost.'
			);
		}
		$renewed = $this->operations->renew(
			$this->activeDiscoveryClaim,
			$this->operations->discoveryLeaseSeconds()
		);
		if ( $renewed instanceof \WP_Error ) {
			return $renewed;
		}
		$this->activeDiscoveryClaim = $renewed;
		return null;
	}

	private function startPendingInstall( string $package ): ?\WP_Error {
		if ( null !== $this->pendingOperationClaim ) {
			return new \WP_Error(
				'github_updater_operation_fence_lost',
				'An updater installation fence is already active for this target.'
			);
		}
		$claim = $this->operations->acquire(
			$this->coordinationTargetKey(),
			'native_install:' . substr( hash( 'sha256', $package ), 0, 40 ),
			$this->operations->installLeaseSeconds()
		);
		if ( $claim instanceof \WP_Error ) {
			return $claim;
		}
		$this->pendingOperationClaim = $claim;
		return null;
	}

	private function renewPendingInstall(): ?\WP_Error {
		if ( null === $this->pendingOperationClaim ) {
			return new \WP_Error(
				'github_updater_operation_fence_lost',
				'The updater installation fence is unavailable.'
			);
		}
		$renewed = $this->operations->renew(
			$this->pendingOperationClaim,
			$this->operations->installLeaseSeconds()
		);
		if ( $renewed instanceof \WP_Error ) {
			return $renewed;
		}
		$this->pendingOperationClaim = $renewed;
		return null;
	}

	private function schedulePendingFinalization(): void {
		if ( $this->pendingShutdownScheduled ) {
			return;
		}
		add_action(
			'shutdown',
			array( $this, 'finalizePendingInstall' ),
			PHP_INT_MAX,
			0
		);
		$this->pendingShutdownScheduled = true;
	}

	private function clearPendingInstall(): void {
		remove_filter(
			'pre_unzip_file',
			array( $this, 'filterPreUnzipFile' ),
			PHP_INT_MAX
		);
		remove_action(
			'shutdown',
			array( $this, 'finalizePendingInstall' ),
			PHP_INT_MAX
		);
		$claim          = $this->pendingClaim;
		$operationClaim = $this->pendingOperationClaim;

		$this->pendingArchive               = null;
		$this->pendingClaim                 = null;
		$this->pendingOffer                 = null;
		$this->pendingExpectedVersion       = null;
		$this->pendingCoreHandoff           = false;
		$this->pendingInstallResultCaptured = false;
		$this->pendingInstallResult         = null;
		$this->pendingCompletionObserved    = false;
		$this->pendingShutdownScheduled     = false;
		$this->pendingOperationClaim        = null;

		try {
			$claim?->discard();
		} finally {
			if ( null !== $operationClaim ) {
				$this->operations->release( $operationClaim );
			}
		}
	}

	private function normalizedPath( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	private function withTrailingSlash( string $path ): string {
		return $this->normalizedPath( $path ) . '/';
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array{code: string, severity: string, message: string, remediation: string}|null
	 */
	private function defaultNotice( array $state ): ?array {
		$diagnostic = is_array( $state['diagnostic'] ?? null )
			? $state['diagnostic']
			: array();
		$code       = is_string( $diagnostic['code'] ?? null )
			? sanitize_key( $diagnostic['code'] )
			: '';
		$status     = is_string( $diagnostic['state'] ?? null )
			? sanitize_key( $diagnostic['state'] )
			: '';
		$repeats    = is_int( $diagnostic['repeats'] ?? null )
			? min( 3, max( 1, $diagnostic['repeats'] ) )
			: 1;
		if ( '' === $code
			|| ! in_array( $status, array( 'failed', 'unavailable', 'cooldown', 'inactive' ), true )
		) {
			return null;
		}

		$isAuthentication = str_contains( $code, 'credential' )
			|| str_contains( $code, 'authentication' )
			|| str_contains( $code, 'unauthorized' )
			|| str_contains( $code, 'access_token' );
		$isRateLimit      = str_contains( $code, 'rate_limit' );
		if ( ( $isAuthentication || $isRateLimit ) && $repeats < 2 ) {
			return null;
		}
		if ( str_contains( $code, 'http_request_failed' )
			|| str_contains( $code, 'http_5' )
			|| str_contains( $code, 'server_error' )
		) {
			return null;
		}

		$name = $this->header( 'Name', $this->pluginSlug );
		if ( $isAuthentication ) {
			return array(
				'code'        => $code,
				'severity'    => 'error',
				'message'     => $name . ' cannot authenticate its GitHub update request.',
				'remediation' => 'Check the configured repository credential and try the update again.',
			);
		}
		if ( $isRateLimit ) {
			return array(
				'code'        => $code,
				'severity'    => 'warning',
				'message'     => $name . ' update checks are temporarily limited by GitHub.',
				'remediation' => 'Wait for the reported cooldown before checking again.',
			);
		}

		return array(
			'code'        => $code,
			'severity'    => 'error',
			'message'     => $name . ' rejected a GitHub release update.',
			'remediation' => 'Review the package update configuration and release artifacts, then retry.',
		);
	}

	/**
	 * Apply the public notice filter, then sanitize and render the result.
	 *
	 * @param array<string, mixed> $notice  Default structured notice.
	 * @param array<string, mixed> $context Safe plugin context.
	 */
	private static function renderFilteredNotice(
		array $notice,
		array $context,
		bool &$rendered
	): void {
		$type = 'theme' === ( $context['type'] ?? null ) ? 'theme' : 'plugin';
		if ( ! self::noticeSurfaceAllows( $type, $rendered ) ) {
			return;
		}

		$notice = apply_filters(
			'ran_wp_github_release_updater_notice',
			$notice,
			$context
		);
		if ( null === $notice || ! is_array( $notice ) ) {
			return;
		}

		$severity = is_string( $notice['severity'] ?? null )
			? sanitize_key( $notice['severity'] )
			: 'error';
		if ( ! in_array( $severity, array( 'error', 'warning', 'info', 'success' ), true ) ) {
			$severity = 'error';
		}
		$message     = is_string( $notice['message'] ?? null )
			? sanitize_text_field( $notice['message'] )
			: '';
		$remediation = is_string( $notice['remediation'] ?? null )
			? sanitize_text_field( $notice['remediation'] )
			: '';
		if ( '' === $message ) {
			return;
		}

		$rendered = true;
		echo '<div class="notice notice-' . esc_attr( $severity ) . '"><p>'
			. esc_html( $message );
		if ( '' !== $remediation ) {
			echo ' ' . esc_html( $remediation );
		}
		echo '</p></div>';
	}

	private static function noticeSurfaceAllows( string $targetType, bool $rendered ): bool {
		$capability = 'theme' === $targetType
			? 'update_themes'
			: 'update_plugins';
		if ( $rendered || ! current_user_can( $capability ) ) {
			return false;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$base   = is_object( $screen ) && is_string( $screen->base ?? null )
			? $screen->base
			: '';
		if ( ! in_array(
			$base,
			array(
				'plugins',
				'plugins-network',
				'themes',
				'themes-network',
				'update-core',
				'update-core-network',
			),
			true
		) ) {
			return false;
		}

		return true;
	}

	/**
	 * Emit a bounded event containing only explicitly allowed safe fields.
	 *
	 * @param array<string, string> $fields
	 */
	private function debugLog( string $event, array $fields = array() ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || true !== WP_DEBUG_LOG ) {
			return;
		}

		$parts = array( sanitize_key( $event ) );
		foreach ( array( 'code', 'version' ) as $key ) {
			$value = $fields[ $key ] ?? null;
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$parts[] = $key . '=' . substr(
				preg_replace( '/[^A-Za-z0-9._-]/', '', $value ) ?? '',
				0,
				80
			);
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required, bounded WP_DEBUG_LOG diagnostics.
		error_log( '[ran-wp-github-release-updater] ' . implode( ' ', $parts ) );
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function conditionalFromState( array $state ): ConditionalState {
		$conditional = is_array( $state['conditional'] ?? null )
			? $state['conditional']
			: array();
		return new ConditionalState(
			is_string( $conditional['etag'] ?? null ) ? $conditional['etag'] : null,
			is_string( $conditional['last_modified'] ?? null ) ? $conditional['last_modified'] : null
		);
	}

	/**
	 * @return array{etag: ?string, last_modified: ?string}
	 */
	private function conditionalToArray( ConditionalState $conditional ): array {
		return array(
			'etag'          => $conditional->etag(),
			'last_modified' => $conditional->lastModified(),
		);
	}

	private function mergedConditional(
		ConditionalState $fresh,
		ConditionalState $prior
	): ConditionalState {
		return new ConditionalState(
			$fresh->etag() ?? $prior->etag(),
			$fresh->lastModified() ?? $prior->lastModified()
		);
	}

	private function cacheKey(): string {
		return 'ran_wp_gh_updater_v1_' . substr(
			hash(
				'sha256',
				$this->repository->canonical()
				. "\0"
				. ( $this->repository->providerRepositoryId() ?? 'unmanaged' )
				. "\0"
				. $this->targetType
				. "\0"
				. $this->pluginBasename
				. "\0"
				. $this->pluginSlug
				. "\0"
				. $this->channel
				. "\0"
				. PHP_VERSION
				. "\0"
				. ( is_string( $GLOBALS['wp_version'] ?? null ) ? $GLOBALS['wp_version'] : '6.5' )
				. "\0"
				. ( $this->accessToken->isConfigured() ? 'private' : 'public' )
			),
			0,
			32
		);
	}

	private function now(): int {
		return ( $this->clock )();
	}

	private function isForceCheck(): bool {
		if ( ! current_user_can(
			'plugin' === $this->targetType ? 'update_plugins' : 'update_themes'
		) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Core update refresh flag.
		$value = isset( $_GET['force-check'] ) ? wp_unslash( $_GET['force-check'] ) : null;
		return is_string( $value ) && '1' === $value;
	}

	private function header( string $key, string $fallback ): string {
		return is_string( $this->pluginData[ $key ] ?? null )
			? $this->pluginData[ $key ]
			: $fallback;
	}

	private function downloadError( string $code, string $message ): \WP_Error {
		if ( ! $this->pendingCoreHandoff ) {
			$this->storeDiagnostic( $code, 'failed' );
		}
		$this->clearPendingInstall();
		return new \WP_Error( $code, $message );
	}

	private static function errorCode( \WP_Error $error ): string {
		$callable = array( $error, 'get_error_code' );
		if ( ! is_callable( $callable ) ) {
			return 'github_updater_error';
		}

		$code = call_user_func( $callable );
		return is_string( $code ) && '' !== $code
			? $code
			: 'github_updater_error';
	}

	private static function configurationError( string $field, string $message ): \WP_Error {
		return new \WP_Error( 'github_updater_invalid_' . $field, $message );
	}

	/**
	 * @return 'manual'|'automatic'|'forced-off'|'disabled'|null
	 */
	private static function normalizePolicy( mixed $policy ): ?string {
		if ( ! is_string( $policy ) ) {
			return null;
		}

		return match ( strtolower( $policy ) ) {
			'manual', 'site-controlled' => 'manual',
			'automatic', 'forced-on'    => 'automatic',
			'forced-off'                => 'forced-off',
			'disabled'                  => 'disabled',
			default                     => null,
		};
	}

	/**
	 * Whether Core's upgrader context belongs to this exact target.
	 *
	 * @param array<string, mixed> $hookExtra Core upgrader context.
	 */
	private function matchesHookExtra( array $hookExtra ): bool {
		$key = 'plugin' === $this->targetType ? 'plugin' : 'theme';
		return ( $hookExtra[ $key ] ?? null ) === $this->pluginBasename;
	}

	/**
	 * Read standard package headers without assuming wp-admin helpers are
	 * loaded.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function readPackageData( string $pluginFile, string $targetType ) {
		if ( 'plugin' === $targetType && function_exists( 'get_plugin_data' ) ) {
			return get_plugin_data( $pluginFile, false, false );
		}
		if ( ! function_exists( 'get_file_data' ) ) {
			return self::configurationError(
				'package_metadata',
				'WordPress package metadata functions are unavailable.'
			);
		}

		return get_file_data(
			$pluginFile,
			self::packageHeaders( $targetType ),
			'plugin' === $targetType ? 'plugin' : 'theme'
		);
	}

	/** @return array<string, string> */
	private static function packageHeaders( string $targetType ): array {
		return array(
			'Name'        => 'plugin' === $targetType ? 'Plugin Name' : 'Theme Name',
			'PluginURI'   => 'plugin' === $targetType ? 'Plugin URI' : 'Theme URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
			'UpdateURI'   => 'Update URI',
		);
	}

	/**
	 * Read Core's fixed package headers through the active filesystem transport.
	 *
	 * @return array<string, string>|\WP_Error
	 */
	private static function readStagedPackageData(
		string $pluginFile,
		string $targetType,
		object $filesystem
	) {
		$sizeReader     = array( $filesystem, 'size' );
		$contentsReader = array( $filesystem, 'get_contents' );
		if ( ! is_callable( $sizeReader ) || ! is_callable( $contentsReader ) ) {
			return self::configurationError(
				'package_metadata',
				'The staged package metadata file cannot be read through WordPress.'
			);
		}
		$size = $sizeReader( $pluginFile );
		if ( ! is_int( $size ) || $size < 0 || $size > self::MAX_STAGED_METADATA_FILE_BYTES ) {
			return self::configurationError(
				'package_metadata',
				'The staged package metadata file exceeds the supported read limit.'
			);
		}
		$contents = $contentsReader( $pluginFile );
		if ( ! is_string( $contents )
			|| strlen( $contents ) !== $size
		) {
			return self::configurationError(
				'package_metadata',
				'The staged package metadata file could not be read safely.'
			);
		}

		$contents = str_replace( "\r", "\n", substr( $contents, 0, self::PACKAGE_HEADER_BYTES ) );
		$headers  = self::packageHeaders( $targetType );
		foreach ( $headers as $field => $header ) {
			$matched           = preg_match(
				'/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi',
				$contents,
				$match
			);
			$value             = 1 === $matched && ! empty( $match[1] )
				? preg_replace( '/\s*(?:\*\/|\?>).*/', '', $match[1] )
				: '';
			$headers[ $field ] = is_string( $value ) ? trim( $value ) : '';
		}

		return $headers;
	}
}
