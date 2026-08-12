# Install-session ownership gate

## Decision

The bounded install-session extraction gate is **NO-GO**. Retain
`NativePluginUpdater` as the single WordPress hook adapter and install-session
coordinator. Do not add an install-session type.

This is a reviewed cohesive exception, not a claim that the class is small.
The class remains a concentration outlier, but the proposed one-type extraction
cannot delete total production code, lifecycle branches, cleanup ownership or
state combinations without splitting one invariant across two owners.

Reopen this decision only if one of these facts changes:

- an eleventh pending-install field becomes necessary;
- another defect is caused by an illegal pending-state combination;
- claim, fence or shutdown cleanup becomes duplicated; or
- a second install lifecycle changes independently of the native WordPress
  lifecycle.

## Exact baseline

The reviewed source is the published `v2.0.0-beta.5` commit
`933eebd7cd00a9529477030e617bbdd893aab131`. The local documentation carrier is
separate from that release commit. The relevant
`src/WordPress/NativePluginUpdater.php` blob is
`ce566ce843456f238c4f4e3aac0b15070505117e` at the published commit, the
previously qualified source commit `3e539a8b26b0f4b31117ddadb92e80dc565f231a`
and this documentation carrier.

The frozen production shape is:

| Surface | Count |
| --- | ---: |
| Shipped backend PHP (`bootstrap.php`, `runtime.php`, `src/`) | 8,729 lines |
| Named runtime types | 36 |
| `NativePluginUpdater` | 2,467 lines |
| `NativePluginUpdater` named methods | 71 |
| Separate development fixture | 27 lines |

The release-candidate main run exercised the real WordPress 6.5 and 7.0.3
lifecycle lanes under PHP 8.2. The source gate also passed both disposable
Composer-installed lifecycle fixtures. This review adds no runtime source,
public API, hook, state, dependency or release change.

## Responsibility map

Every named method belongs to one of the following closed groups. The counts
sum to all 71 methods.

| Group | Count | Methods |
| --- | ---: | --- |
| Construction and registration | 4 | `__construct`, `fromTarget`, `register`, `normalizePolicy` |
| Update-surface adapters | 5 | `filterUpdate`, `filterPluginInformation`, `filterAutoUpdate`, `diagnostics`, `refreshCache` |
| Install hook adapters | 7 | `filterPreDownload`, `filterPreUnzipFile`, `filterPreInstall`, `filterSourceSelection`, `captureInstallPackageResult`, `observeCompletion`, `finalizePendingInstall` |
| Discovery and offer projection | 11 | `offer`, `acceptDescriptor`, `reusableCurrentValidation`, `validateCandidate`, `identityTarget`, `query`, `offerFromDescriptor`, `descriptorMatchesOffer`, `validatedOffer`, `identityHeaderFile`, `validatedCandidateValidation` |
| Cache and operation coordination | 17 | `cachedState`, `nativeStateFromClaim`, `persistNativeState`, `storeAvailable`, `storeCandidateRejected`, `storeCurrent`, `storeUnavailable`, `storeCooldown`, `storeRemoteError`, `storeDiagnostic`, `diagnosticRepeats`, `coordinationTargetKey`, `renewDiscoveryClaim`, `conditionalFromState`, `conditionalToArray`, `mergedConditional`, `cacheKey` |
| Install custody helpers | 12 | `claimCoreReinstallHandoff`, `stagedMetadataMatches`, `sourceError`, `versionsEquivalent`, `expectedUpdateUri`, `startPendingInstall`, `renewPendingInstall`, `schedulePendingFinalization`, `clearPendingInstall`, `normalizedPath`, `withTrailingSlash`, `downloadError` |
| Notice projection | 5 | `renderAdminNotice`, `registerConfigurationNotice`, `defaultNotice`, `renderFilteredNotice`, `noticeSurfaceAllows` |
| Shared boundary helpers | 10 | `debugLog`, `errorCode`, `configurationError`, `now`, `isForceCheck`, `header`, `matchesHookExtra`, `readPackageData`, `packageHeaders`, `readStagedPackageData` |

The broader runtime already has focused owners:

- the bootstrap broker owns physical candidate registration, copy selection,
  target origins and late/equal-copy diagnostics;
- `runtime.php` rejects target collisions and creates one shared hook-free
  artifact client plus one native hook adapter per target;
- `ReleaseCandidateSelector` owns bounded release selection and candidate ZIP
  validation, but no caller cache;
- `ReleaseOperationCoordinator` and `ReleaseOperationClaim` own the
  database-authoritative compare-and-swap fence and result slots;
