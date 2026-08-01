<?php

declare(strict_types=1);

namespace Tests\Artifact;

use PHPUnit\Framework\TestCase;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\AccessToken;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ConditionalState;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ExactReleaseRequest;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\ReleaseQuery;
use RAN\WPGitHubReleaseUpdater\V1\Artifact\Repository;
use RAN\WPGitHubReleaseUpdater\V1\Http\Response;

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
 * Hook-free native GitHub release ZIP tests.
 */
final class GitHubReleaseArtifactServiceTest extends TestCase {
	private const COMMIT     = '0123456789abcdef0123456789abcdef01234567';
	private const REPOSITORY = 'RocketsAreNostalgic/example-plugin';
	private const TAG        = 'v1.2.3';
	private const VERSION    = '1.2.3';
	private const ZIP        = 'verified zip bytes';

	public function testListingIgnoresNonZipAssetsAndCarriesImmutableEvidence(): void {
		$transport           = new FakeTransport();
		$release             = $this->release();
		$release['assets'][] = array(
			'id'    => 202,
			'name'  => 'checksums.json',
			'size'  => 12,
			'state' => 'uploaded',
		);
		$transport->queue(
			new Response( 200, array(), $this->json( array( $release ) ) )
		);

		$result = $this->service( $transport )->listReleases( $this->query() );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertCount( 1, $result->releases() );
		self::assertSame(
			array( 'example-plugin-1.2.3.zip' ),
			$result->releases()[0]->expectedAssetNames()
		);
		self::assertTrue( $result->releases()[0]->isImmutable() );
	}

	public function testListingRetainsMalformedZipCandidateForExactFailClosedInspection(): void {
		$transport           = new FakeTransport();
		$release             = $this->release();
		$release['assets'][] = array_replace(
			$release['assets'][0],
			array(
				'id'   => 202,
				'name' => 'second.zip',
			)
		);
		$transport->queue(
			new Response( 200, array(), $this->json( array( $release ) ) )
		);

		$result = $this->service( $transport )->listReleases( $this->query() );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertCount( 1, $result->releases() );
		self::assertSame(
			array( 'example-plugin-1.2.3.zip', 'second.zip' ),
			$result->releases()[0]->expectedAssetNames()
		);
	}

	public function testListingSortsAndFiltersReleaseChannels(): void {
		$transport = new FakeTransport();
		$transport->queue(
			new Response(
				200,
				array(),
				$this->json(
					array(
						$this->listRelease( 1, 'v1.1.0', false ),
						$this->listRelease( 2, 'v2.0.0-beta.1', true ),
						$this->listRelease( 3, 'v1.3.0', false ),
					)
				)
			)
		);

		$stable = $this->service( $transport )->listReleases( $this->query() );

		self::assertNotInstanceOf( \WP_Error::class, $stable );
		self::assertSame(
			array( '1.3.0', '1.1.0' ),
			array_map( static fn ( $item ): string => $item->version(), $stable->releases() )
		);

		$transport = new FakeTransport();
		$transport->queue(
			new Response(
				200,
				array(),
				$this->json(
					array(
						$this->listRelease( 1, 'v1.1.0', false ),
						$this->listRelease( 2, 'v2.0.0-beta.1', true ),
					)
				)
			)
		);
		$prerelease = $this->service( $transport )->listReleases(
			$this->query( ReleaseQuery::PRERELEASE )
		);

		self::assertNotInstanceOf( \WP_Error::class, $prerelease );
		self::assertSame(
			array( '2.0.0-beta.1', '1.1.0' ),
			array_map( static fn ( $item ): string => $item->version(), $prerelease->releases() )
		);
	}

