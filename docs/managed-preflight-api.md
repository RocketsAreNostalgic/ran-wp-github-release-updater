# Managed preflight and prospective release API

This guide documents the WordPress-facing APIs that managed consumers — such as
deployment controllers — use instead of constructing artifact descriptors or
parsing ZIP files themselves. Ordinary plugin and theme integrations only need
the bootstrap facade documented in the [README](../README.md).

Classes under `src/Artifact` implement the trust engine and are internal.
Consumers should not construct or introspect them directly.

## Managed-release preflight

`ReleaseCandidatePreflight::fromTarget()` builds a preflight for a target that
is already installed.

```php
$preflight = ReleaseCandidatePreflight::fromTarget( array(
	'repository' => 'RocketsAreNostalgic/example-plugin',
	'providerRepositoryId' => '123456789',
	'pluginSlug' => 'example-plugin',
	'mainFile' => 'example-plugin.php',
	'channel' => 'stable',
	'accessToken' => static fn (): ?string => getenv( 'RAN_GITHUB_TOKEN' ) ?: null,
	'packageType' => 'plugin',
) );

$validation = is_wp_error( $preflight ) ? $preflight : $preflight->check();
```

For a theme use `packageType => 'theme'`, `themeRoot => 'example-theme'`, and
omit `mainFile`; the preflight uses `style.css`.

`check()` preserves the released bounded row-backed verdict cache; pass `true`
to force fresh discovery and archive inspection. It returns a
`CandidateValidation` object with only safe fields: `state`, `code`,
`release_tag`, normalized `release_version`, `package_header_version`, and
exact release/ZIP/digest identity. `cacheDuration` remains configurable from
300 to 86,400 seconds and defaults to six hours. Remote failures return a
credential-free `WP_Error` with the original updater error code and any bounded
rate-limit cooldown.

`CandidateValidation::relationshipTo()` is the canonical offered-versus-
installed release relationship. It returns only `newer`, `same`, `older`, or
`invalid`, so consumers do not need to reproduce the updater's SemVer rules.

This release contract uses the embedded WordPress `Version:` and `Update URI`
headers. WordPress.org `Stable tag`, SVN directories, readmes, changelogs, and
`package.json` are not considered.

## Coordination state

The updater retains exactly one non-autoloaded main-site option row for each
target it has coordinated. That bounded row is intentional authority state, not
a transient lock: its idle tombstone preserves the monotonic fencing generation
across later checks and installations. Repeated operations reuse the same row,
and object-cache availability or eviction never changes ownership.

The package does not delete a row merely because one consumer is temporarily
inactive; removing a consumer therefore leaves that single inert row unless
site maintenance deliberately removes the consumer's data.

## Lease timing

Discovery and managed-preflight claims last 600 seconds by default.
Installation claims last 3,600 seconds from the latest successful package
checkpoint, matching WordPress Core's hour-scale automatic-updater boundary.
Normal success or failure releases the claim immediately; the duration is the
nominal bounded abandoned-operation recovery window, not a minimum wait. Rapid
same-database-second checkpoints may extend the exact expiry by a few seconds
so each compare-and-set renewal writes observably different bytes.

Developers may set either a WordPress constant in `wp-config.php` or the
same-named process environment variable:

```php
define( 'RAN_WP_GITHUB_RELEASE_UPDATER_DISCOVERY_LEASE_SECONDS', 900 );
define( 'RAN_WP_GITHUB_RELEASE_UPDATER_INSTALL_LEASE_SECONDS', 7200 );
```

| Setting                                                 | Default | Accepted range |
| ------------------------------------------------------- | ------: | -------------: |
| `RAN_WP_GITHUB_RELEASE_UPDATER_DISCOVERY_LEASE_SECONDS` |     600 |       60–3,600 |
| `RAN_WP_GITHUB_RELEASE_UPDATER_INSTALL_LEASE_SECONDS`   |   3,600 |     600–86,400 |

A defined WordPress constant takes precedence over the corresponding
environment variable. Values must be integers or unsigned decimal strings;
invalid or out-of-range selected values use the safe default. Configure every
web, cron and CLI runtime consistently. The stored database expiry remains the
sole ownership authority even when processes disagree about configuration.

The package revalidates ownership at every hook WordPress exposes, including
immediately before returning from `upgrader_source_selection`. WordPress then
owns backup, destination removal and copying without another package hook.
Accordingly, the final filesystem transition has WordPress Core-equivalent
concurrency semantics rather than a claim of absolute fencing across an
indefinitely suspended PHP worker. No file sentinel or second installer is
introduced.

The reviewed [install-session ownership gate](install-session-ownership-gate.md)
retains `NativePluginUpdater` as the single WordPress adapter. A proposed
hook-free session extraction could relocate fields, but could not delete total
code, lifecycle branches or custody owners without splitting shutdown and
persistent-state transitions. Reopen that decision only on new state, another
illegal-state lifecycle defect, duplicated cleanup or a second independently
changing install lifecycle.

## Prospective first-install verification

An authorised consumer can inspect a package that is not installed yet without
receiving credentials, internal descriptors, signed URLs or temporary paths.

The selected runtime advertises this strict custody contract as
`ReleaseCandidatePreflight::PROSPECTIVE_API_VERSION === 4`.

