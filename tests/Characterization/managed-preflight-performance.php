<?php

declare(strict_types=1);

use RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService;
use RAN\WPGitHubReleaseUpdater\V1\Http\Request;
use RAN\WPGitHubReleaseUpdater\V1\Http\Response;
use RAN\WPGitHubReleaseUpdater\V1\Http\Transport;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\CandidateValidation;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\GitHubReleaseArtifactClient;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseAssurance;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;
use Tests\Support\WordPressState;

require_once dirname( __DIR__ ) . '/bootstrap.php';

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
 * File-backed fixture asset so maximum-size characterization remains streamed.
 */
final class ManagedPreflightProbeAsset {
	public function __construct( private string $path ) {
	}

	public function path(): string {
		return $this->path;
	}

	public function size(): int {
		$size = filesize( $this->path );
		if ( false === $size ) {
			throw new \RuntimeException( 'Could not stat the deterministic probe ZIP.' );
		}

		return $size;
	}

	public function sha256(): string {
		$digest = hash_file( 'sha256', $this->path );
		if ( false === $digest ) {
			throw new \RuntimeException( 'Could not digest the deterministic probe ZIP.' );
		}

		return $digest;
	}

	public function discard(): void {
		if ( is_file( $this->path ) ) {
			unlink( $this->path );
		}
	}
}

/**
 * Deterministic request/ZIP observation seam for characterization only.
 */
final class ManagedPreflightProbeTransport implements Transport {
	/** @var list<array{response: Response|\WP_Error, download: ?ManagedPreflightProbeAsset}> */
	private array $queue = array();

	/** @var list<string> */
	private array $streamedPaths = array();

	private int $requests      = 0;
	private int $downloads     = 0;
	private int $downloadBytes = 0;
	private bool $suspended    = false;

	public function __construct( private bool $suspendFirstRequest = false ) {
	}

	public function queue(
		Response|\WP_Error $response,
		?ManagedPreflightProbeAsset $download = null
	): void {
		$this->queue[] = array(
			'response' => $response,
			'download' => $download,
		);
	}

	public function prepend( Response|\WP_Error $response ): void {
		array_unshift(
			$this->queue,
			array(
				'response' => $response,
				'download' => null,
			)
		);
	}

	public function get( Request $request ) {
		++$this->requests;
		if ( $this->suspendFirstRequest && ! $this->suspended ) {
			$this->suspended = true;
			\Fiber::suspend();
		}

		$next = array_shift( $this->queue );
		if ( null === $next ) {
			return new \WP_Error( 'probe_queue_empty', 'The deterministic response queue was exhausted.' );
		}
		if ( null !== $request->streamTo() && null !== $next['download'] ) {
			$source = fopen( $next['download']->path(), 'rb' );
			$target = fopen( $request->streamTo(), 'wb' );
			if ( false === $source || false === $target ) {
				throw new \RuntimeException( 'Could not stream the deterministic probe ZIP.' );
			}
			stream_copy_to_stream( $source, $target );
			fclose( $source );
			fclose( $target );
			chmod( $request->streamTo(), 0600 );
			++$this->downloads;
			$this->downloadBytes  += $next['download']->size();
			$this->streamedPaths[] = $request->streamTo();
		}

		return $next['response'];
	}

	public function requests(): int {
		return $this->requests;
	}

	public function downloads(): int {
		return $this->downloads;
	}

	public function downloadBytes(): int {
		return $this->downloadBytes;
	}

	public function temporaryFilesWereDiscarded(): bool {
		foreach ( $this->streamedPaths as $path ) {
			if ( is_file( $path ) ) {
				return false;
			}
		}

		return true;
	}
}

/**
 * @param array{
 *   incompatible?: int,
 *   outcome?: 'success'|'304'|'rate'|'timeout'|'bad_digest'|'invalid_identity',
 *   asset_bytes?: int,
 *   suspend_first_request?: bool
 * } $options
 * @return array{
 *   preflight: ReleaseCandidatePreflight,
 *   transport: ManagedPreflightProbeTransport,
 *   fixtures: list<ManagedPreflightProbeAsset>
 * }
 */
