<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Safe rate-limit classification derived from an ordinary response.
 */
final class RateLimit {
	public const NONE    = 'none';
	public const LIMITED = 'limited';

	public function __construct(
		private string $classification = self::NONE,
		private ?int $remaining = null,
		private ?int $resetAt = null,
		private ?int $cooldownSeconds = null
	) {
	}

	public function classification(): string {
		return $this->classification;
	}

	public function remaining(): ?int {
		return $this->remaining;
	}

	public function resetAt(): ?int {
		return $this->resetAt;
	}

	public function cooldownSeconds(): ?int {
		return $this->cooldownSeconds;
	}

	public function isLimited(): bool {
		return self::LIMITED === $this->classification;
	}
}
