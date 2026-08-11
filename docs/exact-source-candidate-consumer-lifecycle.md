# Exact source candidate and consumer lifecycle proof

Date: 11 August 2026

## Scope and claim

This proof records local qualification evidence for source commit
`3e539a8b26b0f4b31117ddadb92e80dc565f231a` as the exact updater source and
runtime candidate produced by the publisher-correction work. It does not
certify a new Release Please merge, Git tag or GitHub Release.

The commit is a one-parent ordinary source commit on
`0aa87978cbf77193f731da44bd893d25deea92da`. The existing immutable
`v2.0.0-beta.4` tag points to
`0688b189c24bb5458cf27a417a743cfb011f499a`, not to this source candidate.
The next publishable candidate must therefore be a future exact Release Please
merge with its own successful same-repository `main` CI result. This proof
cannot be reused as authority to retarget or recreate `v2.0.0-beta.4`.

No tag, release, asset, label, repository setting, live WordPress site or other
remote state was changed.

## Exact source identity

Authenticated read-only repository evidence returned repository ID
`1319704666`, default branch `main` and no open or merged Release Please pull
request for this source candidate. After a fresh fetch, both the local branch
and its remote-tracking ref resolved to the full candidate commit. Remote
`main` remained `0aa87978cbf77193f731da44bd893d25deea92da`.

The publisher's production identity reader accepted the exact Git and
Composer-resolved copies and returned:

- package: `ran/wp-github-release-updater`;
- type: unversioned Composer `library`;
- embedded version: `2.0.0-beta.4`;
- derived historical tag name: `v2.0.0-beta.4`; and
- candidate SHA: `3e539a8b26b0f4b31117ddadb92e80dc565f231a`.

The manifest value, broker package version, HTTP user agent and both runtime
assertions agree exactly at `2.0.0-beta.4`. Deliberately changing only the
manifest value was refused as `version_source_drift`. The runtime files
`bootstrap.php`, `runtime.php` and all `src/` files are byte-identical to the
historical beta.4 tag; both commits have exact `src/` tree
`bd1563d2ba08d7a4bdaca8b93ce6658fe9752b49`. The source correction changes
release tooling, Composer/export metadata, workflow/tests and public
documentation, not the selected runtime.

## Composer consumer and archive evidence

A disposable Composer project disabled Packagist, declared only a local VCS
repository and required:

```text
ran/wp-github-release-updater:
dev-bnjmnrsh/ran-booster-v3-ecosystem-integrity#3e539a8b26b0f4b31117ddadb92e80dc565f231a
```

Composer 2.9.2 installed from source with plugins and scripts disabled. Its
lock `source.reference`, the installed package Git `HEAD`, the local bare VCS
branch and the expected candidate all resolved to the same full commit. The
installed checkout was clean and its tracked tree had no byte delta from the
candidate Git tree. The solver correctly represented the package as a dev
branch; it did not invent a Composer `version` or claim the historical tag.

Two clean `composer archive` builds were byte-identical:

- SHA-256:
  `b908fc2e8e84916f781159c4d297c782cb7c43e9f7f6968fd8eb239ed34e7c53`;
- size: 89,524 bytes;
- exported files: 43;
- exported directories: four; and
- runtime declarations: 36.

Every exported file matched the exact `git archive` member and the
same path in the Composer-installed source checkout. The export contains
package metadata and documentation plus `composer.json`, `bootstrap.php`,
`runtime.php` and the 36 `src/` files. It excludes tests, fixtures, workflows,
publisher scripts, lockfile, development configuration and vendor files. The
library intentionally has no WordPress-plugin release ZIP asset.

## Broker and WordPress lifecycle outcomes

The normal suite retained older-first and newer-first mixed-copy selection.
An additional disposable proof loaded two physical, clean copies of the exact
Composer source in both orders. Both orders selected the lexically first
physical runtime, reported one equal-version duplicate and preserved both
target registrations. Loading the second physical copy after selection was
fixed produced `late_registration` without reselection or a changed duplicate
count.

The Composer-installed package's own lifecycle runner then operated against
two newly installed, isolated WordPress sites under PHP 8.2.29:

- WordPress 6.5, the supported floor; and
- WordPress 7.0.3, the workflow lane recorded on 11 August 2026, including
  Core's active-plugin fatal-error rollback path.

Both lanes passed:

- early plugin-owned active and inactive theme registration;
- direct active-theme late-registration refusal and inactive-theme
  non-execution;
- a renamed-directory plugin update with exact installed version and bytes;
- an automatic active-plugin update with activation preserved;
- active and inactive theme updates with active-theme selection preserved;
- active-plugin fatal rollback where supported;
- an injected early theme copy failure with exact pre-update tree restoration;
- fresh installed-header, marker, activation and directory-digest readback;
- offer retention until WordPress completed rollback;
- temporary backup and maintenance-file absence; and
- dynamic option, transient and fixture cleanup.

The lifecycle runner refused a non-owned root without its exact disposable
marker before invoking WP-CLI. Exact-source controls also detected a dirty
checkout and a wrong expected commit before use.

The owning local proof process reported removing both complete temporary
WordPress roots, the Composer consumer, the two physical-copy fixtures and its
temporary PHP symlink. It dropped only the two uniquely named disposable
databases, then returned an exact zero-row schema readback for those names and
negative filesystem checks for every owned root. The pre-existing Local
WordPress installation and database were never used as a lifecycle target.

## Verification and budget

`composer check` passed:

- strict Composer validation;
- 288 PHPUnit tests and 3,473 assertions;
- 22 tests of the production publisher against disposable Git and the stateful
  fake transport, including successful creation, lost acknowledgement recovery
  and immutable-disabled zero-write refusal;
- performance characterization;
- PHP_CodeSniffer; and
- PHPStan.

Archive member/byte comparisons, PHP 8.2 extension checks, exact Git/Composer
identity checks and repository diff checks also passed.

The proof adds no runtime PHP, runtime type, dependency, autoload entry,
persistent field or state. The candidate remains 8,729 backend PHP lines, 36
named runtime declarations and the separately reported 27-line tracked dummy
plugin development fixture, which is excluded from the Composer archive.

## Residual gate

Publication remains separate. It must first land the correction through the
normal checked pull-request path, allow Release Please to prepare a genuinely
advanced version commit, merge exactly that release PR, and bind its successful
same-repository `main` CI SHA to the new tag and immutable zero-asset
prerelease. Tagged Composer resolution and authenticated tag/release readback
belong to that later authority.
