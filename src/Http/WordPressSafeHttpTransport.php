<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Http;

/**
 * WordPress safe-HTTP implementation.
 */
final class WordPressSafeHttpTransport implements Transport {
	/**
	 * @return Response|\WP_Error
	 */
	public function get( Request $request ) {
		$args = array(
			'headers'             => $request->headers(),
			'timeout'             => $request->timeout(),
			'redirection'         => $request->redirection(),
			'limit_response_size' => $request->responseSizeLimit(),
		);

		if ( null !== $request->streamTo() ) {
			$args['stream']   = true;
			$args['filename'] = $request->streamTo();
		}

		$response = wp_safe_remote_get( $request->url(), $args );
		if ( $response instanceof \WP_Error ) {
			return $response;
		}
		$statusCode = wp_remote_retrieve_response_code( $response );
		if ( is_string( $statusCode ) && (string) (int) $statusCode === $statusCode ) {
			$statusCode = (int) $statusCode;
		}
		if ( ! is_int( $statusCode ) || 100 > $statusCode || 599 < $statusCode ) {
			return new \WP_Error(
				'github_updater_invalid_response_status',
				'GitHub returned an invalid HTTP response status.'
			);
		}

		$headers    = array();
		$rawHeaders = wp_remote_retrieve_headers( $response );
		foreach ( $rawHeaders as $name => $value ) {
			if ( is_string( $name ) && ( is_string( $value ) || is_numeric( $value ) ) ) {
				$headers[ $name ] = (string) $value;
			}
		}

		return new Response(
			$statusCode,
			$headers,
			null === $request->streamTo() ? wp_remote_retrieve_body( $response ) : ''
		);
	}
}
