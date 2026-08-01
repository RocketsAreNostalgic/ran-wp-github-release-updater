<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Http;

/**
 * Normalized safe HTTP response.
 */
final class Response {
	/**
	 * @var array<string, string>
	 */
	private array $headers = array();

	/**
	 * @param array<string, string> $headers Response headers.
	 */
	public function __construct(
		private int $statusCode,
		array $headers = array(),
		private string $body = ''
	) {
		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( $name ) ] = trim( $value );
		}
	}

	public function statusCode(): int {
		return $this->statusCode;
	}

	public function header( string $name ): ?string {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}

	public function body(): string {
		return $this->body;
	}
}
