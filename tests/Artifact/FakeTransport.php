<?php

declare(strict_types=1);

namespace Tests\Artifact;

use RAN\WPGitHubReleaseUpdater\V1\Http\Request;
use RAN\WPGitHubReleaseUpdater\V1\Http\Response;
use RAN\WPGitHubReleaseUpdater\V1\Http\Transport;

/**
 * Queued HTTP transport for artifact tests.
 */
final class FakeTransport implements Transport {
	/**
	 * @var list<array{response: Response|\WP_Error|\Throwable, download: ?string}>
	 */
	private array $queue = array();

	/**
	 * @var list<Request>
	 */
	private array $requests = array();

	public function queue( Response|\WP_Error $response, ?string $download = null ): void {
		$this->queue[] = array(
			'response' => $response,
			'download' => $download,
		);
	}

	public function queueThrowable( \Throwable $throwable ): void {
		$this->queue[] = array(
			'response' => $throwable,
			'download' => null,
		);
	}

	/**
	 * @return Response|\WP_Error
	 */
	public function get( Request $request ) {
		$this->requests[] = $request;
		$next             = array_shift( $this->queue );
		if ( null === $next ) {
			return new \WP_Error( 'fake_queue_empty', 'No fake response was queued.' );
		}
		if ( $next['response'] instanceof \Throwable ) {
			throw $next['response'];
		}

		if ( null !== $request->streamTo() && null !== $next['download'] ) {
			file_put_contents( $request->streamTo(), $next['download'] );
			chmod( $request->streamTo(), 0600 );
		}

		return $next['response'];
	}

	/**
	 * @return list<Request>
	 */
	public function requests(): array {
		return $this->requests;
	}
}
