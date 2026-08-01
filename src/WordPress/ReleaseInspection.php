<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\WordPress;

use RAN\WPGitHubReleaseUpdater\V1\Artifact\ArtifactDescriptor;

/**
 * Request-local exact release metadata from a downloaded and discarded ZIP.
 */
final readonly class ReleaseInspection {

	private function __construct(
		private int $releaseId,
		private string $tag,
		private string $version,
		private string $commit,
		private string $detailsUrl,
		private string $packageType,
		private string $packageRoot,
		private string $mainFile,
		private ReleaseFingerprint $fingerprint
	) {
	}

	public static function fromDescriptor(
		ArtifactDescriptor $descriptor,
		CandidateValidation $validation,
		ReleaseFingerprint $fingerprint
	): self {
		$identity = $validation->identity();
		$path     = explode( '/', $identity['header_file'], 2 );

		return new self(
			$descriptor->releaseId(),
			$descriptor->tag(),
			$descriptor->version(),
			$descriptor->commit(),
			$descriptor->detailsUrl(),
			$identity['package_type'],
			$path[0],
			$path[1],
			$fingerprint
		);
	}

	public function releaseId(): int {
		return $this->releaseId;
	}

	public function tag(): string {
		return $this->tag;
	}

	public function version(): string {
		return $this->version;
	}

	public function commit(): string {
		return $this->commit;
	}

	public function detailsUrl(): string {
		return $this->detailsUrl;
	}

	public function packageType(): string {
		return $this->packageType;
	}

	public function packageRoot(): string {
		return $this->packageRoot;
	}

	public function mainFile(): string {
		return $this->mainFile;
	}

	public function fingerprint(): ReleaseFingerprint {
		return $this->fingerprint;
	}
}