function managed_preflight_probe( int $identity, array $options = array() ): array {
	$repository             = 'fixture-owner/fixture-package-' . $identity;
	$repositoryId           = (string) ( 100000 + $identity );
	$packageRoot            = 'fixture-package-' . $identity;
	$mainFile               = $packageRoot . '.php';
	$incompatible           = max( 0, min( 8, (int) ( $options['incompatible'] ?? 0 ) ) );
	$outcome                = $options['outcome'] ?? 'success';
	$assetBytes             = max( 0, (int) ( $options['asset_bytes'] ?? 0 ) );
	$suspendFirstRequest    = true === ( $options['suspend_first_request'] ?? false );
	$transport              = new ManagedPreflightProbeTransport( $suspendFirstRequest );
	$fixtures               = array();
	$terminalIncompatible   = 8 === $incompatible;
	$releaseCount           = $terminalIncompatible ? 8 : $incompatible + 1;
	$releaseRepresentations = array();

	if ( '304' === $outcome ) {
		$transport->queue( new Response( 304, array( 'ETag' => '"probe-etag"' ) ) );
	} elseif ( 'rate' === $outcome ) {
		$transport->queue(
			new Response(
				403,
				array(
					'X-RateLimit-Remaining' => '0',
					'X-RateLimit-Reset'     => '1900000000',
				)
			)
		);
	} elseif ( 'timeout' === $outcome ) {
		$transport->queue( new \WP_Error( 'http_request_failed', 'Fixture timeout.' ) );
	} else {
		for ( $candidate = 0; $candidate < $releaseCount; ++$candidate ) {
			$version    = '2.0.' . ( $releaseCount - $candidate );
			$tag        = 'v' . $version;
			$releaseId  = 2000000 + ( $identity * 10 ) + $candidate;
			$assetId    = 3000000 + ( $identity * 10 ) + $candidate;
			$asset      = managed_preflight_probe_zip(
				$packageRoot,
				$mainFile,
				'invalid_identity' === $outcome ? 'fixture-owner/wrong-package' : $repository,
				$version,
				$candidate < $incompatible ? '99.0' : '8.0',
				0 === $candidate ? $assetBytes : 0
			);
			$fixtures[] = $asset;
			$digest     = $asset->sha256();
			if ( 'bad_digest' === $outcome && 0 === $candidate ) {
				$digest = str_repeat( '0', 64 );
			}
			$release                  = managed_preflight_probe_release(
				$repository,
				$packageRoot,
				$releaseId,
				$assetId,
				$version,
				$asset->size(),
				$digest
			);
			$releaseRepresentations[] = $release;

			$transport->queue( new Response( 200, array(), managed_preflight_probe_json( $release ) ) );
			$transport->queue(
				new Response(
					200,
					array(),
					managed_preflight_probe_json(
						array( 'sha' => str_pad( dechex( $releaseId ), 40, '1', STR_PAD_LEFT ) )
					)
				)
			);
			$transport->queue(
				new Response(
					302,
					array(
						'Location' => 'https://release-assets.githubusercontent.com/probe-'
							. $assetId . '?Expires=9999999999',
					)
				)
			);
			$transport->queue( new Response( 200 ), $asset );
			$transport->queue(
				new Response(
					200,
					array(),
					managed_preflight_probe_json(
						array(
							'id'        => (int) $repositoryId,
							'full_name' => $repository,
						)
					)
				)
			);
		}
		$transport->prepend(
			new Response( 200, array(), managed_preflight_probe_json( $releaseRepresentations ) )
		);
	}

	$preflight = ReleaseCandidatePreflight::fromTarget(
		array(
			'repository'           => $repository,
			'providerRepositoryId' => $repositoryId,
			'pluginSlug'           => $packageRoot,
			'mainFile'             => $mainFile,
			'channel'              => 'stable',
			'accessToken'          => null,
			'packageType'          => 'plugin',
			'cacheDuration'        => 300,
		),
		new GitHubReleaseArtifactClient( new GitHubReleaseArtifactService( $transport ) ),
		static fn (): int => 1000
	);
	if ( ! $preflight instanceof ReleaseCandidatePreflight ) {
		throw new \RuntimeException( 'The deterministic preflight target was rejected.' );
	}

	return array(
		'preflight' => $preflight,
		'transport' => $transport,
		'fixtures'  => $fixtures,
	);
}

