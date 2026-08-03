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
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ProspectiveReleaseCandidate;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseArtifactClient;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseAssurance;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseDiscovery;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseFingerprint;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseInspection;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseOperationClaim;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseOperationCoordinator;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ValidatedReleaseArtifact;
use Tests\Support\FakeWpdb;
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

final class ReleaseCandidatePreflightTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		WordPressState::reset();
		$GLOBALS['wp_version'] = '6.5';
	}

	public function testFactoryRejectsNonStringRepositoryWithoutThrowing(): void {
		$client = $this->clientWithOlderValidRelease();

		$result = ReleaseCandidatePreflight::fromTarget(
			array(
				'repository' => 123,
			),
			$client
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_invalid_preflight_target', $result->get_error_code() );
	}

	public function testProspectiveFactoryRequiresStableRepositoryIdentity(): void {
		$target = array(
			'repository'  => 'RocketsAreNostalgic/example-plugin',
			'channel'     => 'stable',
			'accessToken' => null,
			'packageType' => 'plugin',
		);

		$missing = ReleaseCandidatePreflight::fromProspectiveTarget( $target );
		self::assertInstanceOf( \WP_Error::class, $missing );
		self::assertSame( 'github_updater_invalid_preflight_target', $missing->get_error_code() );

		$target['providerRepositoryId'] = 'not-numeric';
		$invalid                        = ReleaseCandidatePreflight::fromProspectiveTarget( $target );
		self::assertInstanceOf( \WP_Error::class, $invalid );
		self::assertSame( 'github_updater_invalid_preflight_target', $invalid->get_error_code() );

		$target['providerRepositoryId'] = '123456789';
		self::assertInstanceOf(
			ReleaseCandidatePreflight::class,
			ReleaseCandidatePreflight::fromProspectiveTarget( $target )
		);
	}

	public function testBrokenNewestReleaseFailsClosedWithoutInspectingOrCachingOlderRelease(): void {
		$client = $this->clientWithOlderValidRelease();
		array_unshift( $client->releases, $this->summary( 99, '2.0.0' ) );
		$client->descriptions[99] = new \WP_Error(
			'github_updater_missing_asset_digest',
			'The newest release has a broken trust contract.'
		);

		$result = $this->preflight( $client )->check();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_missing_asset_digest', $result->get_error_code() );
		self::assertSame( array( 99 ), $client->describedReleaseIds );
		self::assertSame( 0, $client->acquireCalls );
		self::assertSame( array(), WordPressState::$siteTransients );
	}

	public function testIncompatibleNewestReleaseFallsBackToOlderValidRelease(): void {
		$client = $this->clientWithOlderValidRelease();
		array_unshift( $client->releases, $this->summary( 99, '2.0.0' ) );
		$client->descriptions[99]       = $this->descriptor( releaseId: 99, version: '2.0.0' );
		$client->incompatibleReleaseIds = array( 99 );

		$result = $this->preflight( $client )->check();

		self::assertInstanceOf( CandidateValidation::class, $result );
		self::assertTrue( $result->isReady() );
		self::assertSame( '1.2.3', $result->releaseVersion() );
		self::assertSame( array( 99, 42 ), $client->describedReleaseIds );
		self::assertSame( 2, $client->acquireCalls );
	}

	public function testTransientNewestReleaseFailureFailsClosed(): void {
		$client = $this->clientWithOlderValidRelease();
		array_unshift( $client->releases, $this->summary( 99, '2.0.0' ) );
		$client->descriptions[99] = new \WP_Error(
			'github_updater_http_failed',
			'The exact release request failed.'
		);

		$result = $this->preflight( $client )->check();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_http_failed', $result->get_error_code() );
		self::assertSame( array( 99 ), $client->describedReleaseIds );
		self::assertSame( array(), WordPressState::$siteTransients );
	}

	public function testAllIncompatibleReleasesReturnNoCandidateWithoutAcquiringAnArchive(): void {
		$client                         = $this->clientWithOlderValidRelease();
		$client->incompatibleReleaseIds = array( 42 );

		$result = $this->preflight( $client )->check();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_no_eligible_release', $result->get_error_code() );
		self::assertSame( array( 42 ), $client->describedReleaseIds );
		self::assertSame( 1, $client->acquireCalls );
	}

	public function testAcquisitionFailurePreservesTheExactProviderError(): void {
		$client               = $this->clientWithOlderValidRelease();
		$client->acquireError = new \WP_Error(
			'download_failed',
			'A private temporary path that must not escape.'
		);

		$result = $this->preflight( $client )->check();

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'download_failed', $result->get_error_code() );
		self::assertSame( 1, $client->acquireCalls );
	}

	public function testListFailureAndCooldownPreserveProviderTruth(): void {
		$client            = $this->clientWithOlderValidRelease();
		$client->listError = new \WP_Error(
			'github_updater_github_authentication_failed',
			'Authentication failed.'
		);

		$authentication = $this->preflight( $client )->check();
		self::assertInstanceOf( \WP_Error::class, $authentication );
		self::assertSame(
			'github_updater_github_authentication_failed',
			$authentication->get_error_code()
		);

		$client->listError = null;
		$client->rateLimit = new RateLimit( RateLimit::LIMITED, 0, null, 321 );
		$preflight         = $this->preflight( $client );
		$limited           = $preflight->check( true );
		self::assertInstanceOf( \WP_Error::class, $limited );
		self::assertSame( 'github_updater_rate_limited', $limited->get_error_code() );
		self::assertSame( array( 'cooldown' => 321 ), $limited->get_error_data() );
		$cached = $preflight->check();
		self::assertInstanceOf( \WP_Error::class, $cached );
		self::assertSame( 'github_updater_rate_limited', $cached->get_error_code() );
		self::assertSame( 2, $client->listCalls );
	}

	public function testValidReleaseUsesCacheUnlessForceRequestsFreshValidation(): void {
		$client    = $this->clientWithOlderValidRelease();
		$preflight = $this->preflight( $client );

		$first  = $preflight->check();
		$second = $preflight->check();
		$third  = $preflight->check( true );

		self::assertInstanceOf( CandidateValidation::class, $first );
		self::assertTrue( $first->isReady() );
		self::assertInstanceOf( CandidateValidation::class, $second );
		self::assertTrue( $second->isReady() );
		self::assertInstanceOf( CandidateValidation::class, $third );
		self::assertTrue( $third->isReady() );
		self::assertSame( 2, $client->listCalls );
		self::assertSame( array( 42, 42 ), $client->describedReleaseIds );
		self::assertSame( 2, $client->acquireCalls );
		self::assertInstanceOf( FakeWpdb::class, $GLOBALS['wpdb'] );
		self::assertCount( 1, $GLOBALS['wpdb']->rows['wp_options'] );
	}

	public function testColdFollowerReceivesRetryableCheckInProgressError(): void {
		$client    = $this->clientWithOlderValidRelease();
		$preflight = $this->preflight( $client );
		$method    = new \ReflectionMethod( ReleaseCandidatePreflight::class, 'coordinationTargetKey' );
		$target    = $method->invoke( $preflight );
		self::assertIsString( $target );
		$coordinator = new ReleaseOperationCoordinator();
		$claim       = $coordinator->acquire( $target, 'native_discovery:test', 30 );
		self::assertInstanceOf( ReleaseOperationClaim::class, $claim );

		$result = $preflight->check();
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_check_in_progress', $result->get_error_code() );
		self::assertSame( array( 'retryable' => true ), $result->get_error_data() );
		self::assertSame( 0, $client->listCalls );
		self::assertTrue( $coordinator->release( $claim ) );
	}

	public function testManagedCacheIdentityChangesWithWordPressRuntime(): void {
		$client    = $this->clientWithOlderValidRelease();
		$method    = new \ReflectionMethod( ReleaseCandidatePreflight::class, 'cacheKey' );
		$preflight = $this->preflight( $client );
		$firstKey  = $method->invoke( $preflight );

		$GLOBALS['wp_version'] = '6.6';
		$secondKey             = $method->invoke( $this->preflight( $client ) );

		self::assertNotSame(
			$firstKey,
			$secondKey,
			'A managed compatibility verdict must not survive a WordPress runtime change.'
		);
	}

	public function testManagedCacheDoesNotBypassANewAssuranceProfile(): void {
		ReleaseAssurance::selectForRequest();
		$client = $this->clientWithOlderValidRelease();
		$first  = $this->preflight( $client )->check();
		self::assertInstanceOf( CandidateValidation::class, $first );

		$checks                     = 0;
		WordPressState::$actions    = array();
		WordPressState::$didActions = array();
		add_action(
			ReleaseAssurance::REGISTRATION_ACTION,
			static function ( ReleaseAssurance $assurance ) use ( &$checks ): void {
				self::assertTrue(
					$assurance->register(
						static function () use ( &$checks ): \WP_Error {
							++$checks;
							return new \WP_Error( 'fixture_tightened_assurance', 'Rejected.' );
						}
					)
				);
			}
		);
		ReleaseAssurance::selectForRequest();

		$result = $this->preflight( $client )->check();

		self::assertSame( 1, $checks, 'The newly selected assurance profile must run on cached readiness.' );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'fixture_tightened_assurance', $result->get_error_code() );

		WordPressState::reset();
		ReleaseAssurance::selectForRequest();
	}

	public function testDiscoverReturnsNewestReleaseWithoutDescribingOrAcquiringZip(): void {
		$client = $this->clientWithOlderValidRelease();
		array_unshift( $client->releases, $this->summary( 99, '2.0.0' ) );

		$result = $this->prospectivePreflight( $client )->discover();

		self::assertInstanceOf( ReleaseDiscovery::class, $result );
		self::assertSame( 99, $result->releaseId() );
		self::assertSame( 'v2.0.0', $result->tag() );
		self::assertSame( '2.0.0', $result->version() );
		self::assertSame( 1, $client->listCalls );
		self::assertSame( array(), $client->describedReleaseIds );
		self::assertSame( 0, $client->acquireCalls );
	}

	public function testListCandidatesReturnsEightBoundedDisplaySafeSummariesWithoutInspection(): void {
		$client           = $this->clientWithOlderValidRelease();
		$client->releases = array();
		for ( $index = 10; $index >= 1; --$index ) {
			$client->releases[] = $this->summary(
				$index,
				'2.0.0-beta.' . $index,
				true,
				'2026-07-' . str_pad( (string) $index, 2, '0', STR_PAD_LEFT ) . 'T12:00:00Z'
			);
		}

		$result = $this->prospectivePreflight( $client )->listCandidates();

		self::assertIsArray( $result );
		self::assertCount( ReleaseQuery::MAX_CANDIDATE_DESCRIPTIONS, $result );
		self::assertContainsOnlyInstancesOf( ProspectiveReleaseCandidate::class, $result );
		self::assertSame( 10, $result[0]->releaseId() );
		self::assertSame( 'v2.0.0-beta.10', $result[0]->tag() );
		self::assertSame( '2.0.0-beta.10', $result[0]->version() );
		self::assertTrue( $result[0]->isPrerelease() );
		self::assertSame( '2026-07-10T12:00:00Z', $result[0]->publishedAt() );
		self::assertSame(
			array(
				'example-plugin-2.0.0-beta.10.zip',
			),
			$result[0]->expectedAssetNames()
		);
		self::assertSame( 1, $client->listCalls );
		self::assertSame( array(), $client->describedReleaseIds );
		self::assertSame( 0, $client->acquireCalls );
	}

	public function testInspectExactReturnsZipIdentityAndDiscardsReviewedArtifact(): void {
		$client = $this->clientWithOlderValidRelease();

		$result = $this->prospectivePreflight( $client )->inspectExact( 42, 'v1.2.3' );

		self::assertNotInstanceOf(
			\WP_Error::class,
			$result,
			$result instanceof \WP_Error ? $result->get_error_code() : ''
		);
		self::assertInstanceOf( ReleaseInspection::class, $result );
		self::assertSame( 42, $result->releaseId() );
		self::assertSame( 'v1.2.3', $result->tag() );
		self::assertSame( '1.2.3', $result->version() );
		self::assertSame( str_repeat( '1', 40 ), $result->commit() );
		self::assertSame( 'plugin', $result->packageType() );
		self::assertSame( 'example-plugin', $result->packageRoot() );
		self::assertSame( 'example-plugin.php', $result->mainFile() );
		self::assertMatchesRegularExpression( '/\Av1:[a-f0-9]{64}\z/D', $result->fingerprint()->value() );
		self::assertFalse( method_exists( $result, 'descriptor' ) );
		self::assertSame( array( 42 ), $client->describedReleaseIds );
		self::assertSame( 1, $client->acquireCalls );
		self::assertCount( 1, $client->artifactPaths );
		self::assertFileDoesNotExist( $client->artifactPaths[0] );
	}

	public function testInspectExactDiscoversThemeStyleSheetIdentity(): void {
		$client = $this->clientWithOlderValidRelease();

		$result = $this->prospectivePreflight( $client, 'theme' )->inspectExact( 42, 'v1.2.3' );

		self::assertInstanceOf( ReleaseInspection::class, $result );
		self::assertSame( 'theme', $result->packageType() );
		self::assertSame( 'example-theme', $result->packageRoot() );
		self::assertSame( 'style.css', $result->mainFile() );
		self::assertSame( 1, $client->acquireCalls );
		self::assertFileDoesNotExist( $client->artifactPaths[0] );
	}

	public function testInspectExactRejectsIncompatibleProspectiveZip(): void {
		$client                         = $this->clientWithOlderValidRelease();
		$client->incompatibleReleaseIds = array( 42 );

		$result = $this->prospectivePreflight( $client )->inspectExact( 42, 'v1.2.3' );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( CandidateValidation::RELEASE_INCOMPATIBLE, $result->get_error_code() );
		self::assertSame( 1, $client->acquireCalls );
		self::assertFileDoesNotExist( $client->artifactPaths[0] );
	}

	public function testAcquireExactBindsInspectionAndTransfersValidatedZipOnce(): void {
		$client     = $this->clientWithOlderValidRelease();
		$preflight  = $this->prospectivePreflight( $client );
		$inspection = $preflight->inspectExact( 42, 'v1.2.3' );
		self::assertInstanceOf( ReleaseInspection::class, $inspection );

		$result = $preflight->acquireExact(
			42,
			'v1.2.3',
			$inspection->fingerprint()
		);

		self::assertInstanceOf( ValidatedReleaseArtifact::class, $result );
		self::assertSame( array( 42, 42 ), $client->describedReleaseIds );
		self::assertSame( 2, $client->acquireCalls );
		self::assertCount( 2, $client->artifactPaths );
		self::assertFileDoesNotExist( $client->artifactPaths[0] );
		self::assertFileExists( $client->artifactPaths[1] );
		self::assertSame( '1.2.3', $result->inspection()->version() );
		$claimed = $result->handoffToCore();
		self::assertNotInstanceOf( \WP_Error::class, $claimed );
		self::assertFileExists( $claimed->path() );
		self::assertInstanceOf( \WP_Error::class, $result->handoffToCore() );

		unlink( $claimed->path() );
	}

	public function testAcquireExactRejectsChangedFingerprintAfterFreshZipValidation(): void {
		$client     = $this->clientWithOlderValidRelease();
		$preflight  = $this->prospectivePreflight( $client );
		$inspection = $preflight->inspectExact( 42, 'v1.2.3' );
		self::assertInstanceOf( ReleaseInspection::class, $inspection );
		$client->descriptions[42] = $this->descriptor( commit: str_repeat( '2', 40 ) );

		$result = $preflight->acquireExact( 42, 'v1.2.3', $inspection->fingerprint() );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'github_updater_artifact_continuity_failed', $result->get_error_code() );
		self::assertSame( 2, $client->acquireCalls );
	}

	public function testAcquireExactRerunsAssuranceAgainstTheFreshInstallationZip(): void {
		$checks = 0;
		add_action(
			ReleaseAssurance::REGISTRATION_ACTION,
			static function ( ReleaseAssurance $assurance ) use ( &$checks ): void {
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
			}
		);
		ReleaseAssurance::selectForRequest();

		$client     = $this->clientWithOlderValidRelease();
		$preflight  = $this->prospectivePreflight( $client );
		$inspection = $preflight->inspectExact( 42, 'v1.2.3' );
		self::assertInstanceOf( ReleaseInspection::class, $inspection );
		self::assertSame( 1, $checks );

		$result = $preflight->acquireExact(
			42,
			'v1.2.3',
			$inspection->fingerprint()
		);

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'fixture_fresh_assurance_rejected', $result->get_error_code() );
		self::assertSame( 2, $checks );
		self::assertNotNull( $client->lastArtifactPath );
		self::assertFileDoesNotExist( $client->lastArtifactPath );

		WordPressState::reset();
		ReleaseAssurance::selectForRequest();
	}

	public function testValidatedArtifactDiscardIsIdempotent(): void {
		$client     = $this->clientWithOlderValidRelease();
		$preflight  = $this->prospectivePreflight( $client );
		$inspection = $preflight->inspectExact( 42, 'v1.2.3' );
		self::assertInstanceOf( ReleaseInspection::class, $inspection );
		$result = $preflight->acquireExact( 42, 'v1.2.3', $inspection->fingerprint() );
		self::assertInstanceOf( ValidatedReleaseArtifact::class, $result );

		self::assertTrue( $result->discard() );
		self::assertTrue( $result->discard() );

		self::assertNotNull( $client->lastArtifactPath );
		self::assertFileDoesNotExist( $client->lastArtifactPath );
		self::assertInstanceOf( \WP_Error::class, $result->handoffToCore() );
	}

	private function preflight( PreflightReleaseArtifactClient $client ): ReleaseCandidatePreflight {
		$target = array(
			'repository'    => 'RocketsAreNostalgic/example-plugin',
			'pluginSlug'    => 'example-plugin',
			'mainFile'      => 'example-plugin.php',
			'channel'       => 'stable',
			'accessToken'   => null,
			'cacheDuration' => 21600,
		);

		$preflight = ReleaseCandidatePreflight::fromTarget(
			$target,
			$client,
			static fn (): int => 1000
		);
		self::assertInstanceOf( ReleaseCandidatePreflight::class, $preflight );
		return $preflight;
	}

	private function prospectivePreflight(
		PreflightReleaseArtifactClient $client,
		string $packageType = 'plugin'
	): ReleaseCandidatePreflight {
		$client->packageType = $packageType;
		$factory             = new \ReflectionMethod(
			ReleaseCandidatePreflight::class,
			'fromProspectiveTargetWithClient'
		);
		self::assertFalse( $factory->isPublic() );
		$preflight = $factory->invoke(
			null,
			array(
				'repository'           => 'RocketsAreNostalgic/example-plugin',
				'providerRepositoryId' => '123456789',
				'channel'              => 'stable',
				'accessToken'          => null,
				'packageType'          => $packageType,
			),
			$client
		);
		self::assertInstanceOf( ReleaseCandidatePreflight::class, $preflight );

		return $preflight;
	}

	private function clientWithOlderValidRelease(): PreflightReleaseArtifactClient {
		$descriptor = $this->descriptor();
		return new PreflightReleaseArtifactClient(
			array( $this->summary( 42, '1.2.3' ) ),
			array( 42 => $descriptor )
		);
	}

	private function summary(
		int $releaseId,
		string $version,
		bool $prerelease = false,
		string $publishedAt = '2026-07-24T12:00:00Z'
	): ReleaseSummary {
		return new ReleaseSummary(
			$releaseId,
			'v' . $version,
			$version,
			$prerelease,
			$publishedAt,
			array(
				'example-plugin-' . $version . '.zip',
			),
			false
		);
	}

	private function descriptor(
		?string $commit = null,
		string $version = '1.2.3',
		int $releaseId = 42,
		bool $immutable = false
	): ArtifactDescriptor {
		$repository = Repository::fromString( 'RocketsAreNostalgic/example-plugin' );
		self::assertInstanceOf( Repository::class, $repository );
		$query  = new ReleaseQuery( $repository, ReleaseQuery::STABLE, '8.2', '6.5' );
		$commit = $commit ?? str_repeat( '1', 40 );

		return new ArtifactDescriptor(
			$query,
			$repository,
			$releaseId,
			'v' . $version,
			$version,
			$commit,
			false,
			'https://github.com/RocketsAreNostalgic/example-plugin/releases/tag/v' . $version,
			new ReleaseAsset( 101, 'example-plugin-' . $version . '.zip', 18, str_repeat( 'a', 64 ) ),
			$immutable
		);
	}
}

