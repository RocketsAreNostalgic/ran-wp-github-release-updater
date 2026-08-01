# Release assurance extension

This is the canonical public contract for the updater's one optional,
request-local, rejection-only release assurance checker. The PHP docblock on
`ReleaseAssurance::register()` remains the implementation-level summary of the
same boundary.

## Registration and lifetime

Listen to the versioned WordPress action:

```php
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseAssurance;

add_action(
	'ran_wp_github_release_updater_v1_assurance_registration',
	static function ( ReleaseAssurance $assurance ): void {
		$assurance->register(
			static function ( array $evidence ) {
				return null;
			}
		);
	}
);
```

`ReleaseAssurance::register(callable $checker): bool` returns `true` only when
the one registration slot is accepted. The selected runtime dispatches the
action once before it constructs release clients and plugin or theme targets,
then immediately seals the request-local object. Registering a second checker,
or registering after sealing, returns `false` and invalidates assurance for the
request. Callback order never chooses a winner. With no checker, the sealed
object is neutral and all built-in verification remains sufficient.

## Evidence

The checker runs only after the updater's built-in exact-release, asset,
downloaded-digest, archive, WordPress-header, identity, and compatibility
checks have passed. It receives this normalized, path-free array:

```text
array{
  repository: string,
  provider_repository_id: ?string,
  release_id: int,
  tag: string,
  version: string,
  commit: string,
  prerelease: bool,
  immutable: bool,
  zip_asset_id: int,
  zip_name: string,
  zip_size: int,
  github_sha256: string,
  local_sha256: string,
  candidate_validation: array{
    state: string,
    code: string,
    release_tag: string,
    release_version: string,
    package_header_version: ?string,
    requires_php: ?string,
    requires_wordpress: ?string,
    identity: array{
      release_id: int,
      tag: string,
      zip_asset_id: int,
      sha256: string,
      package_type: string,
      header_file: string
    }
  }
}
```

`candidate_validation` is the exact bounded shape emitted by
`CandidateValidation::toArray()`. The same contract applies to plugins and
themes; `candidate_validation.identity.package_type` and `header_file` carry
the target-specific identity.

The `immutable` field is GitHub's reported release boolean. It is evidence for
a policy decision, not proof that this updater cryptographically verified a
GitHub release attestation or a separate Artifact Attestation. GitHub's
[immutable releases documentation](https://docs.github.com/en/code-security/concepts/supply-chain-security/immutable-releases)
describes the platform protection and generated release attestation.

## Result and failure semantics

The checker must return one of:

- `null` to add no rejection; or
- `WP_Error` to reject the package. Only its sanitized error code is retained;
  the updater returns its own bounded generic message.

The boundary fails closed:

- duplicate or late registration rejects with
  `github_updater_release_assurance_duplicate`;
- a thrown exception rejects with `github_updater_release_assurance_failed`;
- any non-`null`, non-`WP_Error` result rejects with
  `github_updater_release_assurance_invalid_result`; and
- an empty or overlong checker error code becomes
  `github_updater_release_assurance_rejected`.

## Authority boundary

The checker can add a rejection only. It cannot waive or replace a built-in
failure. The updater does not provide credentials, authorization headers,
signed URLs, raw responses, archive contents, or local filesystem paths. The
extension contract grants no authority to download or install an artifact,
mutate updater state, or create a second WordPress update path.

A future add-on owns any GitHub immutable-release, release-attestation, or
Artifact Attestation verification policy and its dependencies. That includes
its remote verification and authentication policy, credential custody, trust
roots, caching and failure behavior, and comparison of verified claims with
the supplied repository, release, commit, asset, and locally calculated digest
evidence. It reports only its rejection through this checker; WordPress Core
remains the installation authority.
