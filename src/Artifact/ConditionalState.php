<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Endpoint-scoped HTTP conditional validators.
 */
final class ConditionalState {
	private ?string $etag;

	private ?string $lastModified;

	public function __construct(
		?string $etag = null,
		?string $lastModified = null
	) {
		if ( ! self::valuesAreValid( $etag, $lastModified ) ) {
			$this->etag         = null;
			$this->lastModified = null;
			return;
		}

		$this->etag         = $etag;
		$this->lastModified = $lastModified;
	}

	public function etag(): ?string {
		return $this->etag;
	}

	public function lastModified(): ?string {
		return $this->lastModified;
	}

	/**
	 * @return array<string, string>
	 */
	public function requestHeaders(): array {
		if ( ! self::valuesAreValid( $this->etag, $this->lastModified ) ) {
			return array();
		}

		$headers = array();
		if ( null !== $this->etag && '' !== $this->etag ) {
			$headers['If-None-Match'] = $this->etag;
		}
		if ( null !== $this->lastModified && '' !== $this->lastModified ) {
			$headers['If-Modified-Since'] = $this->lastModified;
		}

		return $headers;
	}

	private static function valuesAreValid(
		?string $etag,
		?string $lastModified
	): bool {
		return ( null === $etag || self::etagIsValid( $etag ) )
			&& ( null === $lastModified || self::lastModifiedIsValid( $lastModified ) );
	}

	private static function etagIsValid( string $etag ): bool {
		return strlen( $etag ) <= 512
			&& 1 === preg_match( '/\A(?:W\/)?"[\x21\x23-\x7E]*"\z/D', $etag );
	}

	private static function lastModifiedIsValid( string $lastModified ): bool {
		return strlen( $lastModified ) <= 128
			&& 1 === preg_match(
				'/\A(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), [0-9]{2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/D',
				$lastModified
			);
	}
}
