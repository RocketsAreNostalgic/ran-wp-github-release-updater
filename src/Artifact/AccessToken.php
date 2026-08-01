<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Request-scoped GitHub credential source.
 *
 * The resolver is deliberately invoked only immediately before a remote
 * request. Its value is never exposed through diagnostics or persisted state.
 */
final class AccessToken {
	/**
	 * @var \Closure(): mixed|null
	 */
	private ?\Closure $resolver = null;

	private function __construct( private ?string $token, private bool $configured ) {
	}

	/**
	 * @return self|\WP_Error
	 */
	public static function fromValue( mixed $value ) {
		if ( null === $value ) {
			return new self( null, false );
		}

		if ( is_string( $value ) ) {
			$valid = self::validateToken( $value );
			if ( $valid instanceof \WP_Error ) {
				return $valid;
			}

			return new self( $value, true );
		}

		if ( is_callable( $value ) ) {
			$instance           = new self( null, true );
			$instance->resolver = \Closure::fromCallable( $value );
			return $instance;
		}

		return new \WP_Error(
			'github_updater_invalid_access_token',
			'The GitHub access token must be a string, callable, or null.'
		);
	}

	public function isConfigured(): bool {
		return $this->configured;
	}

	/**
	 * Resolve the credential for one top-level request.
	 *
	 * @return string|null|\WP_Error
	 */
	public function resolve() {
		$value = $this->token;
		if ( null !== $this->resolver ) {
			try {
				$value = ( $this->resolver )();
			} catch ( \Throwable ) {
				return new \WP_Error(
					'github_updater_credentials_unavailable',
					'GitHub credentials are temporarily unavailable.'
				);
			}
		}

		if ( null === $value ) {
			return $this->configured
				? new \WP_Error(
					'github_updater_credentials_unavailable',
					'GitHub credentials are temporarily unavailable.'
				)
				: null;
		}
		if ( ! is_string( $value ) ) {
			return new \WP_Error(
				'github_updater_credentials_unavailable',
				'GitHub credentials are temporarily unavailable.'
			);
		}

		$valid = self::validateToken( $value );
		return $valid instanceof \WP_Error ? $valid : $value;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function validateToken( string $token ) {
		if ( '' === $token
			|| strlen( $token ) > 512
			|| 1 === preg_match( '/[^\x21-\x7e]/', $token )
		) {
			return new \WP_Error(
				'github_updater_invalid_access_token',
				'The GitHub access token is invalid.'
			);
		}

		return true;
	}
}