	public function testConditionalListingRetainsValidatorsAndBoundsRequests(): void {
		$transport = new FakeTransport();
		$transport->queue(
			new Response(
				304,
				array(
					'ETag'          => '"new"',
					'Last-Modified' => 'Wed, 01 Jul 2026 12:00:00 GMT',
				)
			)
		);
		$query  = $this->query(
			ReleaseQuery::STABLE,
			new ConditionalState( '"old"', 'Tue, 30 Jun 2026 12:00:00 GMT' )
		);
		$result = $this->service( $transport )->listReleases( $query );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertTrue( $result->isNotModified() );
		self::assertSame( '"new"', $result->conditional()->etag() );
		self::assertSame( '"old"', $transport->requests()[0]->headers()['If-None-Match'] );
		self::assertSame( 10, $transport->requests()[0]->timeout() );
		self::assertSame( 0, $transport->requests()[0]->redirection() );
	}

	/**
	 * @dataProvider invalidZipProvider
	 *
	 * @param callable(array<string, mixed>): array<string, mixed> $mutate
	 */
	public function testExactDescriptionRejectsInvalidZipContracts(
		callable $mutate,
		string $expectedCode
	): void {
		$transport = new FakeTransport();
		$release   = $mutate( $this->release() );
		$transport->queue( new Response( 200, array(), $this->json( $release ) ) );

		$result = $this->service( $transport )->describeExact(
			new ExactReleaseRequest( $this->query(), 77, self::TAG )
		);

		$this->assertErrorCode( $expectedCode, $result );
		self::assertCount( 1, $transport->requests() );
	}

	/**
	 * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
	 */
	public static function invalidZipProvider(): array {
		return array(
			'missing ZIP'        => array(
				static function ( array $release ): array {
					$release['assets'][0]['name'] = 'package.tar.gz';
					return $release;
				},
				'github_updater_ambiguous_release_asset',
			),
			'multiple ZIPs'      => array(
				static function ( array $release ): array {
					$release['assets'][] = $release['assets'][0];
					return $release;
				},
				'github_updater_ambiguous_release_asset',
			),
			'not uploaded'       => array(
				static function ( array $release ): array {
					$release['assets'][0]['state'] = 'new';
					return $release;
				},
				'github_updater_invalid_release_asset',
			),
			'missing digest'     => array(
				static function ( array $release ): array {
					unset( $release['assets'][0]['digest'] );
					return $release;
				},
				'github_updater_missing_asset_digest',
			),
			'unsupported digest' => array(
				static function ( array $release ): array {
					$release['assets'][0]['digest'] = 'sha512:' . str_repeat( 'a', 64 );
					return $release;
				},
				'github_updater_missing_asset_digest',
			),
			'zero size'          => array(
				static function ( array $release ): array {
					$release['assets'][0]['size'] = 0;
					return $release;
				},
				'github_updater_invalid_release_asset',
			),
			'oversized ZIP'      => array(
				static function ( array $release ): array {
					$release['assets'][0]['size'] = 52428801;
					return $release;
				},
				'github_updater_release_asset_too_large',
			),
		);
	}

	public function testZipSuffixIsCaseInsensitiveButIdentityPreservesExactName(): void {
		$transport                    = new FakeTransport();
		$release                      = $this->release();
		$release['assets'][0]['name'] = 'Friendly-Package.ZIP';
		$this->queueDescription( $transport, $release );

		$result = $this->service( $transport )->describeExact(
			new ExactReleaseRequest( $this->query(), 77, self::TAG )
		);

		self::assertInstanceOf( ArtifactDescriptor::class, $result );
		self::assertSame( 'Friendly-Package.ZIP', $result->zipAsset()->name() );
	}