/** @return array<string, mixed> */
function managed_preflight_probe_release(
	string $repository,
	string $packageRoot,
	int $releaseId,
	int $assetId,
	string $version,
	int $size,
	string $digest
): array {
	$tag = 'v' . $version;

	return array(
		'id'           => $releaseId,
		'tag_name'     => $tag,
		'draft'        => false,
		'prerelease'   => false,
		'immutable'    => true,
		'published_at' => '2026-08-03T12:00:00Z',
		'html_url'     => 'https://github.com/' . $repository . '/releases/tag/' . $tag,
		'assets'       => array(
			array(
				'id'     => $assetId,
				'name'   => $packageRoot . '-' . $version . '.zip',
				'size'   => $size,
				'state'  => 'uploaded',
				'digest' => 'sha256:' . $digest,
			),
		),
	);
}

function managed_preflight_probe_zip(
	string $packageRoot,
	string $mainFile,
	string $repository,
	string $version,
	string $requiresPhp = '8.0',
	int $targetBytes = 0
): ManagedPreflightProbeAsset {
	if ( 0 === $targetBytes ) {
		return managed_preflight_build_zip(
			$packageRoot,
			$mainFile,
			$repository,
			$version,
			$requiresPhp,
			0,
			false
		);
	}

	$empty    = managed_preflight_build_zip(
		$packageRoot,
		$mainFile,
		$repository,
		$version,
		$requiresPhp,
		0,
		true
	);
	$overhead = $empty->size();
	$empty->discard();
	if ( $targetBytes <= $overhead ) {
		throw new \RuntimeException( 'The configured probe asset size is too small.' );
	}

	$asset = managed_preflight_build_zip(
		$packageRoot,
		$mainFile,
		$repository,
		$version,
		$requiresPhp,
		$targetBytes - $overhead,
		true
	);
	if ( $targetBytes !== $asset->size() ) {
		$asset->discard();
		throw new \RuntimeException( 'The maximum-size probe ZIP was not exact.' );
	}

	return $asset;
}

function managed_preflight_build_zip(
	string $packageRoot,
	string $mainFile,
	string $repository,
	string $version,
	string $requiresPhp,
	int $payloadBytes,
	bool $includePayload
): ManagedPreflightProbeAsset {
	$path = tempnam( sys_get_temp_dir(), 'ran-preflight-probe-' );
	if ( false === $path ) {
		throw new \RuntimeException( 'Could not create the deterministic probe ZIP.' );
	}
	$zip = new \ZipArchive();
	if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
		throw new \RuntimeException( 'Could not open the deterministic probe ZIP.' );
	}
	$zip->addFromString(
		$packageRoot . '/' . $mainFile,
		"<?php\n/*\nPlugin Name: Probe\nVersion: {$version}\n"
			. "Update URI: https://github.com/{$repository}\nRequires PHP: {$requiresPhp}\n"
			. "Requires at least: 6.5\n*/\n"
	);
	$payloadPath = null;
	if ( $includePayload ) {
		$payloadPath = tempnam( sys_get_temp_dir(), 'ran-preflight-payload-' );
		if ( false === $payloadPath ) {
			throw new \RuntimeException( 'Could not create the deterministic probe payload.' );
		}
		$payload = fopen( $payloadPath, 'wb' );
		if ( false === $payload || ! ftruncate( $payload, $payloadBytes ) ) {
			throw new \RuntimeException( 'Could not size the deterministic probe payload.' );
		}
		fclose( $payload );
		$payloadName = $packageRoot . '/fixture-payload.bin';
		$zip->addFile( $payloadPath, $payloadName );
		$zip->setCompressionName( $payloadName, \ZipArchive::CM_STORE );
	}
	$zip->close();
	if ( null !== $payloadPath && is_file( $payloadPath ) ) {
		unlink( $payloadPath );
	}

	return new ManagedPreflightProbeAsset( $path );
}

function managed_preflight_probe_json( mixed $value ): string {
	$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) ) {
		throw new \RuntimeException( 'Could not encode a deterministic provider response.' );
	}

	return $json;
}

const MANAGED_PREFLIGHT_MAX_ASSET_BYTES      = 50 * 1024 * 1024;
const MANAGED_PREFLIGHT_SMALL_MEMORY_CEILING = 32 * 1024 * 1024;
const MANAGED_PREFLIGHT_MAX_MEMORY_CEILING   = 96 * 1024 * 1024;
const MANAGED_PREFLIGHT_CPU_CEILING_MS       = 15000.0;
const MANAGED_PREFLIGHT_WALL_CEILING_MS      = 30000.0;

/**
 * @param array{incompatible?: int, outcome?: string, asset_bytes?: int} $options
 * @return array<string, int|float|string>
 */
