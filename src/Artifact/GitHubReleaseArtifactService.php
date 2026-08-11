<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\Request;
use RAN\WPGitHubReleaseUpdater\V1\Http\Response;
use RAN\WPGitHubReleaseUpdater\V1\Http\TemporaryFileFactory;
use RAN\WPGitHubReleaseUpdater\V1\Http\Transport;
use RAN\WPGitHubReleaseUpdater\V1\Http\WordPressTemporaryFileFactory;

/**
 * Hook-free and persistence-free GitHub release-artifact service.
 */
final class GitHubReleaseArtifactService {
	private const API_ORIGIN               = 'https://api.github.com';
	private const API_HOST                 = 'api.github.com';
	private const RELEASE_PAGE_SIZE        = 20;
	private const MAX_RELEASE_PAGES        = 2;
	private const RELEASE_RESPONSE_LIMIT   = 262144;
	private const RELEASE_LIST_BYTES_LIMIT = 524288;
	private const COMMIT_RESPONSE_LIMIT    = 16384;
	private const PACKAGE_SIZE_LIMIT       = 52428800;
	private const HTTP_TIMEOUT             = 10;
	private const MAX_REDIRECTS            = 1;
	private const RELEASE_ASSET_HOSTS      = array(
		'release-assets.githubusercontent.com',
		'objects.githubusercontent.com',
		'github-releases.githubusercontent.com',
	);

	/**
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	public function __construct(
		private Transport $transport,
		?TemporaryFileFactory $temporaryFiles = null,
		?callable $clock = null
	) {
		$this->temporaryFiles = $temporaryFiles ?? new WordPressTemporaryFileFactory();
		$this->clock          = null === $clock ? static fn (): int => time() : \Closure::fromCallable( $clock );
	}

	private TemporaryFileFactory $temporaryFiles;

	/**
	 * List a bounded page of eligible stable or prerelease releases.
	 *
	 * @return ReleaseListResult|\WP_Error
	 */
	public function listReleases( ReleaseQuery $query ) {
		$valid = $query->validate();
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}

		$conditional     = new ConditionalState();
		$rateLimit       = new RateLimit();
		$releases        = array();
		$seenReleaseIds  = array();
		$responseBytes   = 0;
		$searchExhausted = false;
		for ( $page = 1; $page <= self::MAX_RELEASE_PAGES; ++$page ) {
			$url      = $this->repositoryApiUrl( $query->repository() )
				. '/releases?per_page=' . self::RELEASE_PAGE_SIZE . '&page=' . $page;
			$response = $this->request(
				$query,
				$url,
				1 === $page
					? array_merge( $this->jsonHeaders(), $query->conditional()->requestHeaders() )
					: $this->jsonHeaders(),
				min( self::RELEASE_RESPONSE_LIMIT, self::RELEASE_LIST_BYTES_LIMIT - $responseBytes )
			);
			if ( $response instanceof \WP_Error ) {
				return $response;
			}

			if ( 1 === $page ) {
				$conditional = $this->conditionalFromResponse( $response );
			}
			$rateLimit = $this->rateLimitFromResponse( $response, 900 );
			if ( 1 === $page && 304 === $response->statusCode() ) {
				return new ReleaseListResult( array(), $conditional, $rateLimit, true );
			}
			if ( $rateLimit->isLimited() ) {
				return new ReleaseListResult( array(), $conditional, $rateLimit );
			}
			if ( 200 !== $response->statusCode() ) {
				return $this->httpError( $response->statusCode() );
			}

			$responseBytes += strlen( $response->body() );
			if ( $responseBytes > self::RELEASE_LIST_BYTES_LIMIT ) {
				return new \WP_Error(
					'github_updater_response_too_large',
					'GitHub returned release pages larger than the allowed limit.'
				);
			}
			$decoded = $this->decodeObjectList( $response->body() );
			if ( $decoded instanceof \WP_Error ) {
				return $decoded;
			}

			foreach ( $decoded as $candidate ) {
				$summary = $this->summaryFromRelease( $candidate, $query );
				if ( null !== $summary && ! isset( $seenReleaseIds[ $summary->releaseId() ] ) ) {
					$seenReleaseIds[ $summary->releaseId() ] = true;
					$releases[]                              = $summary;
				}
			}

			$pageFull = self::RELEASE_PAGE_SIZE === count( $decoded );
			if ( count( $releases ) >= $query->candidateLimit() || ! $pageFull ) {
				$searchExhausted = count( $releases ) > $query->candidateLimit() || $pageFull;
				break;
			}
			if ( self::MAX_RELEASE_PAGES === $page ) {
				$searchExhausted = true;
			}
		}

