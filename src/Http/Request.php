<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Http;

/**
 * One bounded safe HTTP GET request.
 */
final class Request {
	/**
	 * @var array<string, string>
	 */
	private array $headers;

	/**
	 * @param array<string, string> $headers Request headers.
	 */
	public function __construct(
		private string $url,
		array $headers = array(),
		private int $timeout = 15,
		private int $responseSizeLimit = 262144,
		private ?string $streamTo = null,
		private int $redirection = 0
	) {
		$this->headers = $headers;
	}

	public function url(): string {
		return $this->url;
	}

	/**
	 * @return array<string, string>
	 */
	public function headers(): array {
		return $this->headers;
	}

	public function timeout(): int {
		return $this->timeout;
	}

	public function responseSizeLimit(): int {
		return $this->responseSizeLimit;
	}

	public function streamTo(): ?string {
		return $this->streamTo;
	}

	public function redirection(): int {
		return max( 0, min( 5, $this->redirection ) );
	}
}