function independent_target_probe(
	string $scenario,
	int $targetCount,
	array $options,
	string $expectedVerdict
): array {
	managed_preflight_reset();
	$probes = array();
	for ( $index = 1; $index <= $targetCount; ++$index ) {
		$probes[] = managed_preflight_probe( ( $targetCount * 1000 ) + $index, $options );
	}

	$result       = managed_preflight_measure(
		$scenario . '-' . $targetCount,
		$targetCount,
		$probes,
		static function ( array $probes ) use ( $expectedVerdict ): void {
			foreach ( $probes as $probe ) {
				managed_preflight_assert_verdict( $probe['preflight']->check(), $expectedVerdict );
			}
		}
	);
	$incompatible = (int) ( $options['incompatible'] ?? 0 );
	$candidates   = 8 === $incompatible ? 8 : $incompatible + 1;
	$downloads    = in_array( $options['outcome'] ?? 'success', array( '304', 'rate', 'timeout' ), true )
		? 0
		: $targetCount * $candidates;
	$hops         = 0 === $downloads ? $targetCount : $targetCount * ( 1 + ( 5 * $candidates ) );
	$logical      = $hops - $downloads;
	managed_preflight_assert_budget(
		$result,
		array(
			'logical_requests' => $logical,
			'transport_hops'   => $hops,
			'zip_downloads'    => $downloads,
			'download_bytes'   => managed_preflight_fixture_bytes( $probes, $downloads > 0 ),
		)
	);
	managed_preflight_discard( $probes );

	return $result;
}

/**
 * @return array<string, int|float|string>
 */
function cached_target_probe( string $scenario, int $targetCount, bool $force ): array {
	managed_preflight_reset();
	$cold = array();
	for ( $index = 1; $index <= $targetCount; ++$index ) {
		$cold[] = managed_preflight_probe( ( $targetCount * 1000 ) + $index );
	}
	foreach ( $cold as $probe ) {
		managed_preflight_assert_verdict( $probe['preflight']->check(), 'ready' );
	}
	managed_preflight_discard( $cold );

	$probes = array();
	for ( $index = 1; $index <= $targetCount; ++$index ) {
		$probes[] = managed_preflight_probe( ( $targetCount * 1000 ) + $index );
	}
	$result = managed_preflight_measure(
		$scenario . '-' . $targetCount,
		$targetCount,
		$probes,
		static function ( array $probes ) use ( $force ): void {
			foreach ( $probes as $probe ) {
				managed_preflight_assert_verdict( $probe['preflight']->check( $force ), 'ready' );
			}
		}
	);
	managed_preflight_assert_budget(
		$result,
		array(
			'logical_requests' => $force ? 5 * $targetCount : 0,
			'transport_hops'   => $force ? 6 * $targetCount : 0,
			'zip_downloads'    => $force ? $targetCount : 0,
			'download_bytes'   => managed_preflight_fixture_bytes( $probes, $force ),
		)
	);
	managed_preflight_discard( $probes );

	return $result;
}

/**
 * A first transport failure is persisted as a bounded cooldown. The second
 * check must fail from local state without another transport hop.
 *
 * @return array<string, int|float|string>
 */
function failure_cooldown_probe( int $targetCount ): array {
	managed_preflight_reset();
	$probes = array();
	for ( $index = 1; $index <= $targetCount; ++$index ) {
		$probes[] = managed_preflight_probe(
			( $targetCount * 1000 ) + $index,
			array( 'outcome' => 'timeout' )
		);
	}
	$result = managed_preflight_measure(
		'failure-cooldown-' . $targetCount,
		$targetCount,
		$probes,
		static function ( array $probes ): void {
			foreach ( $probes as $probe ) {
				managed_preflight_assert_verdict(
					$probe['preflight']->check(),
					'github_updater_http_transport_failed'
				);
				managed_preflight_assert_verdict(
					$probe['preflight']->check(),
					'github_updater_http_transport_failed'
				);
			}
		}
	);
	managed_preflight_assert_budget(
		$result,
		array(
			'logical_requests' => $targetCount,
			'transport_hops'   => $targetCount,
			'zip_downloads'    => 0,
			'download_bytes'   => 0,
		)
	);
	managed_preflight_discard( $probes );

	return $result;
}

/**
 * Interleave ten cold checks after every caller has read the empty cache. This
 * deterministically characterizes same-target concurrency without sleeping or
 * making network requests.
 *
 * @return array<string, int|float|string>
 */
