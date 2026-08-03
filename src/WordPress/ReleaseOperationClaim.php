<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

/**
 * One exact database-backed release-operation lease.
 *
 * @internal
 */
final class ReleaseOperationClaim {
	/** @param array<string, array<string, mixed>> $results */
	public function __construct(
		private string $table,
		private string $name,
		private string $target,
		private string $operation,
		private string $owner,
		private int $generation,
		private int $acquiredAt,
		private int $expiresAt,
		private array $results,
		private string $raw
	) {
	}

	public function table(): string {
		return $this->table;
	}

	public function name(): string {
		return $this->name;
	}

	public function target(): string {
		return $this->target;
	}

	public function operation(): string {
		return $this->operation;
	}

	public function owner(): string {
		return $this->owner;
	}

	public function generation(): int {
		return $this->generation;
	}

	public function expiresAt(): int {
		return $this->expiresAt;
	}

	public function acquiredAt(): int {
		return $this->acquiredAt;
	}

	/** @return array<string, array<string, mixed>> */
	public function results(): array {
		return $this->results;
	}

	public function raw(): string {
		return $this->raw;
	}
}
