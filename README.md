# RAN WordPress GitHub Release Updater

A Composer library that lets WordPress plugins and themes offer updates from
exact GitHub Release assets through WordPress Core's native update lifecycle.

Released changes are recorded in the Release Please-owned
[CHANGELOG.md](CHANGELOG.md). Accepted unreleased Conventional Commits are
summarized in the active Release Please proposal; dirty local work is in
neither record.

## Beta 1

Beta 1 supports public and private GitHub repositories, plugins and themes,
and multiple independent package copies in one WordPress request.
Its request-local broker selects the highest compatible V1 runtime regardless
of plugin load order, uses a deterministic path tie-break for equivalent
versions, and loads only that runtime. Each consumer retains its own
configuration, cache and updater target.

Every update remains bound to:

- the configured repository and exact GitHub release;
- the release tag's resolved commit;
- exactly one uploaded ZIP asset;
- GitHub's asset size and SHA-256 digest; and
- the SHA-256 digest of the downloaded file.

Before an update is offered, the library also binds that exact release
identity to the package WordPress will install. It downloads the exact
already-digest-verified ZIP to temporary storage, reads only the canonical
plugin main file (or a theme root's `style.css`) directly from the archive, and
compares its `Version:` header with the normalized release tag. It never
extracts or installs the archive during this check.

Release tags use full `MAJOR.MINOR.PATCH` versions with an optional canonical
SemVer prerelease suffix. A stable WordPress header may use
the normal two-part shorthand: `2.1` is equivalent to `2.1.0`. A prerelease
header must contain the complete canonical value, such as `2.1.0-beta.2`.
Leading `v`, shortened prereleases, extra components, and other malformed
headers are blocked. A missing, unreadable, invalid, or mismatched header is a
fail-closed result: neither manual nor automatic WordPress update offers are
published until the publisher corrects the release asset.

The canonical plugin header or theme `style.css` must also declare an
`Update URI` for the configured GitHub repository. Host, owner and repository
comparison is case-insensitive and trailing slashes are ignored; credentials,
query strings, fragments, release URLs and other repository paths are rejected.

WordPress Core continues to own update scheduling, the Plugins and Updates
screens, downloading orchestration, filesystem credentials, extraction,
temporary backups, installation, restoration, activation state, and cleanup.

Private credentials may be a string or a zero-argument callable. Callables are
resolved separately for each top-level request, allowing rotation without
re-registering the updater. Authorization is attached only to
`api.github.com`; automatic redirects are disabled, every redirect is validated
through WordPress safe-HTTP policy and an exact GitHub asset-host allowlist, and
Authorization is permanently stripped when the chain leaves the API host.

For the exact verified archive, the package uses Core's `pre_unzip_file` report
to reject extraction requirements above 256 MiB. After Core extracts the ZIP,
it validates the canonical root, entry file, version and compatibility headers.
If the plugin or theme is installed under a safe noncanonical directory name,
it maps only Core's staged directory through `WP_Filesystem`; it never renames,
deletes or copies the live installed directory itself.

The staged header check remains a defense-in-depth guard after Core extraction.
It applies the same `2.1`/`2.1.0` comparison and reports a release-version
mismatch without attempting to repair the package.

Failures are retained only as bounded expiring site-transient state. Default
Plugins and Updates screen notices are capability-scoped, filterable through
`ran_wp_github_release_updater_notice`, sanitized after filtering, and quiet
for transient network failures. When `WP_DEBUG_LOG` is enabled, the package
emits only concise stable events without credentials, URLs, response bodies or
filesystem paths.

Consumers must register directly from their main plugin files. A copy loaded
after `plugins_loaded` is deferred until the next request and cannot replace the
runtime already selected for the current request.

## Installation

Unless this package is available through the consumer's configured Composer
registry, declare its GitHub VCS repository first. Private repository
authentication belongs in Composer's external auth configuration, not in the
consumer repository.

```sh
composer config repositories.ran-wp-github-release-updater vcs \
	https://github.com/RocketsAreNostalgic/ran-wp-github-release-updater.git
composer require ran/wp-github-release-updater:^1.0@beta
```

The package intentionally declares no production Composer autoload mapping.
Each consuming plugin or theme owns its repository declaration, exact lock and
production bundling of this dependency.
Require its bootstrap explicitly from the consuming plugin's main file:

```php
$createUpdater = require __DIR__
	. '/vendor/ran/wp-github-release-updater/bootstrap.php';

$updater = $createUpdater(
	pluginFile: __FILE__,
	repository: 'RocketsAreNostalgic/example-plugin',
	pluginSlug: 'example-plugin',
	channel: 'stable',
	accessToken: null,
	autoUpdatePolicy: 'site-controlled',
	cacheDuration: 21_600,
	failureCacheDuration: 900,
	nativeDiscovery: true,
);

$updater->register();
```

For a private repository, prefer a request-time resolver:

```php
accessToken: static fn (): ?string => getenv( 'RAN_GITHUB_TOKEN' ) ?: null,
```

The token is never placed in the package URL, cache, notices or diagnostics.

Registration belongs directly in the plugin's main file, not in a
`plugins_loaded` callback. `register()` is idempotent and `diagnostics()` is
passive: it does not make a remote request.

Set `nativeDiscovery: false` when a consumer must still participate in shared
runtime arbitration but must not create a native update target. The target
reports passive `inactive / native_discovery_disabled` diagnostics and
registers no discovery, plugin-information, automatic-update, upgrader,
completion, notice, refresh, or HTTP work. This is distinct from
`autoUpdatePolicy: 'disabled'`, which suppresses an offer but can still perform
release discovery.

Managed targets may additionally pass their stored GitHub numeric repository
identity as `providerRepositoryId`. Standalone native targets may omit it; when
present, cached offers and final acquisition are bound to that stable identity.

The supported integration surfaces are this bootstrap facade and the managed
preflight below. Classes under `src/Artifact` implement the trust engine and
are internal; consumers should not construct or introspect them directly.

## Optional target-registration signal

An optional integration can ask whether this package has already accepted a
registration for one exact installed WordPress target in the current request:

```php
if (
	function_exists( 'ran_wp_github_release_updater_v1_has_registered_target' )
	&& ran_wp_github_release_updater_v1_has_registered_target(
		'plugin',
		plugin_basename( __FILE__ )
	)
) {
	// Do not register a second RAN updater for this plugin target.
}
```

Use a plugin's `plugin_basename()` value for `plugin` targets and its WordPress
stylesheet slug for `theme` targets. The function returns `true` only after a
RAN updater facade has successfully submitted that exact target to this
request's broker. It exposes no updater configuration, repository, credential,
policy, or diagnostics; it does not detect third-party update mechanisms and
does not promise that the selected runtime will later become active.

## Managed-release preflight API

Managed consumers such as deployment controllers can use the WordPress-facing
preflight instead of constructing artifact descriptors or parsing ZIP files.
`check()` preserves the released bounded site-transient cache; pass `true` to
force fresh discovery and archive inspection. It returns a
`CandidateValidation` object with only safe fields: `state`, `code`,
`release_tag`, normalized `release_version`, `package_header_version`, and
exact release/ZIP/digest identity. `cacheDuration` remains configurable from
300 to 86,400 seconds and defaults to six hours. Remote failures return a
credential-free `WP_Error` with the original updater error code and any bounded
rate-limit cooldown.

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
omit `mainFile` (the preflight uses `style.css`). This release contract uses
the embedded WordPress `Version:` and `Update URI` headers. WordPress.org
`Stable tag`, SVN directories, readmes, changelogs, and `package.json` are not
considered.

### Prospective first-install verification

An authorised consumer can inspect a package that is not installed yet without
receiving credentials, internal descriptors, signed URLs or temporary paths.

The selected runtime advertises this strict custody contract as
`ReleaseCandidatePreflight::PROSPECTIVE_API_VERSION === 3`.

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
	: $preflight->inspectExact( $selected->releaseId(), $selected->tag(), 'main' );
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
request, parse it with
`ReleaseFingerprint::fromString()`, and pass it to `acquireExact()`. Acquisition
reconstructs and freshly downloads the exact release, rejects any identity
change, validates the ZIP in one bounded inventory pass, and performs the final
default-branch reachability check against the acquired commit. The deliberate
second download means display-time inspection is never reused as installation
custody.

The normal single-page, no-redirect request characterization is:

| Operation | Logical requests |
| --- | ---: |
| Native offer discovery and ZIP validation | 5 |
| Native fresh pre-install acquisition | 4 |
| Prospective candidate list | 2 |
| Prospective exact review | 5 |
| Prospective exact acquisition | 5 |

A full prospective list, review, and acquisition is therefore 12 logical
requests and two ZIP downloads. Each ZIP download may add one allowlisted
redirect. An incompatible native candidate adds four requests before the
selector can safely try the next release. This is deliberate: headers inside
the exact ZIP are the compatibility authority, and neither display-time bytes
nor an earlier candidate verdict are installation custody. No cross-request
pool, second cache, or retained ZIP is introduced.

The returned `ValidatedReleaseArtifact` retains cleanup ownership. Call
`discard()` to abandon it or call `handoffToCore()` exactly once immediately
before WordPress Core consumes the resulting `ClaimedArtifact`. The claim
retains the updater-verified digest and file identity:
`ClaimedArtifact::assertUnchanged()` returns that frozen snapshot only while the
private archive is unchanged, and `ClaimedArtifact::discard()` deletes only
that same file. `ClaimedArtifact::path()` remains available for the immediate
WordPress Core handoff. The updater does not install or adopt the package.

Themes use the same callback. Pass the absolute `style.css` path and the native
installed stylesheet identity:

```php
$updater = $createUpdater(
	pluginFile: get_theme_root() . '/locally-renamed-theme/style.css',
	repository: 'RocketsAreNostalgic/example-theme',
	pluginSlug: 'example-theme',
	autoUpdatePolicy: 'manual',
	targetType: 'theme',
	stylesheet: 'locally-renamed-theme',
);
$updater->register();
```

`autoUpdatePolicy` accepts `manual`, `automatic`, or `disabled`. Manual preserves
the site's native automatic-update choice, automatic enables Core auto updates,
and disabled records passive release status without offering an install.
The legacy `site-controlled`, `forced-on`, and `forced-off` values remain
accepted for existing plugin integrations.

Native discovery scans at most two 20-release pages and describes at most eight
compatible candidates. Release-list responses are capped at 256 KiB per page
and 512 KiB in total; branch comparison requests one item and accepts at most
64 KiB. Every request has a ten-second timeout and follows at most one validated
redirect. Authentication, transport and rate-limit failures retain their exact
diagnostic classification and cooldown. A failed or exhausted discovery keeps
the last verified cache record but returns the incoming WordPress host-filter
value; only a verified RAN offer or the explicit disabled policy replaces it.

`$updater->refresh()` clears only this target's package cache and Core's native
plugin or theme update transient. It performs no remote request; the next normal
WordPress update check repopulates both. The package does not expose a second
installer, scheduler, candidate store, or CLI.

## Release asset contract

This is the publishing contract for WordPress plugins and themes that consume
the library. Release Please in this library repository updates its version
sources, changelog, tag and GitHub source release only; it does not build or
upload a consumer's ZIP.

Publish exactly one uploaded `.zip` asset on the GitHub Release:

```text
example-plugin-1.2.3.zip
```

Non-ZIP assets are ignored; zero or multiple uploaded ZIPs fail closed. GitHub
must report the ZIP's `sha256:` digest, and the downloaded bytes must match its
reported size and digest.

The ZIP must contain exactly one safe top-level package directory. A plugin
must contain exactly one root-level PHP file with `Plugin Name`; a theme must
contain root `style.css` with `Theme Name`. That header must also contain the
release `Version`, canonical repository `Update URI`, `Requires PHP`, and
`Requires at least`. Prerelease tags retain the complete suffix, for example
`v1.2.3-beta.1` and `Version: 1.2.3-beta.1`.

The selected runtime exposes one optional rejection-only assurance checker
through
`ran_wp_github_release_updater_v1_assurance_registration`. Its canonical
registration, evidence, result semantics, and authority limits are documented
in the [release assurance extension contract](docs/release-assurance-extension.md).
The built-in release, digest, archive and package checks remain mandatory and
sufficient when no checker is present. This seam permits a future
immutable-release or GitHub Artifact Attestation add-on without putting that
policy or its dependencies in this package today.

WordPress Core performs extraction and installation; this library does not
implement a second installer.

## Development

```sh
composer install
composer check
```

The complete design and staged acceptance gates are in
`docs/plans/__completed/2026-07-24-wp-github-release-updater-v1-plan-revision-1.md`.

Licensed under GPL-2.0-or-later.