function same_target_concurrent_probe(): array {
	managed_preflight_reset();
	$probes = array();
	$fibers = array();
	for ( $index = 0; $index < 10; ++$index ) {
		$probes[] = managed_preflight_probe( 999, array( 'suspend_first_request' => true ) );
		$fibers[] = new \Fiber(
			static fn (): CandidateValidation|\WP_Error => $probes[ $index ]['preflight']->check()
		);
	}
	$memoryBefore = memory_get_usage( false );
	memory_reset_peak_usage();
	$started   = hrtime( true );
	$cpu       = managed_preflight_cpu_ms();
	$leader    = null;
	$followers = array();
	foreach ( $fibers as $fiber ) {
		$fiber->start();
		if ( $fiber->isSuspended() ) {
			if ( null !== $leader ) {
				throw new \RuntimeException( 'More than one cold caller reached the remote barrier.' );
			}
			$leader = $fiber;
			continue;
		}
		$followers[] = $fiber->getReturn();
	}
	if ( ! $leader instanceof \Fiber || 9 !== count( $followers ) ) {
		throw new \RuntimeException( 'The cold race did not produce one leader and nine followers.' );
	}
	foreach ( $followers as $result ) {
		if ( ! $result instanceof \WP_Error
			|| 'github_updater_check_in_progress' !== $result->get_error_code()
			|| true !== ( $result->get_error_data()['retryable'] ?? null )
		) {
			throw new \RuntimeException( 'A cold follower did not receive the retryable in-progress verdict.' );
		}
	}
	$leader->resume();
	$result = $leader->getReturn();
	if ( ! $result instanceof CandidateValidation || ! $result->isReady() ) {
		throw new \RuntimeException( 'The cold-race leader did not become ready.' );
	}

	$requests  = array_sum(
		array_map( static fn ( array $probe ): int => $probe['transport']->requests(), $probes )
	);
	$downloads = array_sum(
		array_map( static fn ( array $probe ): int => $probe['transport']->downloads(), $probes )
	);
	$bytes     = array_sum(
		array_map( static fn ( array $probe ): int => $probe['transport']->downloadBytes(), $probes )
	);

	$result = managed_preflight_probe_result(
		'same-target-concurrent-10',
		1,
		$requests,
		$downloads,
		$bytes,
		$memoryBefore,
		$started,
		$cpu
	);
	managed_preflight_assert_budget(
		$result,
		array(
			'logical_requests' => 5,
			'transport_hops'   => 6,
			'zip_downloads'    => 1,
			'download_bytes'   => $probes[0]['fixtures'][0]->size(),
		)
	);
	managed_preflight_assert_cleanup( $probes );
	managed_preflight_discard( $probes );
	return $result;
}

/**
 * @return array<string, int|float|string>
 */
function outcome_probe( string $outcome, string $expectedVerdict ): array {
	managed_preflight_reset();
	$probe  = managed_preflight_probe( 70000, array( 'outcome' => $outcome ) );
	$result = managed_preflight_measure(
		'outcome-' . $outcome,
		1,
		array( $probe ),
		static function ( array $probes ) use ( $expectedVerdict ): void {
			managed_preflight_assert_verdict( $probes[0]['preflight']->check(), $expectedVerdict );
		}
	);
	$early  = in_array( $outcome, array( '304', 'rate', 'timeout' ), true );
	managed_preflight_assert_budget(
		$result,
		array(
			'logical_requests' => $early ? 1 : ( 'bad_digest' === $outcome ? 4 : 5 ),
			'transport_hops'   => $early ? 1 : ( 'bad_digest' === $outcome ? 5 : 6 ),
			'zip_downloads'    => $early ? 0 : 1,
			'download_bytes'   => managed_preflight_fixture_bytes( array( $probe ), ! $early ),
		)
	);
	managed_preflight_discard( array( $probe ) );

	return $result;
}

/**
 * @return array<string, int|float|string>
 */