final class PreflightReleaseArtifactClient implements ReleaseArtifactClient {

	public int $listCalls = 0;

	public int $acquireCalls = 0;

	public ?\WP_Error $acquireError = null;

	public ?\WP_Error $listError = null;

	public ?RateLimit $rateLimit = null;

	public ?string $lastArtifactPath = null;

	/** @var list<string> */
	public array $artifactPaths = array();

	/** @var list<int> */
	public array $describedReleaseIds = array();

	/** @var list<int> */
	public array $incompatibleReleaseIds = array();

	public string $packageType = 'plugin';

	public bool $searchExhausted = false;

	/**
	 * @param list<ReleaseSummary> $releases
	 * @param array<int, ArtifactDescriptor|\WP_Error> $descriptions
	 */
	public function __construct(
		public array $releases,
		public array $descriptions
	) {
	}

	public function listReleases( ReleaseQuery $query ) {
		++$this->listCalls;
		if ( null !== $this->listError ) {
			return $this->listError;
		}
		return new ReleaseListResult(
			$this->releases,
			new ConditionalState(),
			$this->rateLimit ?? new RateLimit(),
			false,
			$this->searchExhausted
		);
	}

	public function describeExact( ExactReleaseRequest $request ) {
		$this->describedReleaseIds[] = $request->releaseId();
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
		if ( $path instanceof \WP_Error ) {
			return $path;
		}
		$this->lastArtifactPath = $path;
		$this->artifactPaths[]  = $path;
		$zip                    = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'zip_failed', 'Test ZIP creation failed.' );
		}
		$version     = $descriptor->version();
		$requiresPhp = in_array( $descriptor->releaseId(), $this->incompatibleReleaseIds, true )
			? '99.0'
			: '8.0';
		if ( 'theme' === $this->packageType ) {
			$zip->addFromString(
				'example-theme/style.css',
				"/*\nTheme Name: Example Theme\nVersion: {$version}\nUpdate URI: https://github.com/RocketsAreNostalgic/example-plugin\nRequires PHP: {$requiresPhp}\nRequires at least: 6.5\n*/\n"
			);
		} else {
			$zip->addFromString(
				'example-plugin/example-plugin.php',
				"<?php\n/*\nPlugin Name: Example Plugin\nVersion: {$version}\nUpdate URI: https://github.com/RocketsAreNostalgic/example-plugin\nRequires PHP: {$requiresPhp}\nRequires at least: 6.5\n*/\n"
			);
		}
		$zip->close();
		$bytes    = file_get_contents( $path );
		$identity = VerifiedArtifact::fileIdentity( $path );
		if ( ! is_string( $bytes ) || null === $identity ) {
			return new \WP_Error( 'identity_failed', 'Test artifact identity unavailable.' );
		}

		return new VerifiedArtifact(
			$path,
			hash( 'sha256', $bytes ),
			$temporaryFiles,
			$identity
		);
	}
}