	/**
	 * @dataProvider invalidReleaseStateProvider
	 */
	public function testExactDescriptionRequiresBooleanReleaseState(
		string $key,
		mixed $value,
		bool $remove
	): void {
		$transport = new FakeTransport();
		$release   = $this->release();
		if ( $remove ) {
			unset( $release[ $key ] );
		} else {
			$release[ $key ] = $value;
		}
		$transport->queue( new Response( 200, array(), $this->json( $release ) ) );

		$result = $this->service( $transport )->describeExact(
			new ExactReleaseRequest( $this->query(), 77, self::TAG )
		);

		$this->assertErrorCode( 'github_updater_invalid_release', $result );
	}

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: bool}>
	 */
	public static function invalidReleaseStateProvider(): array {
		return array(
			'missing draft'      => array( 'draft', null, true ),
			'invalid draft'      => array( 'draft', 'false', false ),
			'missing prerelease' => array( 'prerelease', null, true ),
			'invalid prerelease' => array( 'prerelease', 0, false ),
			'missing immutable'  => array( 'immutable', null, true ),
			'invalid immutable'  => array( 'immutable', 1, false ),
		);
	}

	public function testDescriptorBindsReleaseCommitZipAndImmutableState(): void {
		$transport = new FakeTransport();
		$this->queueDescription( $transport, $this->release() );

		$result = $this->service( $transport )->describeExact(
			new ExactReleaseRequest( $this->query(), 77, self::TAG )
		);

		self::assertInstanceOf( ArtifactDescriptor::class, $result );
		self::assertSame( self::COMMIT, $result->commit() );
		self::assertSame( 101, $result->zipAsset()->id() );
		self::assertSame( strlen( self::ZIP ), $result->zipAsset()->size() );
		self::assertSame( hash( 'sha256', self::ZIP ), $result->zipAsset()->sha256() );
		self::assertTrue( $result->isImmutable() );
		self::assertCount( 2, $transport->requests() );
	}

	public function testFreshDescriptionExposesChangedZipAndImmutableIdentity(): void {
		$transport = new FakeTransport();
		$service   = $this->service( $transport );
		$this->queueDescription( $transport, $this->release() );
		$descriptor = $service->describeExact(
			new ExactReleaseRequest( $this->query(), 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );

		$changed                    = $this->release();
		$changed['assets'][0]['id'] = 999;
		$this->queueDescription( $transport, $changed );
		$current = $service->describeExact(
			new ExactReleaseRequest( $this->query(), 77, self::TAG )
		);

		self::assertInstanceOf( ArtifactDescriptor::class, $current );
		self::assertFalse( $descriptor->equals( $current ) );
	}

	public function testAcquireDescribedDownloadsOnceAndTransfersCustodyOnce(): void {
		$transport      = new FakeTransport();
		$temporaryFiles = new FakeTemporaryFileFactory();
		$service        = $this->service( $transport, $temporaryFiles );
		$this->queueDescription( $transport, $this->release() );
		$descriptor = $service->describeExact(
			new ExactReleaseRequest( ReleaseQuery::prospective( $this->repository() ), 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );
		$transport->queue( new Response( 200 ), self::ZIP );

		$verified = $service->acquireDescribed( $descriptor );

		self::assertNotInstanceOf( \WP_Error::class, $verified );
		self::assertCount( 3, $transport->requests() );
		$claimed = $verified->claim();
		self::assertNotInstanceOf( \WP_Error::class, $claimed );
		self::assertInstanceOf( \WP_Error::class, $verified->claim() );
		self::assertFalse( $verified->discard() );
		self::assertFileExists( $claimed->path() );
		unlink( $claimed->path() );
	}

	public function testDownloadedSizeAndDigestAreCheckedAgainstGitHubEvidence(): void {
		$transport      = new FakeTransport();
		$temporaryFiles = new FakeTemporaryFileFactory();
		$service        = $this->service( $transport, $temporaryFiles );
		$this->queueDescription( $transport, $this->release() );
		$descriptor = $service->describeExact(
			new ExactReleaseRequest( ReleaseQuery::prospective( $this->repository() ), 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );
		$transport->queue( new Response( 200 ), 'short' );

		$result = $service->acquireDescribed( $descriptor );

		$this->assertErrorCode( 'github_updater_downloaded_artifact_invalid', $result );
		self::assertCount( 1, $temporaryFiles->deleted() );

		$transport                    = new FakeTransport();
		$temporaryFiles               = new FakeTemporaryFileFactory();
		$service                      = $this->service( $transport, $temporaryFiles );
		$release                      = $this->release();
		$release['assets'][0]['size'] = strlen( 'same size tamper!!' );
		$this->queueDescription( $transport, $release );
		$descriptor = $service->describeExact(
			new ExactReleaseRequest( ReleaseQuery::prospective( $this->repository() ), 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );
		$transport->queue( new Response( 200 ), 'same size tamper!!' );

		$result = $service->acquireDescribed( $descriptor );

		$this->assertErrorCode( 'github_updater_downloaded_digest_mismatch', $result );
		self::assertCount( 1, $temporaryFiles->deleted() );
	}

	public function testRepositoryIdentityIsCheckedForManagedRequests(): void {
		$transport = new FakeTransport();
		$transport->queue(
			new Response( 200, array(), $this->json( array( $this->release() ) ) )
		);
		$transport->queue(
			new Response(
				200,
				array(),
				$this->json(
					array(
						'id'        => 987654321,
						'full_name' => self::REPOSITORY,
					)
				)
			)
		);

		$repository = Repository::fromString( self::REPOSITORY, '123456789' );
		self::assertInstanceOf( Repository::class, $repository );
		$result = $this->service( $transport )->listReleases(
			ReleaseQuery::prospective( $repository )
		);

		$this->assertErrorCode( 'github_updater_repository_identity_changed', $result );
	}

	public function testMissingCallableCredentialFailsBeforeTransport(): void {
		$token = AccessToken::fromValue( static fn (): ?string => null );
		self::assertInstanceOf( AccessToken::class, $token );
		$transport = new FakeTransport();
		$query     = new ReleaseQuery(
			$this->repository(),
			ReleaseQuery::STABLE,
			'8.2',
			'6.8',
			5,
			null,
			$token
		);

		$result = $this->service( $transport )->listReleases( $query );

		$this->assertErrorCode( 'github_updater_credentials_unavailable', $result );
		self::assertSame( array(), $transport->requests() );
	}

	public function testOversizedReleaseResponseAndOrdinaryForbiddenStayDistinct(): void {
		$transport = new FakeTransport();
		$transport->queue( new Response( 200, array(), str_repeat( 'x', 262145 ) ) );
		$result = $this->service( $transport )->listReleases( $this->query() );
		$this->assertErrorCode( 'github_updater_response_too_large', $result );

		$transport = new FakeTransport();
		$transport->queue( new Response( 403, array( 'X-RateLimit-Remaining' => '10' ) ) );
		$result = $this->service( $transport )->listReleases( $this->query() );
		$this->assertErrorCode( 'github_updater_github_forbidden', $result );
	}

	public function testRateLimitCooldownIsBounded(): void {
		$transport = new FakeTransport();
		$transport->queue(
			new Response(
				429,
				array(
					'Retry-After'           => '999999',
					'X-RateLimit-Remaining' => '0',
					'X-RateLimit-Reset'     => '2500',
				)
			)
		);

		$result = ( new GitHubReleaseArtifactService(
			$transport,
			null,
			static fn (): int => 1000
		) )->listReleases( $this->query() );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertTrue( $result->rateLimit()->isLimited() );
		self::assertSame( 86400, $result->rateLimit()->cooldownSeconds() );
	}

	/**
	 * @dataProvider unsafeRedirectProvider
	 */
	public function testUnsafeAndExpiredRedirectsFailBeforeFollowing(
		string $location,
		string $expectedCode
	): void {
		$transport      = new FakeTransport();
		$temporaryFiles = new FakeTemporaryFileFactory();
		$service        = new GitHubReleaseArtifactService(
			$transport,
			$temporaryFiles,
			static fn (): int => 1000
		);
		$this->queueDescription( $transport, $this->release() );
		$descriptor = $service->describeExact(
			new ExactReleaseRequest( ReleaseQuery::prospective( $this->repository() ), 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );
		$transport->queue( new Response( 302, array( 'Location' => $location ) ) );

		$result = $service->acquireDescribed( $descriptor );

		$this->assertErrorCode( $expectedCode, $result );
		self::assertCount( 3, $transport->requests() );
		self::assertCount( 1, $temporaryFiles->deleted() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unsafeRedirectProvider(): array {
		return array(
			'foreign host' => array(
				'https://attacker.example/file?Expires=2000',
				'github_updater_unsafe_release_asset_redirect',
			),
			'expired URL'  => array(
				'https://release-assets.githubusercontent.com/file?Expires=999',
				'github_updater_expired_release_asset_url',
			),
			'invalid date' => array(
				'https://release-assets.githubusercontent.com/file?se=tomorrow',
				'github_updater_expired_release_asset_url',
			),
		);
	}

	public function testExcessiveRedirectsFailClosedAndCleanUp(): void {
		$transport      = new FakeTransport();
		$temporaryFiles = new FakeTemporaryFileFactory();
		$service        = new GitHubReleaseArtifactService(
			$transport,
			$temporaryFiles,
			static fn (): int => 1000
		);
		$this->queueDescription( $transport, $this->release() );
		$descriptor = $service->describeExact(
			new ExactReleaseRequest( ReleaseQuery::prospective( $this->repository() ), 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );
		for ( $hop = 1; $hop <= 2; ++$hop ) {
			$transport->queue(
				new Response(
					302,
					array(
						'Location' => 'https://release-assets.githubusercontent.com/hop-'
							. $hop
							. '?Expires=2000',
					)
				)
			);
		}

		$result = $service->acquireDescribed( $descriptor );

		$this->assertErrorCode( 'github_updater_redirect_limit_exceeded', $result );
		self::assertCount( 4, $transport->requests() );
		self::assertCount( 1, $temporaryFiles->deleted() );
	}

	/**
	 * @dataProvider reachabilityProvider
	 */
	public function testDefaultBranchReachabilityRequiresExactMergeBase(
		string $status,
		string $mergeBase,
		bool $expected
	): void {
		$transport = new FakeTransport();
		$transport->queue(
			new Response(
				200,
				array(),
				$this->json(
					array(
						'status'            => $status,
						'merge_base_commit' => array( 'sha' => $mergeBase ),
					)
				)
			)
		);

		$result = $this->service( $transport )->isCommitReachableFromBranch(
			$this->query(),
			self::COMMIT,
			'main'
		);

		self::assertSame( $expected, $result );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function reachabilityProvider(): array {
		return array(
			'ahead'            => array( 'ahead', self::COMMIT, true ),
			'identical'        => array( 'identical', self::COMMIT, true ),
			'behind'           => array( 'behind', self::COMMIT, false ),
			'wrong merge base' => array( 'ahead', str_repeat( 'a', 40 ), false ),
		);
	}

	public function testPrivateRedirectDropsAuthorizationOutsideGitHubApi(): void {
		$transport      = new FakeTransport();
		$temporaryFiles = new FakeTemporaryFileFactory();
		$token          = AccessToken::fromValue( 'private-token' );
		self::assertInstanceOf( AccessToken::class, $token );
		$query   = new ReleaseQuery(
			$this->repository(),
			ReleaseQuery::STABLE,
			'8.2',
			'6.8',
			5,
			null,
			$token
		);
		$service = new GitHubReleaseArtifactService(
			$transport,
			$temporaryFiles,
			static fn (): int => 1000
		);
		$this->queueDescription( $transport, $this->release() );
		$descriptor = $service->describeExact(
			new ExactReleaseRequest( $query, 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );
		$transport->queue(
			new Response(
				302,
				array(
					'Location' => 'https://release-assets.githubusercontent.com/file?Expires=2000',
				)
			)
		);
		$transport->queue( new Response( 200 ), self::ZIP );

		$verified = $service->acquireDescribed( $descriptor );

		self::assertNotInstanceOf( \WP_Error::class, $verified );
		$requests = $transport->requests();
		self::assertSame( 'Bearer private-token', $requests[2]->headers()['Authorization'] );
		self::assertArrayNotHasKey( 'Authorization', $requests[3]->headers() );
		self::assertTrue( $verified->discard() );
	}

	public function testCallableCredentialsResolveOncePerRequestChain(): void {
		$calls = 0;
		$token = AccessToken::fromValue(
			static function () use ( &$calls ): string {
				++$calls;
				return 'token-' . $calls;
			}
		);
		self::assertInstanceOf( AccessToken::class, $token );
		$transport = new FakeTransport();
		$transport->queue(
			new Response(
				302,
				array( 'Location' => 'https://api.github.com/next' )
			)
		);
		$transport->queue( new Response( 200, array(), '[]' ) );
		$query = new ReleaseQuery(
			$this->repository(),
			ReleaseQuery::STABLE,
			'8.2',
			'6.8',
			5,
			null,
			$token
		);

		$result = $this->service( $transport )->listReleases( $query );

		self::assertNotInstanceOf( \WP_Error::class, $result );
		self::assertSame( 1, $calls );
		self::assertSame( 'Bearer token-1', $transport->requests()[0]->headers()['Authorization'] );
		self::assertSame( 'Bearer token-1', $transport->requests()[1]->headers()['Authorization'] );
	}

	/**
	 * @dataProvider authenticationProvider
	 */
	public function testNativeZipOnlyUpdateUsesFiveOfferAndFourFreshInstallRequests(
		bool $authenticated
	): void {
		$credentialCalls = 0;
		$transport       = new FakeTransport();
		$service         = $this->service( $transport, new FakeTemporaryFileFactory() );
		$query           = $this->managedQuery( $authenticated, $credentialCalls );
		$release         = $this->releaseWithIgnoredSidecars();

		$transport->queue( new Response( 200, array(), $this->json( array( $release ) ) ) );
		for ( $phase = 0; $phase < 2; ++$phase ) {
			$this->queueDescription( $transport, $release );
			$transport->queue( new Response( 200 ), self::ZIP );
			$transport->queue( $this->repositoryResponse() );
		}

		$list = $service->listReleases( $query );
		self::assertNotInstanceOf( \WP_Error::class, $list );
		for ( $phase = 0; $phase < 2; ++$phase ) {
			$descriptor = $service->describeExact(
				new ExactReleaseRequest( $query, 77, self::TAG )
			);
			self::assertInstanceOf( ArtifactDescriptor::class, $descriptor );
			$artifact = $service->acquireDescribed( $descriptor );
			self::assertNotInstanceOf( \WP_Error::class, $artifact );
			self::assertTrue( $artifact->discard() );
			if ( 0 === $phase ) {
				self::assertCount( 5, $transport->requests() );
			}
		}

		$this->assertZipOnlyRequestBudget( $transport, 9, 2, $authenticated, $credentialCalls );
	}

	/**
	 * @dataProvider authenticationProvider
	 */
	public function testProspectiveZipOnlyJourneyUsesTwoListAndFiveRequestsPerExactPass(
		bool $authenticated
	): void {
		$credentialCalls = 0;
		$transport       = new FakeTransport();
		$service         = $this->service( $transport, new FakeTemporaryFileFactory() );
		$query           = $this->prospectiveQuery( $authenticated, $credentialCalls );
		$release         = $this->releaseWithIgnoredSidecars();

		$transport->queue( new Response( 200, array(), $this->json( array( $release ) ) ) );
		$transport->queue( $this->repositoryResponse() );

		$this->queueDescription( $transport, $release );
		$transport->queue( $this->reachableResponse() );
		$transport->queue( $this->repositoryResponse() );
		$transport->queue( new Response( 200 ), self::ZIP );

		$this->queueDescription( $transport, $release );
		$transport->queue( new Response( 200 ), self::ZIP );
		$transport->queue( $this->reachableResponse() );
		$transport->queue( $this->repositoryResponse() );

		$list = $service->listReleases( $query );
		self::assertNotInstanceOf( \WP_Error::class, $list );
		self::assertCount( 2, $transport->requests() );

		$inspected = $service->describeExact(
			new ExactReleaseRequest( $query, 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $inspected );
		self::assertTrue( $service->isCommitReachableFromBranch( $query, self::COMMIT, 'main' ) );
		$inspectionArtifact = $service->acquireDescribed( $inspected );
		self::assertNotInstanceOf( \WP_Error::class, $inspectionArtifact );
		self::assertTrue( $inspectionArtifact->discard() );
		self::assertCount( 7, $transport->requests() );

		$acquired = $service->describeExact(
			new ExactReleaseRequest( $query, 77, self::TAG )
		);
		self::assertInstanceOf( ArtifactDescriptor::class, $acquired );
		$installationArtifact = $service->acquireDescribed( $acquired );
		self::assertNotInstanceOf( \WP_Error::class, $installationArtifact );
		self::assertTrue( $service->isCommitReachableFromBranch( $query, self::COMMIT, 'main' ) );
		self::assertTrue( $installationArtifact->discard() );

		$this->assertZipOnlyRequestBudget( $transport, 12, 2, $authenticated, $credentialCalls );
	}

	/**
	 * @return array<string, array{bool}>
	 */
	public static function authenticationProvider(): array {
		return array(
			'public'  => array( false ),
			'private' => array( true ),
		);
	}

	private function service(
		FakeTransport $transport,
		?FakeTemporaryFileFactory $temporaryFiles = null
	): GitHubReleaseArtifactService {
		return new GitHubReleaseArtifactService( $transport, $temporaryFiles );
	}

	private function query(
		string $channel = ReleaseQuery::STABLE,
		?ConditionalState $conditional = null,
		?string $repositoryId = null
	): ReleaseQuery {
		$repository = Repository::fromString( self::REPOSITORY, $repositoryId );
		self::assertInstanceOf( Repository::class, $repository );

		return new ReleaseQuery(
			$repository,
			$channel,
			'8.2',
			'6.8',
			5,
			$conditional
		);
	}

	private function managedQuery( bool $authenticated, int &$credentialCalls ): ReleaseQuery {
		return $this->requestBudgetQuery( false, $authenticated, $credentialCalls );
	}

	private function prospectiveQuery( bool $authenticated, int &$credentialCalls ): ReleaseQuery {
		return $this->requestBudgetQuery( true, $authenticated, $credentialCalls );
	}

	private function requestBudgetQuery(
		bool $prospective,
		bool $authenticated,
		int &$credentialCalls
	): ReleaseQuery {
		$repository = Repository::fromString( self::REPOSITORY, '123456789' );
		self::assertInstanceOf( Repository::class, $repository );
		$token = null;
		if ( $authenticated ) {
			$token = AccessToken::fromValue(
				static function () use ( &$credentialCalls ): string {
					++$credentialCalls;
					return 'private-token';
				}
			);
			self::assertInstanceOf( AccessToken::class, $token );
		}

		return $prospective
			? ReleaseQuery::prospective( $repository, ReleaseQuery::STABLE, '8.2', '6.8', 5, $token )
			: new ReleaseQuery(
				$repository,
				ReleaseQuery::STABLE,
				'8.2',
				'6.8',
				5,
				null,
				$token
			);
	}

	private function repository(): Repository {
		$repository = Repository::fromString( self::REPOSITORY );
		self::assertInstanceOf( Repository::class, $repository );
		return $repository;
	}

	/**
	 * @param array<string, mixed> $release
	 */
	private function queueDescription( FakeTransport $transport, array $release ): void {
		$transport->queue( new Response( 200, array(), $this->json( $release ) ) );
		$transport->queue(
			new Response( 200, array(), $this->json( array( 'sha' => self::COMMIT ) ) )
		);
	}

	private function repositoryResponse(): Response {
		return new Response(
			200,
			array(),
			$this->json(
				array(
					'id'        => 123456789,
					'full_name' => self::REPOSITORY,
				)
			)
		);
	}

	private function reachableResponse(): Response {
		return new Response(
			200,
			array(),
			$this->json(
				array(
					'status'            => 'ahead',
					'merge_base_commit' => array( 'sha' => self::COMMIT ),
				)
			)
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function release(): array {
		return array(
			'id'           => 77,
			'tag_name'     => self::TAG,
			'draft'        => false,
			'prerelease'   => false,
			'immutable'    => true,
			'published_at' => '2026-07-24T12:00:00Z',
			'html_url'     => 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . self::TAG,
			'assets'       => array(
				array(
					'id'     => 101,
					'name'   => 'example-plugin-1.2.3.zip',
					'size'   => strlen( self::ZIP ),
					'state'  => 'uploaded',
					'digest' => 'sha256:' . hash( 'sha256', self::ZIP ),
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function releaseWithIgnoredSidecars(): array {
		$release             = $this->release();
		$release['assets'][] = array(
			'id'    => 202,
			'name'  => 'legacy-manifest.json',
			'size'  => 100,
			'state' => 'uploaded',
		);
		$release['assets'][] = array(
			'id'    => 203,
			'name'  => 'legacy-manifest.json.sig',
			'size'  => 100,
			'state' => 'uploaded',
		);

		return $release;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function listRelease( int $id, string $tag, bool $prerelease ): array {
		$version = ltrim( $tag, 'v' );
		$zip     = 'zip-' . $version;

		return array(
			'id'           => $id,
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => $prerelease,
			'immutable'    => false,
			'published_at' => '2026-07-24T12:00:00Z',
			'assets'       => array(
				array(
					'id'     => $id * 10,
					'name'   => 'friendly-' . $version . '.zip',
					'size'   => strlen( $zip ),
					'state'  => 'uploaded',
					'digest' => 'sha256:' . hash( 'sha256', $zip ),
				),
			),
		);
	}

	private function json( mixed $value ): string {
		$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
		self::assertIsString( $json );
		return $json;
	}

	private function assertErrorCode( string $expected, mixed $actual ): void {
		self::assertInstanceOf( \WP_Error::class, $actual );
		self::assertSame( $expected, $actual->get_error_code() );
	}

	private function assertZipOnlyRequestBudget(
		FakeTransport $transport,
		int $expectedRequests,
		int $expectedZipDownloads,
		bool $authenticated,
		int $credentialCalls
	): void {
		$zipDownloads = 0;
		self::assertCount( $expectedRequests, $transport->requests() );
		self::assertSame( $authenticated ? $expectedRequests : 0, $credentialCalls );
		foreach ( $transport->requests() as $request ) {
			self::assertStringNotContainsString( 'manifest', $request->url() );
			self::assertStringNotContainsString( '.json', $request->url() );
			self::assertStringNotContainsString( '.sig', $request->url() );
			self::assertDoesNotMatchRegularExpression(
				'~/releases/assets/(?:202|203)(?:\z|[/?#])~D',
				$request->url()
			);
			if ( str_ends_with( $request->url(), '/releases/assets/101' ) ) {
				++$zipDownloads;
				self::assertNotNull( $request->streamTo() );
			}
			if ( $authenticated ) {
				self::assertSame( 'Bearer private-token', $request->headers()['Authorization'] ?? null );
			} else {
				self::assertArrayNotHasKey( 'Authorization', $request->headers() );
			}
		}
		self::assertSame( $expectedZipDownloads, $zipDownloads );
	}
}