function assurance_rejection_probe(): array {
	managed_preflight_reset();
	add_action(
		ReleaseAssurance::REGISTRATION_ACTION,
		static function ( ReleaseAssurance $assurance ): void {
			$assurance->register(
				static fn (): \WP_Error => new \WP_Error( 'fixture_assurance_rejected', 'Rejected.' )
			);
		}
	);
	ReleaseAssurance::selectForRequest();
	$probe  = managed_preflight_probe( 71000 );
	$result = managed_preflight_measure(
		'outcome-assurance-rejection',
		1,
		array( $probe ),
		static function ( array $probes ): void {
			managed_preflight_assert_verdict(
				$probes[0]['preflight']->check(),
				'fixture_assurance_rejected'
			);
		}
	);
	managed_preflight_assert_budget(
		$result,
		array(
			'logical_requests' => 5,
			'transport_hops'   => 6,
			'zip_downloads'    => 1,
			'download_bytes'   => managed_preflight_fixture_bytes( array( $probe ), true ),
		)
	);
	managed_preflight_discard( array( $probe ) );
	managed_preflight_reset();

	return $result;
}

/**
 * @return array<string, int|float|string>
 */
function maximum_asset_probe(): array {
	managed_preflight_reset();
	$probe  = managed_preflight_probe(
		72000,
		array( 'asset_bytes' => MANAGED_PREFLIGHT_MAX_ASSET_BYTES )
	);
	$result = managed_preflight_measure(
		'configured-maximum-asset',
		1,
		array( $probe ),
		static function ( array $probes ): void {
			managed_preflight_assert_verdict( $probes[0]['preflight']->check(), 'ready' );
		},
		MANAGED_PREFLIGHT_MAX_MEMORY_CEILING
	);
	managed_preflight_assert_budget(
		$result,
		array(
			'logical_requests' => 5,
			'transport_hops'   => 6,
			'zip_downloads'    => 1,
			'download_bytes'   => MANAGED_PREFLIGHT_MAX_ASSET_BYTES,
		)
	);
	managed_preflight_discard( array( $probe ) );

	return $result;
}

/**
 * @return array<string, int|float|string>
 */
function managed_preflight_probe_result(
	string $scenario,
	int $targets,
	int $transportHops,
	int $zipDownloads,
	int $downloadBytes,
	int $memoryBefore,
	int $started,
	float $cpuBefore
): array {
	return array(
		'scenario'          => $scenario,
		'targets'           => $targets,
		'logical_requests'  => $transportHops - $zipDownloads,
		'transport_hops'    => $transportHops,
		'zip_downloads'     => $zipDownloads,
		'download_bytes'    => $downloadBytes,
		'peak_memory_delta' => max( 0, memory_get_peak_usage( false ) - $memoryBefore ),
		'cpu_time_ms'       => round( managed_preflight_cpu_ms() - $cpuBefore, 3 ),
		'wall_time_ms'      => round( ( hrtime( true ) - $started ) / 1_000_000, 3 ),
	);
}

/**
 * @param list<array{
 *   preflight: ReleaseCandidatePreflight,
 *   transport: ManagedPreflightProbeTransport,
 *   fixtures: list<ManagedPreflightProbeAsset>
 * }> $probes
 * @param callable(array): void $execute
 * @return array<string, int|float|string>
 */
function managed_preflight_measure(
	string $scenario,
	int $targets,
	array $probes,
	callable $execute,
	int $memoryCeiling = MANAGED_PREFLIGHT_SMALL_MEMORY_CEILING
): array {
	memory_reset_peak_usage();
	$memoryBefore = memory_get_usage( false );
	$started      = hrtime( true );
	$cpuBefore    = managed_preflight_cpu_ms();
	$execute( $probes );
	$result = managed_preflight_probe_result(
		$scenario,
		$targets,
		array_sum(
			array_map( static fn ( array $probe ): int => $probe['transport']->requests(), $probes )
		),
		array_sum(
			array_map( static fn ( array $probe ): int => $probe['transport']->downloads(), $probes )
		),
		array_sum(
			array_map( static fn ( array $probe ): int => $probe['transport']->downloadBytes(), $probes )
		),
		$memoryBefore,
		$started,
		$cpuBefore
	);
	if ( $result['peak_memory_delta'] > $memoryCeiling
		|| $result['cpu_time_ms'] > MANAGED_PREFLIGHT_CPU_CEILING_MS
		|| $result['wall_time_ms'] > MANAGED_PREFLIGHT_WALL_CEILING_MS
	) {
		throw new \RuntimeException( $scenario . ' exceeded its deterministic resource ceiling.' );
	}
	managed_preflight_assert_cleanup( $probes );

	return $result;
}

