# RAN WordPress GitHub Release Updater

A Composer library for offering WordPress plugin and theme updates from exact
GitHub Release ZIP assets through WordPress Core's native update lifecycle.

The updater verifies the repository, release, tag commit, uploaded asset,
GitHub-reported SHA-256 digest, downloaded bytes, archive layout, and WordPress
package headers before it offers an update. If any part of that chain does not
match, the release is not offered.

WordPress Core still owns update scheduling, the Plugins and Updates screens,
filesystem credentials, extraction, temporary backups, installation,
restoration, activation state, and cleanup. This package is a library, not an
activatable plugin, and it does not add another installer, scheduler, candidate
store, or CLI.

Public and private repositories, plugins and themes, and multiple independent
copies of the library in one WordPress request are supported.

## Requirements and support

- PHP 8.2 or newer, with the Hash, JSON, and Zip extensions
- WordPress 6.5 or newer
- Composer 2 for installation and development

The project is in Beta. Beta releases are intended for controlled integration
testing and do not have a backport policy.

Use the [issue tracker](https://github.com/RocketsAreNostalgic/ran-wp-github-release-updater/issues)
for ordinary defects. Report suspected vulnerabilities privately as described
in [SECURITY.md](SECURITY.md); do not put tokens, signed URLs, credentials, raw
HTTP responses, or temporary paths in a public issue.

## Install the library

Unless the package is available through the consumer's configured Composer
registry, declare its GitHub repository first. Keep private-repository
authentication in Composer's external auth configuration, not in the consumer
repository.

```sh
composer config repositories.ran-wp-github-release-updater vcs \
	https://github.com/RocketsAreNostalgic/ran-wp-github-release-updater.git
composer require ran/wp-github-release-updater:^2.0@beta
```

The package deliberately declares no production Composer autoload mapping.
Each consumer owns its repository declaration, lock file, and production
bundling of this dependency. The released plugin or theme ZIP must contain the
installed library; production sites do not run Composer to obtain it.

## Register a plugin

Require `bootstrap.php` from the plugin's main file, create the updater, and
register the target:

```php
$createUpdater = require __DIR__
	. '/vendor/ran/wp-github-release-updater/bootstrap.php';

$updater = $createUpdater(
	pluginFile: __FILE__,
	repository: 'RocketsAreNostalgic/example-plugin',
	providerRepositoryId: '123456789',
	pluginSlug: 'example-plugin',
	channel: 'stable',
	accessToken: null,
	autoUpdatePolicy: 'manual',
	cacheDuration: 21_600,
	failureCacheDuration: 900,
);

$updater->register();
```

`register()` is idempotent. `diagnostics()` returns passive, bounded state and
makes no remote request. `refresh()` clears only this target's package cache and
the matching Core update transient; the next normal WordPress update check
performs discovery.

### Registration timing

Load the bootstrap from the plugin's main file. Register there or from a
`plugins_loaded` callback below `PHP_INT_MAX - 1`. At that priority, the
request-local broker selects the highest compatible V1 runtime regardless of
plugin load order, using a deterministic path tie-break for equivalent
versions, and loads only that runtime.

A target registered at or after the selector reports
`inactive / late_registration` for that request. A package copy loaded after
`plugins_loaded` is deferred until the next request and cannot replace the
runtime already selected.

At declaration time, only the returned bootstrap facade is available. Runtime
classes under `RAN\WPGitHubReleaseUpdater\V1` become available after the selected
runtime loads at `plugins_loaded`; use them from a callback at `PHP_INT_MAX`.

### Private repositories

Prefer a request-time token resolver so credentials can rotate without
re-registering the updater:

```php
accessToken: static fn (): ?string => getenv( 'RAN_GITHUB_TOKEN' ) ?: null,
```

The token is attached only to `api.github.com`. It is not placed in the package
URL, cache, notices, diagnostics, or logs. Automatic redirects are disabled;
each redirect is checked with WordPress safe HTTP and an exact GitHub asset-host
allowlist, and authorization is removed when the request leaves the API host.

## Register a theme

A theme cannot register itself: an active theme's `functions.php` loads too
late for the broker, and an inactive theme does not execute. Register every
managed theme from an ordinary active plugin or another early application entry
point. Pass the absolute `style.css` path and the installed stylesheet identity:

```php
$updater = $createUpdater(
	pluginFile: get_theme_root() . '/locally-renamed-theme/style.css',
	repository: 'RocketsAreNostalgic/example-theme',
	providerRepositoryId: '987654321',
	pluginSlug: 'example-theme',
	autoUpdatePolicy: 'manual',
	targetType: 'theme',
	stylesheet: 'locally-renamed-theme',
);

$updater->register();
```

Do not put this registration in the theme's `functions.php` or an
`after_setup_theme` callback. Those paths cannot register in time or cover an
inactive theme.

## Configure a target

| Parameter | Required | Accepted value or default |
| --- | --- | --- |
| `pluginFile` | Yes | Absolute plugin main-file path, or a theme's absolute `style.css` path |
| `repository` | Yes | GitHub `owner/name` |
| `providerRepositoryId` | Yes | GitHub's stable numeric repository `id` |
| `pluginSlug` | No | Canonical archive root; derived from `repository` when omitted |
| `channel` | No | `stable` (default) or `prerelease` |
| `accessToken` | No | String, zero-argument callable, or `null` |
| `autoUpdatePolicy` | No | `manual`, `automatic`, or `disabled`; defaults to manual behavior |
| `cacheDuration` | No | 300–86,400 seconds; default 21,600 |
| `failureCacheDuration` | No | 60–3,600 seconds, no longer than `cacheDuration`; default 900 |
| `nativeDiscovery` | No | `true` (default), or `false` to join runtime arbitration without creating an update target |
| `targetType` | For themes | `theme`; plugins are the default |
| `stylesheet` | For themes | Installed WordPress stylesheet identity |

Read `providerRepositoryId` from GitHub's repository API when configuring the
integration. Discovery, cached offers, and final acquisition are bound to that
numeric identity, even if the repository owner or name is later transferred or
recreated.

The automatic-update policies are deliberately distinct:

- `manual` publishes an eligible update to WordPress and preserves the site's
  own automatic-update choice only when the release also passes the automatic
  profile.
- `automatic` permits Core to install the release automatically only when the
  repository identity is stable and GitHub reports the published Release as
  immutable. The updater checks the same profile again against a fresh release
  description and ZIP before installation.
- `disabled` records passive release status without offering an installation.

The legacy `site-controlled`, `forced-on`, and `forced-off` values remain
accepted for existing integrations.

Set `nativeDiscovery: false` when a consumer must join shared runtime
arbitration without creating a native update target. It reports
`inactive / native_discovery_disabled` and performs no discovery,
plugin-information, automatic-update, upgrader, completion, notice, refresh,
or HTTP work. This is different from `autoUpdatePolicy: 'disabled'`, which can
still discover releases.

## Publish a compatible release

This is the publishing contract for every WordPress plugin or theme that uses
the updater.

### GitHub Release

Publish a non-draft GitHub Release in the configured repository. A `stable`
target rejects a release marked as a prerelease and any tag containing a SemVer
prerelease suffix. A `prerelease` target may select either kind.

The tag must contain a full `MAJOR.MINOR.PATCH` version, with an optional
canonical SemVer prerelease suffix. A single leading `v` is accepted:

```text
v1.2.3
v1.2.3-beta.1
```

Short tags, extra version components, and malformed prerelease identifiers are
rejected.

### Uploaded ZIP

Upload exactly one `.zip` asset to the Release:

```text
example-plugin-1.2.3.zip
```

Non-ZIP assets are ignored, but zero or multiple uploaded ZIPs fail closed. The
ZIP must be completely uploaded, no larger than 50 MiB, and accompanied by the
`sha256:` digest reported by GitHub. The downloaded size and SHA-256 digest must
match that release metadata.

GitHub's generated **Source code** archives are not uploaded release assets and
do not satisfy this contract.

### Archive layout

The ZIP must contain one safe top-level package directory matching
`pluginSlug`. It may contain at most 10,000 entries and about 121.9 MiB of total
expanded content. Unsafe, duplicate, ambiguous, or multi-root paths are
rejected.

For a plugin, the root must contain the registered main PHP file with a
`Plugin Name` header. A prospective first-install inspection, which does not
already know the main filename, requires exactly one root-level PHP file with a
`Plugin Name` header. For a theme, the root must contain `style.css` with a
`Theme Name` header.

The canonical plugin main file or theme `style.css` must also contain:

```text
Version: 1.2.3
Update URI: https://github.com/RocketsAreNostalgic/example-plugin
Requires PHP: 8.2
Requires at least: 6.5
```

The `Version` header must match the normalized release tag. A stable header may
use WordPress's two-part shorthand, so `2.1` matches `v2.1.0`. A prerelease
header must contain the complete canonical value, such as `2.1.0-beta.2`. Do
not include the tag's leading `v` in the package header.

`Update URI` must identify the configured GitHub repository. Host, owner, and
repository comparison is case-insensitive, and a trailing slash is ignored.
Credentials, query strings, fragments, release URLs, and other repository paths
are rejected.

`Requires PHP` and `Requires at least` must be valid version values and must be
satisfied by the target site. The updater does not use WordPress.org
`Stable tag`, SVN directories, readmes, changelogs, or `package.json` as release
identity.

If a required header is missing, unreadable, invalid, or inconsistent, the
release is not offered for either a manual or automatic update.

## How an update is verified

1. The updater describes an eligible release and binds it to the configured
   numeric repository identity, exact release ID, tag, resolved tag commit, and
   uploaded ZIP.
2. It downloads that asset to private temporary storage and verifies its file
   identity, size, and SHA-256 digest.
3. It inspects the canonical WordPress header directly inside the verified ZIP,
   without extracting or installing the package, and checks package identity,
   version, `Update URI`, and compatibility.
4. Only then does it publish an update offer through WordPress's native update
   filters.
5. Immediately before installation, it freshly describes and downloads the
   exact release again. A changed repository, release, commit, asset, digest, or
   package identity fails closed.
6. WordPress Core extracts and installs the package. The updater validates the
   staged root and headers as a defense-in-depth check. If the installed package
   uses a safe noncanonical directory name, it maps only Core's staged
   directory; it does not rename, delete, or copy the live installed directory.

The [security policy](SECURITY.md) describes the trust boundary and credential
handling in more detail.

## Diagnostics and notices

The updater keeps bounded, expiring state in one non-autoloaded main-site option
row per coordinated target. Failed, exhausted, or rate-limited discovery clears
the readiness-authorizing RAN offer while retaining bounded diagnostics and
conditional request state. When no safe offer is available, the updater returns
the incoming WordPress host-filter value.

Default Plugins and Updates screen notices are capability-scoped, filterable
through `ran_wp_github_release_updater_notice`, sanitized after filtering, and
quiet for transient network failures. When `WP_DEBUG_LOG` is enabled, the
package emits concise stable events without credentials, URLs, response bodies,
archive contents, or filesystem paths.

## Advanced integration

The bootstrap facade is the normal plugin and theme integration surface.
Deployment controllers can instead use the public WordPress-facing preflight
APIs for installed-package checks, first-install inspection and acquisition,
artifact custody, and coordination. These operations use fixed internal request
budgets. The runtime classes must be used from a callback at `PHP_INT_MAX`;
classes under `src/Artifact` are internal and should not be constructed or
introspected by consumers.

For an installed package, create the preflight only after the selected runtime
has loaded:

```php
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseCandidatePreflight;

add_action( 'plugins_loaded', static function (): void {
	if ( ! class_exists( ReleaseCandidatePreflight::class, false ) ) {
		return;
	}

	$preflight = ReleaseCandidatePreflight::fromTarget( array(
		'repository' => 'RocketsAreNostalgic/example-plugin',
		'providerRepositoryId' => '123456789',
		'pluginSlug' => 'example-plugin',
		'mainFile' => 'example-plugin.php',
		'channel' => 'stable',
		'accessToken' => null,
		'packageType' => 'plugin',
	) );

	$validation = is_wp_error( $preflight ) ? $preflight : $preflight->check();
}, PHP_INT_MAX );
```

### Prospective first-install custody

For a prospective first installation, create the preflight with the same target
configuration on both the review and installation requests:

```php
use RAN\WPGitHubReleaseUpdater\V1\WordPress\ReleaseFingerprint;

$preflight = ReleaseCandidatePreflight::fromProspectiveTarget( array(
	'repository' => 'RocketsAreNostalgic/example-plugin',
	'providerRepositoryId' => '123456789',
	'channel' => 'stable',
	'accessToken' => null,
	'packageType' => 'plugin',
) );
```

On the review request, list the bounded candidates and inspect the selected
release ZIP. Every call can return `WP_Error` and must be checked:

```php
$candidates = $preflight->listCandidates();
if ( is_wp_error( $candidates ) ) {
	$inspection = $candidates;
} else {
	$selected = $candidates[0] ?? null;
	$inspection = null === $selected
		? new WP_Error( 'no_candidate', 'No release candidate was selected.' )
		: $preflight->inspectExact( $selected->releaseId(), $selected->tag() );
}

if ( ! is_wp_error( $inspection ) ) {
	$approvedRelease = array(
		'release_id' => $inspection->releaseId(),
		'tag' => $inspection->tag(),
		'fingerprint' => $inspection->fingerprint()->value(),
	);
	// Persist these bounded values with the authorised install request.
}
```

On the separately authorised installation request, parse the stored fingerprint
and freshly acquire that exact release:

```php
$fingerprint = ReleaseFingerprint::fromString( $approvedRelease['fingerprint'] );
$artifact = is_wp_error( $fingerprint )
	? $fingerprint
	: $preflight->acquireExact(
		$approvedRelease['release_id'],
		$approvedRelease['tag'],
		$fingerprint
	);

if ( ! is_wp_error( $artifact ) ) {
	$claim = $artifact->handoffToCore();
	if ( is_wp_error( $claim ) ) {
		$artifact->discard();
	} else {
		// Pass $claim->path() immediately to the authorised WordPress install flow.
	}
}
```

Inspection downloads, validates, and discards its ZIP. Acquisition downloads
again and rejects any change to the release, commit, asset, digest, or package
identity. A retained artifact must be `discard()`ed or handed to Core exactly
once. After a successful handoff, the claim owns cleanup and must itself be
discarded if Core does not consume it.

The [release assurance extension](docs/release-assurance-extension.md) defines
the single optional, request-local, rejection-only assurance checker. It can add
a rejection after the built-in checks, but it cannot waive a failure or install
a package.

### Detect an accepted target registration

An integration can ask whether a RAN updater facade has already submitted one
exact installed target to the current request's broker:

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

Use `plugin_basename()` for a plugin or the WordPress stylesheet slug for a
theme. The function returns `true` only after the facade has submitted that
exact target. It exposes no configuration or credentials, does not detect
third-party update mechanisms, and does not promise that the selected runtime
will later become active.

## Development and releases

The full development gate requires PHP 8.2, Composer, and Node.js 24. Node runs
only the repository publisher outcome fixtures; it is not a production runtime
or Composer dependency.

```sh
composer install
composer check
```

Release Please prepares this library's version sources, changelog, and release
notes. Release PRs must merge normally with two parents; squash and rebase
merges are not publishable. Repository publication also requires immutable
releases and the Actions variable
`RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID` set to this
repository's numeric GitHub ID. Exact post-create readback remains the authority.

After the exact candidate passes the repository's quality proof, the publisher
creates and reads back the tag and immutable GitHub source release. This
repository does not build or upload a consuming plugin's or theme's ZIP.

The public source lineage begins at `1.6.0-beta.1`; earlier prerelease
identities are retired. Released changes are recorded in [CHANGELOG.md](CHANGELOG.md).

The project is licensed under [GPL-2.0-or-later](LICENSE).