		usort(
			$releases,
			static fn ( ReleaseSummary $left, ReleaseSummary $right ): int =>
				ReleaseVersion::compare( $right->version(), $left->version() ) ?? 0
		);
		if ( $query->isProspective() ) {
			$repositoryIdentity = $this->assertRepositoryIdentity( $query );
			if ( $repositoryIdentity instanceof \WP_Error ) {
				return $repositoryIdentity;
			}
		}

		return new ReleaseListResult(
			array_slice( $releases, 0, $query->candidateLimit() ),
			$conditional,
			$rateLimit,
			false,
			$searchExhausted
		);
	}

	/**
	 * Resolve one exact release ID and validate its complete artifact contract.
	 *
	 * @return ArtifactDescriptor|\WP_Error
	 */
	public function describeExact( ExactReleaseRequest $request ) {
		$query = $request->query();
		$valid = $query->validate();
		if ( $valid instanceof \WP_Error ) {
			return $valid;
		}
		if ( $request->releaseId() < 1 ) {
			return new \WP_Error( 'github_updater_invalid_release_id', 'The exact release ID is invalid.' );
		}
		if ( null !== $request->expectedTag()
			&& null === ReleaseVersion::fromTag( $request->expectedTag() )
		) {
			return new \WP_Error( 'github_updater_invalid_release_tag', 'The expected release tag is invalid.' );
		}

		$response = $this->getJson(
			$query,
			$this->repositoryApiUrl( $query->repository() ) . '/releases/' . $request->releaseId(),
			self::RELEASE_RESPONSE_LIMIT
		);
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$release = $this->decodeObject( $response->body() );
		if ( $release instanceof \WP_Error ) {
			return $release;
		}

		return $this->descriptorFromRelease( $query, $request, $release );
	}

	/**
	 * Download a descriptor reconstructed in the current operation.
	 *
	 * The caller describes the exact release within the current operation,
	 * then acquires that descriptor without a duplicate description request.
	 *
	 * @return VerifiedArtifact|\WP_Error
	 */
	public function acquireDescribed( ArtifactDescriptor $descriptor ) {
		$path = $this->temporaryFiles->create( $descriptor->zipAsset()->name() );
		if ( $path instanceof \WP_Error ) {
			return $path;
		}

		try {
			$response = $this->request(
				$descriptor->query(),
				$this->assetApiUrl( $descriptor->repository(), $descriptor->zipAsset()->id() ),
				$this->binaryHeaders(),
				self::PACKAGE_SIZE_LIMIT,
				$path
			);
		} catch ( \Throwable ) {
			$this->discardTemporaryFile( $path );
			return new \WP_Error(
				'github_updater_download_failed',
				'The verified release asset could not be downloaded.'
			);
		}
		if ( $response instanceof \WP_Error ) {
			$this->discardTemporaryFile( $path );
			return $response;
		}
		if ( 200 !== $response->statusCode() ) {
			$this->discardTemporaryFile( $path );
			return $this->httpError( $response->statusCode() );
		}

		$identity = VerifiedArtifact::fileIdentity( $path );
		if ( null === $identity
			|| 1 !== $identity['nlink']
			|| 0600 !== ( $identity['mode'] & 0777 )
			|| $identity['size'] !== $descriptor->zipAsset()->size()
			|| $identity['size'] > self::PACKAGE_SIZE_LIMIT
		) {
			$this->discardTemporaryFile( $path );
			return new \WP_Error(
				'github_updater_downloaded_artifact_invalid',
				'The downloaded release asset did not match its declared file identity or size.'
			);
		}

		$sha256 = hash_file( 'sha256', $path );
		if ( false === $sha256 || ! hash_equals( $descriptor->zipAsset()->sha256(), $sha256 ) ) {
			$this->discardTemporaryFile( $path );
			return new \WP_Error(
				'github_updater_downloaded_digest_mismatch',
				'The downloaded release asset did not match its expected SHA-256 digest.'
			);
		}
		$repositoryIdentity = $this->assertRepositoryIdentity( $descriptor->query() );
		if ( $repositoryIdentity instanceof \WP_Error ) {
			$this->discardTemporaryFile( $path );
			return $repositoryIdentity;
		}

		return new VerifiedArtifact(
			$path,
			$sha256,
			$this->temporaryFiles,
			$identity
		);
	}

	private function discardTemporaryFile( string $path ): void {
		try {
			$this->temporaryFiles->delete( $path );
		} catch ( \Throwable ) {
			// A cleanup adapter must not replace the original download error.
		}
	}

	/**
	 * Compare the live GitHub repository ID with the managed target identity.
	 *
	 * @return true|\WP_Error
	 */
	private function assertRepositoryIdentity( ReleaseQuery $query ) {
		$expected = $query->repository()->providerRepositoryId();
		if ( null === $expected ) {
			return true;
		}

		$response = $this->getJson(
			$query,
			$this->repositoryApiUrl( $query->repository() ),
			self::RELEASE_RESPONSE_LIMIT
		);
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		$repository = $this->decodeObject( $response->body() );
		$actual     = $repository instanceof \WP_Error
			? null
			: $this->positiveInt( $repository['id'] ?? null );
		if ( null === $actual || ! hash_equals( $expected, (string) $actual ) ) {
			return new \WP_Error(
				'github_updater_repository_identity_changed',
				'The GitHub repository identity no longer matches the managed target.'
			);
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $release Release API response.
	 * @return ArtifactDescriptor|\WP_Error
	 */
	private function descriptorFromRelease(
		ReleaseQuery $query,
		ExactReleaseRequest $request,
		array $release
	) {
		$id = $this->positiveInt( $release['id'] ?? null );
		if ( null === $id || $request->releaseId() !== $id ) {
			return $this->continuityError( 'release ID' );
		}
		if ( ! is_bool( $release['draft'] ?? null )
			|| ! is_bool( $release['prerelease'] ?? null )
			|| ! is_bool( $release['immutable'] ?? null )
		) {
			return new \WP_Error(
				'github_updater_invalid_release',
				'The selected GitHub Release has invalid publication state.'
			);
		}
		if ( $release['draft'] ) {
			return new \WP_Error( 'github_updater_release_is_draft', 'The selected GitHub Release is a draft.' );
		}

		$tag     = is_string( $release['tag_name'] ?? null ) ? $release['tag_name'] : '';
		$version = ReleaseVersion::fromTag( $tag );
		if ( null === $version ) {
			return new \WP_Error( 'github_updater_invalid_release_tag', 'The selected release tag is not semantic.' );
		}
		if ( null !== $request->expectedTag() && $request->expectedTag() !== $tag ) {
			return $this->continuityError( 'release tag' );
		}

		$prerelease = $release['prerelease'];
		if ( ReleaseQuery::STABLE === $query->channel()
			&& ( $prerelease || str_contains( $version, '-' ) )
		) {
			return new \WP_Error(
				'github_updater_prerelease_not_allowed',
				'A prerelease cannot satisfy a stable release channel.'
			);
		}

		$detailsUrl = is_string( $release['html_url'] ?? null ) ? $release['html_url'] : '';
		if ( ! $this->isReleaseUrl( $detailsUrl, $query->repository() ) ) {
			return new \WP_Error( 'github_updater_invalid_release_url', 'The GitHub Release URL is invalid.' );
		}

		$assets = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$zip    = $this->zipAsset( $assets );
		if ( $zip instanceof \WP_Error ) {
			return $zip;
		}

		$commit = $this->resolveCommit( $query, $tag );
		if ( $commit instanceof \WP_Error ) {
			return $commit;
		}

		return new ArtifactDescriptor(
			$query,
			$query->repository(),
			$id,
			$tag,
			$version,
			$commit,
			$prerelease,
			$detailsUrl,
			$zip,
			$release['immutable']
		);
	}

	private function isReleaseUrl( string $url, Repository $repository ): bool {
		if ( strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return false;
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'github.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['port'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
			|| ! is_string( $parts['path'] ?? null )
		) {
			return false;
		}

		$path       = explode( '/', ltrim( $parts['path'], '/' ), 4 );
		$repository = explode( '/', $repository->canonical(), 2 );
		return 4 === count( $path )
			&& 0 === strcasecmp( rawurldecode( $path[0] ), $repository[0] )
			&& 0 === strcasecmp( rawurldecode( $path[1] ), $repository[1] )
			&& 'releases' === $path[2]
			&& '' !== $path[3];
	}

	/**
	 * @param array<int, mixed> $assets Release assets.
	 * @return ReleaseAsset|\WP_Error
	 */
	private function zipAsset( array $assets ) {
		$matches = array();
		foreach ( $assets as $asset ) {
			$name = is_array( $asset ) && is_string( $asset['name'] ?? null )
				? $asset['name']
				: '';
			if ( str_ends_with( strtolower( $name ), '.zip' ) ) {
				$matches[] = $asset;
			}
		}
		if ( 1 !== count( $matches ) ) {
			return new \WP_Error(
				'github_updater_ambiguous_release_asset',
				'The release must contain exactly one uploaded ZIP asset.'
			);
		}

		$asset = $matches[0];
		$name  = $asset['name'];
		$id    = $this->positiveInt( $asset['id'] ?? null );
		$size  = $this->positiveInt( $asset['size'] ?? null );
		if ( strlen( $name ) > 220
			|| 1 === preg_match( '/[\x00-\x20\x7f]/', $name )
			|| null === $id
			|| null === $size
			|| 'uploaded' !== ( $asset['state'] ?? null )
		) {
			return new \WP_Error(
				'github_updater_invalid_release_asset',
				'The release ZIP asset is invalid or is not completely uploaded.'
			);
		}
		if ( $size > self::PACKAGE_SIZE_LIMIT ) {
			return new \WP_Error(
				'github_updater_release_asset_too_large',
				'The release ZIP asset exceeds the package size limit.'
			);
		}

		$digest = is_string( $asset['digest'] ?? null ) ? strtolower( $asset['digest'] ) : '';
		if ( 1 !== preg_match( '/\Asha256:([a-f0-9]{64})\z/D', $digest, $digestMatch ) ) {
			return new \WP_Error(
				'github_updater_missing_asset_digest',
				'The GitHub release ZIP does not provide a supported SHA-256 digest.'
			);
		}

		return new ReleaseAsset( $id, $name, $size, $digestMatch[1] );
	}

	/**
	 * @param array<string, mixed> $release Release API response.
	 */
	private function summaryFromRelease( array $release, ReleaseQuery $query ): ?ReleaseSummary {
		if ( ! is_bool( $release['draft'] ?? null )
			|| ! is_bool( $release['prerelease'] ?? null )
			|| ! is_bool( $release['immutable'] ?? null )
			|| $release['draft']
		) {
			return null;
		}
		$id          = $this->positiveInt( $release['id'] ?? null );
		$tag         = is_string( $release['tag_name'] ?? null ) ? $release['tag_name'] : '';
		$version     = ReleaseVersion::fromTag( $tag );
		$prerelease  = $release['prerelease'];
		$publishedAt = is_string( $release['published_at'] ?? null ) ? $release['published_at'] : '';
		if ( null === $id
			|| null === $version
			|| 1 !== preg_match(
				'/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/D',
				$publishedAt
			)
		) {
			return null;
		}
		if ( ReleaseQuery::STABLE === $query->channel()
			&& ( $prerelease || str_contains( $version, '-' ) )
		) {
			return null;
		}

		$assets   = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$zipNames = array();
		foreach ( $assets as $asset ) {
			$name = is_array( $asset ) && is_string( $asset['name'] ?? null )
				? $asset['name']
				: '';
			if ( str_ends_with( strtolower( $name ), '.zip' )
				&& strlen( $name ) <= 220
				&& 1 !== preg_match( '/[\x00-\x20\x7f]/', $name )
			) {
				$zipNames[] = $name;
			}
		}

		return new ReleaseSummary(
			$id,
			$tag,
			$version,
			$prerelease || ReleaseVersion::isPrerelease( $version ),
			$publishedAt,
			array_slice( $zipNames, 0, 2 ),
			$release['immutable']
		);
	}

	/**
	 * Resolve both lightweight and annotated release tags through GitHub's
	 * commit endpoint.
	 *
	 * @return string|\WP_Error
	 */
	private function resolveCommit( ReleaseQuery $query, string $tag ) {
		$response = $this->getJson(
			$query,
			$this->repositoryApiUrl( $query->repository() ) . '/commits/' . rawurlencode( $tag ),
			self::COMMIT_RESPONSE_LIMIT
		);
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		$data = $this->decodeObject( $response->body() );
		if ( $data instanceof \WP_Error ) {
			return $data;
		}
		$commit = is_string( $data['sha'] ?? null ) ? strtolower( $data['sha'] ) : '';
		if ( 1 !== preg_match( '/\A[a-f0-9]{40}\z/D', $commit ) ) {
			return new \WP_Error(
				'github_updater_invalid_tag_commit',
				'GitHub did not resolve the release tag to a full commit SHA.'
			);
		}

		return $commit;
	}

	/**
	 * @return Response|\WP_Error
	 */
	private function getJson(
		ReleaseQuery $query,
		string $url,
		int $limit,
		bool $binary = false
	) {
		$response = $this->request(
			$query,
			$url,
			$binary ? $this->binaryHeaders() : $this->jsonHeaders(),
			$limit
		);
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$rateLimit = $this->rateLimitFromResponse( $response, 900 );
		if ( $rateLimit->isLimited() ) {
			return new \WP_Error(
				'github_updater_rate_limited',
				'GitHub temporarily rate limited the release request.',
				array( 'cooldown' => $rateLimit->cooldownSeconds() )
			);
		}
		if ( 200 !== $response->statusCode() ) {
			return $this->httpError( $response->statusCode() );
		}

		return $response;
	}

	/**
	 * Make one bounded request chain with credentials scoped to api.github.com.
	 *
	 * WordPress transport redirects remain disabled. Each hop is validated
	 * here, and credentials are irreversibly removed when a chain leaves the
	 * GitHub API origin.
	 *
	 * @param array<string, string> $headers Request headers.
	 * @return Response|\WP_Error
	 */
	private function request(
		ReleaseQuery $query,
		string $url,
		array $headers,
		int $limit,
		?string $streamTo = null
	) {
		$initial = $this->validatedApiUrl( $url );
		if ( $initial instanceof \WP_Error ) {
			return $initial;
		}

		$token = $query->accessToken()->resolve();
		if ( $token instanceof \WP_Error ) {
			return $token;
		}
		if ( null !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$currentUrl       = $initial;
		$credentialsBound = true;
		for ( $redirects = 0; ; ++$redirects ) {
			$response = $this->transport->get(
				new Request(
					$currentUrl,
					$headers,
					self::HTTP_TIMEOUT,
					$limit + 1,
					$streamTo,
					0
				)
			);
			if ( $response instanceof \WP_Error ) {
				return $this->transportError( $response );
			}

			if ( ! in_array( $response->statusCode(), array( 301, 302, 303, 307, 308 ), true ) ) {
				if ( null === $streamTo && strlen( $response->body() ) > $limit ) {
					return new \WP_Error(
						'github_updater_response_too_large',
						'GitHub returned a response larger than the allowed limit.'
					);
				}
				return $response;
			}
			if ( $redirects >= self::MAX_REDIRECTS ) {
				return new \WP_Error(
					'github_updater_redirect_limit_exceeded',
					'The GitHub release asset exceeded the redirect limit.'
				);
			}

			$nextUrl = $this->validatedRedirectUrl( $response->header( 'location' ) );
			if ( $nextUrl instanceof \WP_Error ) {
				return $nextUrl;
			}

			$nextHost = strtolower( (string) parse_url( $nextUrl, PHP_URL_HOST ) );
			if ( self::API_HOST !== $nextHost ) {
				$credentialsBound = false;
			}
			if ( ! $credentialsBound ) {
				unset( $headers['Authorization'] );
			}
			$currentUrl = $nextUrl;
		}
	}

	/**
	 * @return string|\WP_Error
	 */
	private function validatedRedirectUrl( ?string $url ) {
		if ( null === $url
			|| '' === $url
			|| strlen( $url ) > 4096
			|| 1 === preg_match( '/[\x00-\x1f\x7f]/', $url )
		) {
			return $this->unsafeRedirectError();
		}
		if ( ! function_exists( 'wp_http_validate_url' ) || false === wp_http_validate_url( $url ) ) {
			return $this->unsafeRedirectError();
		}

		$parts = parse_url( $url );
		if ( ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| ! is_string( $parts['host'] ?? null )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] )
			|| isset( $parts['fragment'] )
		) {
			return $this->unsafeRedirectError();
		}

		$host = strtolower( $parts['host'] );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP )
			|| ( self::API_HOST !== $host && ! in_array( $host, self::RELEASE_ASSET_HOSTS, true ) )
		) {
			return $this->unsafeRedirectError();
		}
		if ( $this->signedUrlIsExpired( $parts['query'] ?? '' ) ) {
			return new \WP_Error(
				'github_updater_expired_release_asset_url',
				'GitHub returned an expired release asset URL.'
			);
		}

		return $url;
	}

	/**
	 * @return string|\WP_Error
	 */
	private function validatedApiUrl( string $url ) {
		$validated = $this->validatedRedirectUrl( $url );
		if ( $validated instanceof \WP_Error ) {
			return $validated;
		}
		if ( self::API_HOST !== strtolower( (string) parse_url( $validated, PHP_URL_HOST ) ) ) {
			return $this->unsafeRedirectError();
		}

		return $validated;
	}

	private function signedUrlIsExpired( string $query ): bool {
		if ( '' === $query ) {
			return false;
		}
		$expiry = array();
		foreach ( explode( '&', $query ) as $pair ) {
			$keyValue = explode( '=', $pair, 2 );
			$key      = strtolower( rawurldecode( $keyValue[0] ) );
			if ( ! in_array( $key, array( 'se', 'expires', 'x-amz-date', 'x-amz-expires' ), true ) ) {
				continue;
			}
			if ( array_key_exists( $key, $expiry ) ) {
				return true;
			}
			$expiry[ $key ] = rawurldecode( $keyValue[1] ?? '' );
		}
		if ( array() === $expiry ) {
			return false;
		}

		$families = ( array_key_exists( 'se', $expiry ) ? 1 : 0 )
			+ ( array_key_exists( 'expires', $expiry ) ? 1 : 0 )
			+ (
				array_key_exists( 'x-amz-date', $expiry )
				|| array_key_exists( 'x-amz-expires', $expiry )
				? 1
				: 0
			);
		if ( 1 !== $families ) {
			return true;
		}

		$now = ( $this->clock )();
		if ( array_key_exists( 'se', $expiry ) ) {
			if ( 1 !== preg_match(
				'/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z\z/D',
				$expiry['se']
			) ) {
				return true;
			}
			$expiresAt = strtotime( $expiry['se'] );
			return false === $expiresAt || $expiresAt <= $now;
		}
		if ( array_key_exists( 'expires', $expiry ) ) {
			return 1 !== preg_match( '/\A\d{1,12}\z/D', $expiry['expires'] )
				|| (int) $expiry['expires'] <= $now;
		}

		if ( ! isset( $expiry['x-amz-date'], $expiry['x-amz-expires'] )
			|| 1 !== preg_match( '/\A\d{8}T\d{6}Z\z/D', $expiry['x-amz-date'] )
			|| 1 !== preg_match( '/\A\d{1,7}\z/D', $expiry['x-amz-expires'] )
		) {
			return true;
		}
		$issuedAt = \DateTimeImmutable::createFromFormat(
			'!Ymd\THis\Z',
			$expiry['x-amz-date'],
			new \DateTimeZone( 'UTC' )
		);
		return false === $issuedAt
			|| ( $issuedAt->getTimestamp() + (int) $expiry['x-amz-expires'] ) <= $now;
	}

	private function unsafeRedirectError(): \WP_Error {
		return new \WP_Error(
			'github_updater_unsafe_release_asset_redirect',
			'GitHub returned an unsafe release asset redirect.'
		);
	}

	private function repositoryApiUrl( Repository $repository ): string {
		return self::API_ORIGIN . '/repos/' . $repository->apiPath();
	}

	private function assetApiUrl( Repository $repository, int $assetId ): string {
		return $this->repositoryApiUrl( $repository ) . '/releases/assets/' . $assetId;
	}

	/**
	 * @return array<string, string>
	 */
	private function jsonHeaders(): array {
		return array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'ran-wp-github-release-updater/2.0.0-beta.5', // x-release-please-version
		);
	}

	/**
	 * @return array<string, string>
	 */
	private function binaryHeaders(): array {
		$headers           = $this->jsonHeaders();
		$headers['Accept'] = 'application/octet-stream';

		return $headers;
	}

	private function conditionalFromResponse( Response $response ): ConditionalState {
		$etag         = $this->boundedHeader( $response->header( 'etag' ), 512 );
		$lastModified = $this->boundedHeader( $response->header( 'last-modified' ), 128 );

		return new ConditionalState( $etag, $lastModified );
	}

	private function rateLimitFromResponse( Response $response, int $fallback ): RateLimit {
		$remaining = $this->nonNegativeInt( $response->header( 'x-ratelimit-remaining' ) );
		$resetAt   = $this->nonNegativeInt( $response->header( 'x-ratelimit-reset' ) );
		$retry     = $this->positiveInt( $response->header( 'retry-after' ) );
		$limited   = 429 === $response->statusCode()
			|| ( 403 === $response->statusCode() && ( null !== $retry || 0 === $remaining ) );

		if ( ! $limited ) {
			return new RateLimit( RateLimit::NONE, $remaining, $resetAt );
		}

		$now      = ( $this->clock )();
		$cooldown = $fallback;
		if ( null !== $retry ) {
			$cooldown = $retry;
		} elseif ( null !== $resetAt && $resetAt > $now ) {
			$cooldown = $resetAt - $now;
		}
		$cooldown = max( 1, min( 86400, $cooldown ) );

		return new RateLimit( RateLimit::LIMITED, $remaining, $resetAt, $cooldown );
	}

	/**
	 * @return list<array<string, mixed>>|\WP_Error
	 */
	private function decodeObjectList( string $body ) {
		$decoded = json_decode( $body, true, 32 );
		if ( ! is_array( $decoded ) || ! self::isList( $decoded ) ) {
			return new \WP_Error( 'github_updater_invalid_json', 'GitHub returned an invalid releases response.' );
		}

		$objects = array();
		foreach ( $decoded as $item ) {
			if ( is_array( $item ) ) {
				$objects[] = $item;
			}
		}

		return $objects;
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private function decodeObject( string $body ) {
		$decoded = json_decode( $body, true, 32 );
		if ( ! is_array( $decoded ) || self::isList( $decoded ) ) {
			return new \WP_Error( 'github_updater_invalid_json', 'GitHub returned an invalid response.' );
		}

		return $decoded;
	}

	/**
	 * @return int|null
	 */
	private function positiveInt( mixed $value ): ?int {
		if ( is_int( $value ) && $value > 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/\A[1-9]\d*\z/D', $value ) ) {
			$integer = filter_var( $value, FILTER_VALIDATE_INT );
			return false === $integer ? null : $integer;
		}

		return null;
	}

	private function nonNegativeInt( ?string $value ): ?int {
		if ( null === $value || 1 !== preg_match( '/\A\d+\z/D', $value ) ) {
			return null;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );

		return false === $integer ? null : $integer;
	}

	private function boundedHeader( ?string $value, int $limit ): ?string {
		if ( null === $value || '' === $value || strlen( $value ) > $limit || str_contains( $value, "\n" ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Explicit list check for the supported PHP runtime.
	 *
	 * @param array<mixed> $value Value to check.
	 */
	private static function isList( array $value ): bool {
		return array() === $value
			|| array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private function transportError( \WP_Error $error ): \WP_Error {
		unset( $error );

		return new \WP_Error(
			'github_updater_http_transport_failed',
			'The GitHub request could not be completed.'
		);
	}

	private function httpError( int $statusCode ): \WP_Error {
		if ( 401 === $statusCode ) {
			$code = 'github_updater_github_authentication_failed';
		} elseif ( 403 === $statusCode ) {
			$code = 'github_updater_github_forbidden';
		} else {
			$code = 'github_updater_github_http_error';
		}

		return new \WP_Error(
			$code,
			'GitHub returned an unexpected response.',
			array( 'status' => $statusCode )
		);
	}

	private function continuityError( string $identity ): \WP_Error {
		return new \WP_Error(
			'github_updater_artifact_continuity_failed',
			'The exact GitHub ' . $identity . ' changed after discovery.'
		);
	}
}