/** @param CandidateValidation|\WP_Error $result */
function managed_preflight_assert_verdict( $result, string $expected ): void {
	if ( 'ready' === $expected ) {
		if ( ! $result instanceof CandidateValidation || ! $result->isReady() ) {
			throw new \RuntimeException( 'A deterministic preflight did not become ready.' );
		}
		return;
	}
	if ( $result instanceof CandidateValidation && $expected === $result->code() ) {
		return;
	}
	if ( ! $result instanceof \WP_Error || $expected !== $result->get_error_code() ) {
		throw new \RuntimeException( 'A deterministic rejection returned the wrong verdict.' );
	}
}

/**
 * @param array<string, int> $expected
 * @param array<string, int|float|string> $result
 */
function managed_preflight_assert_budget( array $result, array $expected ): void {
	foreach ( $expected as $metric => $ceiling ) {
		if ( $result[ $metric ] !== $ceiling ) {
			throw new \RuntimeException(
				(string) $result['scenario'] . ' expected ' . $metric . '=' . $ceiling
					. ', observed ' . $result[ $metric ] . '.'
			);
		}
	}
}

/** @param list<array{transport: ManagedPreflightProbeTransport}> $probes */
function managed_preflight_assert_cleanup( array $probes ): void {
	foreach ( $probes as $probe ) {
		if ( ! $probe['transport']->temporaryFilesWereDiscarded() ) {
			throw new \RuntimeException( 'An updater-owned streamed ZIP was not discarded.' );
		}
	}
}

/**
 * @param list<array{fixtures: list<ManagedPreflightProbeAsset>}> $probes
 */
function managed_preflight_discard( array $probes ): void {
	foreach ( $probes as $probe ) {
		foreach ( $probe['fixtures'] as $fixture ) {
			$fixture->discard();
		}
	}
}

/**
 * @param list<array{fixtures: list<ManagedPreflightProbeAsset>}> $probes
 */
function managed_preflight_fixture_bytes(
	array $probes,
	bool $all
): int {
	if ( ! $all ) {
		return 0;
	}
	$bytes = 0;
	foreach ( $probes as $probe ) {
		foreach ( $probe['fixtures'] as $fixture ) {
			$bytes += $fixture->size();
		}
	}

	return $bytes;
}

function managed_preflight_reset(): void {
	WordPressState::reset();
	$GLOBALS['wp_version'] = '6.5';
	ReleaseAssurance::selectForRequest();
}

function managed_preflight_cpu_ms(): float {
	$usage = getrusage();
	return ( (float) ( $usage['ru_utime.tv_sec'] ?? 0 ) * 1000 )
		+ ( (float) ( $usage['ru_utime.tv_usec'] ?? 0 ) / 1000 )
		+ ( (float) ( $usage['ru_stime.tv_sec'] ?? 0 ) * 1000 )
		+ ( (float) ( $usage['ru_stime.tv_usec'] ?? 0 ) / 1000 );
}

$warmup = managed_preflight_probe( 99999 );
$warmup['preflight']->check();
managed_preflight_discard( array( $warmup ) );

$results = array();
foreach ( array( 1, 5, 10, 20 ) as $targets ) {
	$results[] = independent_target_probe( 'compatible-cold', $targets, array(), 'ready' );
	$results[] = cached_target_probe( 'warm-cache', $targets, false );
	$results[] = cached_target_probe( 'unchanged-cached-verdict', $targets, false );
	$results[] = independent_target_probe(
		'one-incompatible',
		$targets,
		array( 'incompatible' => 1 ),
		'ready'
	);
	$results[] = independent_target_probe(
		'eight-incompatible',
		$targets,
		array( 'incompatible' => 8 ),
		'github_updater_no_eligible_release'
	);
	$results[] = failure_cooldown_probe( $targets );
	$results[] = cached_target_probe( 'forced-refresh', $targets, true );
}
$results[] = same_target_concurrent_probe();
$results[] = maximum_asset_probe();
$results[] = outcome_probe( '304', 'github_updater_no_eligible_release' );
$results[] = outcome_probe( 'rate', 'github_updater_rate_limited' );
$results[] = outcome_probe( 'timeout', 'github_updater_http_transport_failed' );
$results[] = outcome_probe( 'bad_digest', 'github_updater_downloaded_digest_mismatch' );
$results[] = outcome_probe( 'invalid_identity', CandidateValidation::UPDATE_URI_INVALID );
$results[] = assurance_rejection_probe();
echo managed_preflight_probe_json( $results ) . PHP_EOL;