- `GitHubReleaseArtifactService` owns release, commit, asset, digest and
  download verification behind `ReleaseArtifactClient`;
- `VerifiedArtifact` owns verified temporary bytes until a single claim;
- `ClaimedArtifact` owns unchanged-file custody, idempotent discard and
  destructor cleanup; and
- `ValidatedReleaseArtifact` owns the prospective preflight-to-Core handoff.

Within the install lifecycle, `NativePluginUpdater` owns the WordPress
correlation that cannot be assigned to those hook-free owners: native hook
identity, cached offer, live operation claim, staged-source checks, completion
signals and installed header readback. Its construction, discovery/cache,
notice and diagnostics responsibilities remain separately visible in the
method map above.

## Mutable lifetimes

Mutable state has three distinct lifetimes:

| Lifetime | Fields | Owner and reset |
| --- | --- | --- |
| Request adapter | `registered`, `noticeRendered` | `NativePluginUpdater`; idempotent hook registration and one rendered notice per request |
| Discovery fence | `activeDiscoveryClaim` | Acquired in `offer`, renewed during remote work and released by its `finally` block or successful native-state publication |
| Install session | the ten fields below | Begun in `filterPreDownload`; one `clearPendingInstall` removes dynamic hooks, resets every field, discards the exact artifact claim and releases the operation fence |

The constructor dependencies, selectors, operation coordinator, clock and
header-derived package data are request-long collaborators or immutable target
configuration rather than pending lifecycle state.

## Ten-field install session

The ten declarations occur across source lines 51–72 and have 59 exact
references. `activeDiscoveryClaim`, which is interleaved in that range, remains
a separate discovery-lifetime field.

| Field | References | Meaning and legal values |
| --- | ---: | --- |
| `pendingArchive` | 12 | Exact local archive path; `null` before admission and after cleanup |
| `pendingClaim` | 6 | `ClaimedArtifact` whose unchanged bytes and deletion are under custody |
| `pendingOperationClaim` | 12 | Database-backed native-install fence; it may be consumed by authoritative state publication before final reset |
| `pendingOffer` | 6 | Exact native release offer; present only for the native release path |
| `pendingExpectedVersion` | 3 | Expected header version; present only for a Core reinstall handoff |
| `pendingCoreHandoff` | 4 | Distinguishes the Core-owned handoff, which must never mutate native release state |
| `pendingInstallResultCaptured` | 4 | Distinguishes a captured `null` result from a missing per-target result |
| `pendingInstallResult` | 6 | Core's authoritative per-target install result |
| `pendingCompletionObserved` | 3 | Correlated `upgrader_process_complete` signal; never success by itself |
| `pendingShutdownScheduled` | 3 | Whether the dynamic WordPress shutdown finalizer is registered |

The result flag and result value are deliberately separate. Core can report a
captured `null` value, so replacing both with one nullable field would re-open
the missing-result false-success defect.

## Legal transitions

| State | Required shape | Entry | Permitted next states |
| --- | --- | --- | --- |
| Idle | all ten fields at defaults | construction or `clearPendingInstall` | fenced |
| Pre-admission fenced | operation claim acquired; shutdown finalization scheduled; no archive yet | successful `startPendingInstall` followed immediately by `schedulePendingFinalization` | native admitted, Core handoff admitted, immediate reset, or scheduled cleanup after a later admission failure publishes its diagnostic |
| Scheduled cleanup | no archive; shutdown finalization scheduled; operation claim may already be published/released and cleared | artifact acquisition, assurance or claiming failure whose diagnostic consumes the fence | shutdown reset |
| Native admitted | archive + artifact claim + operation claim + offer; handoff false; expected version absent | fresh exact descriptor, download, assurance and claim | extraction/staging, result/completion signals, failure, shutdown finalization |
| Core handoff admitted | archive + artifact claim + operation claim + expected version; handoff true; offer absent | exact typed one-shot Core handoff | extraction/staging, result/completion signals, shutdown cleanup without native-state mutation |
| Progressing | archive retained; fence renewed at each available Core hook | pre-unzip, pre-install and source-selection callbacks | result/completion signals, failure or shutdown finalization |
| Signalled | result captured and completion observed in either order | per-target result filter and process-complete action | shutdown finalization |
| Final native success | native mode, non-failing captured result, completion observed and installed header equals exact offer | shutdown finalizer after Core rollback/backup handlers | authoritative current-state publication, then idle |
| Final native failure | failing/missing result, missing completion or installed-header mismatch | shutdown finalizer | bounded diagnostic, then idle |
| Final Core handoff | handoff mode regardless of native result projection | shutdown finalizer | cleanup only, then idle |

