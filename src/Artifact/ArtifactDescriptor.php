<?php

declare(strict_types=1);

namespace RAN\WPGitHubReleaseUpdater\V1\Artifact;

/**
 * Immutable path-free verified release description.
 */
final class ArtifactDescriptor {
	public function __construct(
		private ReleaseQuery $query,
		private Repository $repository,
		private int $releaseId,
		private string $tag,
		private string $version,
		private string $commit,
		private bool $prerelease,
		private string $detailsUrl,
		private ReleaseAsset $zipAsset,
		private bool $immutable
	) {
	}

	public function query(): ReleaseQuery {
		return $this->query;
	}

	public function repository(): Repository {
		return $this->repository;
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

	public function isPrerelease(): bool {
		return $this->prerelease;
	}

	public function detailsUrl(): string {
		return $this->detailsUrl;
	}

	public function zipAsset(): ReleaseAsset {
		return $this->zipAsset;
	}

	public function isImmutable(): bool {
		return $this->immutable;
	}

	public function equals( self $other ): bool {
		return $this->repository->equals( $other->repository )
			&& $this->releaseId === $other->releaseId
			&& $this->tag === $other->tag
			&& $this->version === $other->version
			&& $this->commit === $other->commit
			&& $this->prerelease === $other->prerelease
			&& $this->detailsUrl === $other->detailsUrl
			&& $this->zipAsset->equals( $other->zipAsset )
			&& $this->immutable === $other->immutable;
	}
}
