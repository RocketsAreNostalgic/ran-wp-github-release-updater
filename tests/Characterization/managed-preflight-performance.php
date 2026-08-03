<?php

declare(strict_types=1);

use RAN\WPGitHubReleaseUpdater\V1\Artifact\GitHubReleaseArtifactService;
use RAN\WPGitHubReleaseUpdater\V1\Http\Request;
use RAN\WPGitHubReleaseUpdater\V1\Http\Response;
use RAN\WPGitHubReleaseUpdater\V1\Http\Transport;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\CandidateValidation;
use RAN\WPGitHubReleaseUpdater\V1\WordPress\GitHubReleaseArtifactClient;
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
 * Deterministic request/ZIP observation seam for characterization only.
 */
final class ManagedPreflightProbeTransport implements Transport {
	/** @var list<array{response: Response|\WP_Error, download: ?string}> */
	private array $queue = array();

	private int $requests      = 0;
	private int $downloads     = 0;
	private int $downloadBytes = 0;
	private bool $suspended    = false;

	public function __construct( private bool $suspendFirstRequest = false ) {
	}

	public function queue( Response|\WP_Error $response, ?string $download = null ): void {
		$this->queue[] = array(
			'response' => $response,
			'download' => $download,
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
			file_put_contents( $request->streamTo(), $next['download'] );
			chmod( $request->streamTo(), 0600 );
			++$this->downloads;
			$this->downloadBytes += strlen( $next['download'] );
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
}

/**
 * @return array{preflight: ReleaseCandidatePreflight, transport: ManagedPreflightProbeTransport}
 */
function managed_preflight_probe( int $identity, bool $suspendFirstRequest = false ): array {
	$repository   = 'fixture-owner/fixture-package-' . $identity;
	$repositoryId = (string) ( 100000 + $identity );
	$packageRoot  = 'fixture-package-' . $identity;
	$mainFile     = $packageRoot . '.php';
	$version      = '1.2.3';
	$tag          = 'v' . $version;
	$releaseId    = 200000 + $identity;
	$assetId      = 300000 + $identity;
	$zipBytes     = managed_preflight_probe_zip( $packageRoot, $mainFile, $repository, $version );
	$release      = array(
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
				'size'   => strlen( $zipBytes ),
				'state'  => 'uploaded',
				'digest' => 'sha256:' . hash( 'sha256', $zipBytes ),
			),
		),
	);
	$transport    = new ManagedPreflightProbeTransport( $suspendFirstRequest );
	$transport->queue( new Response( 200, array(), managed_preflight_probe_json( array( $release ) ) ) );
	$transport->queue( new Response( 200, array(), managed_preflight_probe_json( $release ) ) );
	$transport->queue(
		new Response(
			200,
			array(),
			managed_preflight_probe_json( array( 'sha' => str_repeat( '1', 40 ) ) )
		)
	);
	$transport->queue(
		new Response(
			302,
			array(
				'Location' => 'https://release-assets.githubusercontent.com/probe-' . $identity
					. '?Expires=9999999999',
			)
		)
	);
	$transport->queue( new Response( 200 ), $zipBytes );
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
	);
}

function managed_preflight_probe_zip(
	string $packageRoot,
	string $mainFile,
	string $repository,
	string $version
): string {
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
			. "Update URI: https://github.com/{$repository}\nRequires PHP: 8.0\n"
			. "Requires at least: 6.5\n*/\n"
	);
	$zip->close();
	$bytes = file_get_contents( $path );
	unlink( $path );
	if ( ! is_string( $bytes ) ) {
		throw new \RuntimeException( 'Could not read the deterministic probe ZIP.' );
	}

	return $bytes;
}

function managed_preflight_probe_json( mixed $value ): string {
	$json = json_encode( $value, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $json ) ) {
		throw new \RuntimeException( 'Could not encode a deterministic provider response.' );
	}

	return $json;
}

/**
 * @return array<string, int|float|string>
 */
function independent_target_probe( int $targetCount ): array {
	WordPressState::reset();
	$GLOBALS['wp_version'] = '6.5';
	memory_reset_peak_usage();
	$memoryBefore = memory_get_usage( false );
	$started      = hrtime( true );
	$requests     = 0;
	$downloads    = 0;
	$bytes        = 0;
	for ( $index = 1; $index <= $targetCount; ++$index ) {
		$probe  = managed_preflight_probe( $index );
		$result = $probe['preflight']->check();
		if ( ! $result instanceof CandidateValidation || ! $result->isReady() ) {
			throw new \RuntimeException( 'An independent deterministic preflight did not become ready.' );
		}
		$requests  += $probe['transport']->requests();
		$downloads += $probe['transport']->downloads();
		$bytes     += $probe['transport']->downloadBytes();
	}

	return managed_preflight_probe_result(
		'independent-' . $targetCount,
		$targetCount,
		$requests,
		$downloads,
		$bytes,
		$memoryBefore,
		$started
	);
}

/**
 * Interleave ten cold checks after every caller has read the empty cache. This
 * deterministically characterizes same-target concurrency without sleeping or
 * making network requests.
 *
 * @return array<string, int|float|string>
 */
function same_target_concurrent_probe(): array {
	WordPressState::reset();
	$GLOBALS['wp_version'] = '6.5';
	memory_reset_peak_usage();
	$memoryBefore = memory_get_usage( false );
	$started      = hrtime( true );
	$probes       = array();
	$fibers       = array();
	for ( $index = 0; $index < 10; ++$index ) {
		$probes[] = managed_preflight_probe( 999, true );
		$fibers[] = new \Fiber(
			static fn (): CandidateValidation|\WP_Error => $probes[ $index ]['preflight']->check()
		);
	}
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
		$started
	);
	if ( 5 !== $result['logical_requests'] || 1 !== $result['zip_downloads'] ) {
		throw new \RuntimeException( 'The cold-race request or ZIP budget regressed.' );
	}
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
	int $started
): array {
	return array(
		'scenario'          => $scenario,
		'targets'           => $targets,
		'logical_requests'  => $transportHops - $zipDownloads,
		'transport_hops'    => $transportHops,
		'zip_downloads'     => $zipDownloads,
		'download_bytes'    => $downloadBytes,
		'peak_memory_delta' => max( 0, memory_get_peak_usage( false ) - $memoryBefore ),
		'wall_time_ms'      => round( ( hrtime( true ) - $started ) / 1_000_000, 3 ),
	);
}

$warmup = managed_preflight_probe( 99999 );
$warmup['preflight']->check();
WordPressState::reset();

$results   = array_map( 'independent_target_probe', array( 1, 5, 10, 20 ) );
$results[] = same_target_concurrent_probe();
echo managed_preflight_probe_json( $results ) . PHP_EOL;