`filterPreDownload` schedules the shutdown fallback immediately after acquiring
the fence. That intentionally retains cleanup ownership when acquisition,
assurance or claiming stops before an archive is fully admitted. It is not a
half-created second lifecycle.

## Custody and cleanup

There is already one cleanup owner:

- `clearPendingInstall` has 13 call sites plus its declaration;
- `renewPendingInstall` has nine call sites plus its declaration;
- `startPendingInstall` and `schedulePendingFinalization` each have two call
  sites plus their declarations;
- dynamic `pre_unzip_file` and shutdown hooks are removed idempotently;
- all ten fields are reset together;
- `ClaimedArtifact::discard()` owns exact file deletion; and
- `ReleaseOperationCoordinator::release()` owns the fence release.

Immediate hook errors use `clearPendingInstall` or `downloadError`, which also
clears. Failures after the fence is acquired but before an admitted archive can
remain fenced until the already-scheduled shutdown fallback. Finalization uses
`try/finally`, so every success, refusal, missing signal and final-readback
failure reaches the same cleanup.

Two cross-boundary facts are intentional:

1. `persistNativeState` may publish through and consume
   `pendingOperationClaim`; this makes successful state publication and fence
   release one database-authoritative transition.
2. `storeCurrent` refuses to replace the offered state while
   `pendingArchive` is active because Core refreshes metadata before shutdown
   finalization.

Moving either rule into another owner would pull cache, discovery and
persistence authority into the install session. Leaving them in the adapter
would make both objects mutate the same lifecycle.

## Extraction alternatives

### Property bag

A shallow object could move the ten declarations and expose archive, offer,
version, result, completion and handoff accessors. `NativePluginUpdater` would
still own all transitions, dynamic hooks, branches and cleanup decisions. This
is the prohibited property-bag outcome.

### Hook-owning state machine

An owner that also absorbs shutdown scheduling and the seven public install
callbacks would no longer be hook-free or leave `NativePluginUpdater` as the
sole WordPress adapter. Keeping public wrappers would add forwarding without
deleting the Core-specific branches.

### Hook-free state machine

A hook-free owner can acquire, renew, release and discard, but the adapter must
still register/remove shutdown and pre-unzip hooks, validate staged WordPress
filesystem state, read installed headers, write diagnostics and publish native
state. Completing the transition would require callbacks in both directions or
an array of internal state accessors. This splits rather than simplifies the
invariant.

### Move persistence into the session

This would absorb `persistNativeState`, cache keys, current/available state,
diagnostics and discovery-claim interaction. It exceeds the install-session
boundary and duplicates the existing operation coordinator instead of
simplifying it.

## Deletion result

The broadest syntactically identifiable relocation is 152 physical lines, but
it is not a valid hook-free extraction:

- pending declarations and their spacing: 21 lines;
- Core handoff claim helper: 47 lines;
- install-fence start: 19 lines;
- install-fence renewal: 18 lines;
- shutdown scheduling: 13 lines; and
- unified reset/cleanup: 34 lines.

The handoff helper calls a WordPress filter, shutdown scheduling calls a
WordPress action, and cleanup removes both WordPress hooks. Those pieces must
stay in the sole adapter. Even the smaller hook-free subset must reproduce its
fields, acquire/renew logic and reset/custody semantics in a new class, add
constructor integration, and add adapter handshakes. The best credible result
is therefore:

| Measure | Result |
| --- | ---: |
| Broad maximum `NativePluginUpdater` reduction by equivalent relocation | 152 lines |
| Total backend deletion | 0 lines or less |
| Lifecycle branches deleted | 0 |
| State/custody owners deleted | 0 |
| New runtime types | 1 |

That fails the gate. No exact positive deletion target is declared and no
implementation packet is eligible.

## Verification

The review reproduced the 8,729-line/36-type baseline, the 2,467-line/71-method
class, all ten exact-reference counts and the source blob identity. Focused
`NativePluginUpdater` tests pass 90 tests and 852 assertions. The complete
Composer gate passes 288 tests and 3,473 assertions plus 22 publisher tests,
strict Composer validation, performance characterization, PHPCS and PHPStan.
`git diff --check` is clean.

The published candidate's same-repository CI remains the authoritative real
WordPress proof: PHP 8.2 and 8.4 quality plus WordPress 6.5 and 7.0.3 lifecycle
lanes all passed before the exact tag and immutable release were created.
