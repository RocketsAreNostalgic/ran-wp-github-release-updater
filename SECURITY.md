# Security policy

Beta releases are for controlled integration testing and are not covered by a
backport policy.

Report suspected vulnerabilities privately to the repository maintainers.
Please do not include access tokens, signed URLs, WordPress credentials, raw
HTTP responses, or temporary filesystem paths in a public issue.

The current Beta trust boundary accepts public or authenticated GitHub Release
assets only when the repository, exact release, tag commit, one uploaded ZIP
identity and size, GitHub SHA-256 digest, and downloaded SHA-256 digest agree.

Artifact integrity is distinct from package identity. Before an update offer is
published, the updater inspects only the canonical WordPress header file in
the already verified ZIP and requires its `Version:` header to match the
normalized tag version (`2.1` is accepted only as shorthand for `2.1.0`).
Missing, unreadable, invalid, ambiguous, and mismatched headers fail closed:
the release is not offered to WordPress Core. WordPress Core still owns archive
extraction and installation, with a second staged-header guard retained as
defense in depth.

Candidate-validation diagnostics and caches contain only bounded release tag,
normalized version, package-header version, and exact release/asset/digest
identity. They never retain credentials, Authorization headers, signed URLs,
raw HTTP responses, archive contents, or temporary archive paths.

Private credentials are resolved at request time, sent only to
`api.github.com`, and removed before following an allowlisted release-asset
redirect. Redirects are bounded and validated with WordPress safe HTTP.

Prospective first-install inspection returns only bounded display-safe metadata
and a `v1:<sha256>` continuity fingerprint. It does not return credentials,
signed URLs, descriptors or temporary paths. Inspection downloads, validates
and discards the exact ZIP. Acquisition repeats exact release resolution,
freshly downloads the ZIP, verifies fingerprint continuity, archive bounds and
identity, and returns a cleanup-owning `ValidatedReleaseArtifact`. The caller
must call `discard()` or call
`handoffToCore()` exactly once. Only handoff returns a `ClaimedArtifact` path
and transfers cleanup ownership for WordPress installation and package
adoption.

An optional checker may add a rejection only after built-in verification. It
receives normalized, path-free, credential-free evidence and cannot waive a
built-in failure or install a package. GitHub immutable-release or Artifact
Attestation policy is deliberately deferred to a future add-on using that
seam. The [release assurance extension contract](docs/release-assurance-extension.md)
defines the complete registration, evidence, failure, and authority boundary.