```php
$preflight = ReleaseCandidatePreflight::fromProspectiveTarget( array(
	'repository' => 'RocketsAreNostalgic/example-plugin',
	'providerRepositoryId' => '123456789',
	'channel' => 'stable',
	'accessToken' => static fn (): ?string => getenv( 'RAN_GITHUB_TOKEN' ) ?: null,
	'packageType' => 'plugin',
) );

$candidates = is_wp_error( $preflight ) ? $preflight : $preflight->listCandidates();
$selected = is_wp_error( $candidates ) ? $candidates : $candidates[0];
$inspection = is_wp_error( $selected )
	? $selected
	: $preflight->inspectExact( $selected->releaseId(), $selected->tag() );

$artifact = is_wp_error( $inspection )
	? $inspection
	: $preflight->acquireExact(
		$selected->releaseId(),
		$selected->tag(),
		$inspection->fingerprint()
	);
```

`listCandidates()` returns up to eight semantically ordered, channel-eligible
`ProspectiveReleaseCandidate` summaries. Each exposes the release ID, tag,
canonical version, prerelease status, publication time, and expected asset
names. The existing `discover()` method remains available for consumers that
only need the newest candidate.

`inspectExact()` downloads, validates and discards the selected ZIP.
`ReleaseInspection` contains bounded display-safe scalars and a compact
`v1:<sha256>` continuity fingerprint derived from that exact ZIP and its
validated headers. Post that fingerprint with the existing authorised install
request, parse it with `ReleaseFingerprint::fromString()`, and pass it to
`acquireExact()`. Acquisition re-describes the exact published release ID and
tag, resolves its published tag commit, freshly downloads the release asset,
rejects any release, commit, asset, digest, or package-identity change, and
validates the ZIP in one bounded inventory pass. No branch value participates
in published-release authority. The deliberate second download means
display-time inspection is never reused as installation custody.

### Artifact custody

The returned `ValidatedReleaseArtifact` retains cleanup ownership. Call
`discard()` to abandon it or call `handoffToCore()` exactly once immediately
before WordPress Core consumes the resulting `ClaimedArtifact`. The claim
retains the updater-verified digest and file identity:
`ClaimedArtifact::assertUnchanged()` returns that frozen snapshot only while the
private archive is unchanged, and `ClaimedArtifact::discard()` deletes only
that same file. `ClaimedArtifact::path()` remains available for the immediate
WordPress Core handoff. The updater does not install or adopt the package.

## Request and transport budget

The normal single-page, no-redirect request characterization is:

| Operation                                 | Logical requests |
| ----------------------------------------- | ---------------: |
| Native offer discovery and ZIP validation |                5 |
| Native fresh pre-install acquisition      |                4 |
| Prospective candidate list                |                2 |
| Prospective exact review                  |                4 |
| Prospective exact acquisition             |                4 |

A full prospective list, review, and acquisition is therefore 10 logical
requests and two ZIP downloads. Each ZIP download may add one allowlisted
redirect. An incompatible native candidate adds four requests before the
selector can safely try the next release. This is deliberate: headers inside
the exact ZIP are the compatibility authority, and neither display-time bytes
nor an earlier candidate verdict are installation custody. No cross-request
pool, second cache, or retained ZIP is introduced.

Native discovery scans at most two 20-release pages and describes and downloads
at most two ZIP-backed compatibility candidates. The prospective selection UI
still lists up to eight lightweight release summaries without inspecting ZIPs.
Release-list responses are capped at 256 KiB per page and 512 KiB in total.
Every request has a ten-second timeout and follows at most one validated
redirect. Authentication, transport and rate-limit failures retain their exact
diagnostic classification and cooldown. A failed or exhausted discovery keeps
the last verified cache record but returns the incoming WordPress host-filter
value; only a verified RAN offer or the explicit disabled policy replaces it.

One compatible cold target uses five logical requests, six transport hops and
one ZIP. The terminal two-incompatible case is capped at nine logical requests,
11 transport hops and two ZIPs per target. At 1/5/10/20 independent targets,
that terminal envelope is therefore 9/45/90/180 logical requests,
11/55/110/220 transport hops and 2/10/20/40 ZIPs. Multi-target consumers should
configure authenticated GitHub access; rate limiting remains fail-closed.

## Archive size bounds

Preflight caps total expanded archive entries at 127,826,407 bytes (about
121.9 MiB), keeping WordPress Core's `2.1` working-space estimate within the
documented 256 MiB ceiling. The package also rejects a larger Core
`pre_unzip_file` report for the exact verified archive.

After Core extracts the ZIP, it validates the canonical root, entry file,
version and compatibility headers. If the plugin or theme is installed under a
safe noncanonical directory name, it maps only Core's staged directory through
`WP_Filesystem`; it never renames, deletes or copies the live installed
directory itself.

The staged header check remains a defense-in-depth guard after Core extraction.
It reads a size-bounded metadata file through the active `WP_Filesystem`
transport, applies the same `2.1`/`2.1.0` comparison and reports a
release-version mismatch without attempting to repair the package.

## Cache invalidation

`$updater->refresh()` clears only this target's package cache and Core's native
plugin or theme update transient. It performs no remote request; the next
normal WordPress update check repopulates both. The package does not expose a
second installer, scheduler, candidate store, or CLI.

## Related documents

- [Install-session ownership gate](install-session-ownership-gate.md)
- [Release assurance extension](release-assurance-extension.md)
- [Exact source candidate and consumer lifecycle](exact-source-candidate-consumer-lifecycle.md)
