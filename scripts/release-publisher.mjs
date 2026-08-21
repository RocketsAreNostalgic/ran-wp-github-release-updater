#!/usr/bin/env node

import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

import {
  CANONICAL_VERSION,
  PublisherRefusal,
  canonicalManifest,
  refuse,
  verifyReleaseContentDelta,
} from "./release-publisher-content.mjs";

export { PublisherRefusal, verifyReleaseContentDelta };

const FULL_SHA = /^[a-f0-9]{40}$/;
const VERSION = CANONICAL_VERSION;
const RELEASE_BRANCH =
  "release-please--branches--main--components--ran/wp-github-release-updater";
const PENDING_LABEL = "autorelease: pending";
const TAGGED_LABEL = "autorelease: tagged";
const BOT_LOGIN = "github-actions[bot]";
const API_VERSION = "2022-11-28";
const IMMUTABLE_RELEASES_API_VERSION = "2026-03-10";
const RELEASE_PATHS = [
  ".release-please-manifest.json",
  "CHANGELOG.md",
  "bootstrap.php",
  "src/Artifact/GitHubReleaseArtifactService.php",
  "tests/RuntimeTest.php",
];

function exactMatch(content, expression, expectedCount, source) {
  const matches = [...content.matchAll(expression)].map((match) => match[1]);
  if (matches.length !== expectedCount) {
    refuse(
      "version_source_invalid",
      `${source} has ${matches.length} version markers; expected ${expectedCount}`,
    );
  }
  return matches;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

export function candidateIdentityFromContents(contents, candidateSha) {
  if (!FULL_SHA.test(candidateSha)) {
    refuse(
      "candidate_invalid",
      "candidate SHA must be 40 lowercase hexadecimal characters",
    );
  }

  let manifest;
  let composer;
  try {
    manifest = JSON.parse(contents.manifest);
    composer = JSON.parse(contents.composer);
  } catch {
    refuse(
      "version_source_invalid",
      "manifest and Composer metadata must be valid JSON",
    );
  }

  const version = manifest?.["."];
  if (typeof version !== "string" || !VERSION.test(version)) {
    refuse(
      "version_source_invalid",
      "manifest must declare one prerelease version",
    );
  }
  if (
    composer?.name !== "ran/wp-github-release-updater" ||
    composer?.type !== "library" ||
    Object.hasOwn(composer, "version")
  ) {
    refuse(
      "composer_identity_invalid",
      "Composer identity must remain the unversioned ran/wp-github-release-updater library",
    );
  }

  const bootstrap = exactMatch(
    contents.bootstrap,
    /'package_version'\s*=>\s*'([^']+)'\s*,\s*\/\/ x-release-please-version/g,
    1,
    "bootstrap.php",
  )[0];
  const userAgent = exactMatch(
    contents.artifactService,
    /'User-Agent'\s*=>\s*'ran-wp-github-release-updater\/([^']+)'\s*,\s*\/\/ x-release-please-version/g,
    1,
    "GitHubReleaseArtifactService.php",
  )[0];
  const runtimeAssertions = exactMatch(
    contents.runtimeTest,
    /assertSame\(\s*'([^']+)'\s*,\s*\$diagnostics\['selected_version'\]\s*\)\s*;\s*\/\/ x-release-please-version/g,
    2,
    "RuntimeTest.php",
  );
  const mirrors = [bootstrap, userAgent, ...runtimeAssertions];
  if (!mirrors.every((value) => value === version)) {
    refuse(
      "version_source_drift",
      "manifest, broker, user agent and runtime assertions disagree",
    );
  }

  const firstHeading = contents.changelog.match(/^## \[([^\]]+)\]/m)?.[1];
  if (firstHeading !== version) {
    refuse(
      "release_notes_missing",
      "candidate version must be the top CHANGELOG section",
    );
  }
  const heading = new RegExp(`^## \\[${escapeRegExp(version)}\\]`, "m");
  const start = contents.changelog.search(heading);
  if (start < 0) {
    refuse(
      "release_notes_missing",
      "CHANGELOG has no section for the candidate version",
    );
  }
  const remaining = contents.changelog.slice(start);
  const next = remaining.slice(1).search(/^## \[/m);
  const notes = (next < 0 ? remaining : remaining.slice(0, next + 1)).trim();
  if (notes.length < 20 || notes.length > 125000) {
    refuse(
      "release_notes_invalid",
      "Release Please notes are empty or unbounded",
    );
  }

  return {
    candidateSha,
    packageName: composer.name,
    tag: `v${version}`,
    version,
    notes,
  };
}

export function readCandidateIdentity(root, candidateSha) {
  const read = (path) => readFileSync(resolve(root, path), "utf8");
  return candidateIdentityFromContents(
    {
      artifactService: read("src/Artifact/GitHubReleaseArtifactService.php"),
      bootstrap: read("bootstrap.php"),
      changelog: read("CHANGELOG.md"),
      composer: read("composer.json"),
      manifest: read(".release-please-manifest.json"),
      runtimeTest: read("tests/RuntimeTest.php"),
    },
    candidateSha,
  );
}

function labelsOf(pull) {
  return Array.isArray(pull?.labels)
    ? pull.labels.map((label) =>
        typeof label === "string" ? label : label?.name,
      )
    : [];
}

function releaseShaped(pull) {
  const labels = labelsOf(pull);
  return (
    pull?.head?.ref === RELEASE_BRANCH ||
    labels.includes(PENDING_LABEL) ||
    labels.includes(TAGGED_LABEL)
  );
}

function validateEvent(event, repository, repositoryId, candidateSha) {
  if (
    event?.event !== "push" ||
    event?.conclusion !== "success" ||
    event?.head_branch !== "main" ||
    !Number.isInteger(repositoryId) ||
    event?.head_repository?.id !== repositoryId ||
    event?.head_repository?.full_name !== repository ||
    event?.head_sha !== candidateSha
  ) {
    refuse(
      "quality_identity_invalid",
      "publisher requires successful same-repository main-push CI for the exact candidate",
    );
  }
}

function validateReleasePull(pull, repository, repositoryId, identity, commit) {
  const labels = labelsOf(pull);
  if (
    pull?.state !== "closed" ||
    typeof pull?.merged_at !== "string" ||
    pull?.draft !== false ||
    pull?.merge_commit_sha !== identity.candidateSha ||
    pull?.base?.ref !== "main" ||
    !FULL_SHA.test(pull?.base?.sha ?? "") ||
    pull?.base?.repo?.id !== repositoryId ||
    pull?.base?.repo?.full_name !== repository ||
    pull?.head?.ref !== RELEASE_BRANCH ||
    pull?.head?.repo?.id !== repositoryId ||
    pull?.head?.repo?.full_name !== repository ||
    pull?.user?.login !== BOT_LOGIN ||
    pull?.title !== `chore(main): release ${identity.version}` ||
    !Number.isInteger(pull?.number) ||
    pull.number < 1
  ) {
    refuse(
      "release_pr_invalid",
      "associated Release Please pull request identity is not exact",
    );
  }
  if (
    !FULL_SHA.test(pull?.head?.sha ?? "") ||
    pull.head.sha === identity.candidateSha
  ) {
    refuse("release_pr_invalid", "Release Please head identity is invalid");
  }
  if (
    commit?.sha !== identity.candidateSha ||
    !Array.isArray(commit?.parents) ||
    commit.parents.length !== 2 ||
    commit.parents[0]?.sha !== pull.base.sha ||
    commit.parents[1]?.sha !== pull.head.sha ||
    commit?.tree?.sha !== pull?.head_tree_sha
  ) {
    refuse(
      "release_pr_not_normal_merge",
      "candidate must be the normal two-parent merge of the exact Release Please head",
    );
  }
  if (
    typeof commit.parentVersion !== "string" ||
    !VERSION.test(commit.parentVersion)
  ) {
    refuse(
      "release_parent_version_invalid",
      "Release Please merge parent must declare one canonical prerelease version",
    );
  }
  if (commit.parentVersion === identity.version) {
    refuse(
      "release_version_unchanged",
      "Release Please merge must advance the manifest version",
    );
  }
  if (
    !Array.isArray(commit.changedPaths) ||
    commit.changedPaths.length !== RELEASE_PATHS.length ||
    !RELEASE_PATHS.every((path, index) => commit.changedPaths[index] === path)
  ) {
    refuse(
      "release_paths_invalid",
      "Release Please merge changed files outside the exact version and notes family",
    );
  }
  if (labels.includes(PENDING_LABEL) && labels.includes(TAGGED_LABEL)) {
    return { labelState: "mixed" };
  }
  if (labels.includes(PENDING_LABEL)) {
    return { labelState: "pending" };
  }
  if (labels.includes(TAGGED_LABEL)) {
    return { labelState: "tagged" };
  }
  return { labelState: "missing" };
}

function tagTarget(tagRef) {
  if (tagRef === null) {
    return null;
  }
  if (
    tagRef?.object?.type !== "commit" ||
    !FULL_SHA.test(tagRef?.object?.sha ?? "")
  ) {
    refuse(
      "tag_state_invalid",
      "existing tag must be a lightweight ref to one exact commit",
    );
  }
  return tagRef.object.sha;
}

export function verifyPublishedState(tagRef, release, identity) {
  if (tagTarget(tagRef) !== identity.candidateSha) {
    refuse(
      "tag_target_conflict",
      "tag target differs from the successful candidate",
    );
  }
  if (
    release?.tag_name !== identity.tag ||
    release?.target_commitish !== identity.candidateSha ||
    release?.name !== identity.tag ||
    release?.body !== identity.notes ||
    release?.draft !== false ||
    release?.prerelease !== true ||
    release?.immutable !== true ||
    !Number.isInteger(release?.id) ||
    release.id < 1
  ) {
    refuse(
      "release_state_conflict",
      "release target, notes, state or immutability is not exact",
    );
  }
  if (!Array.isArray(release?.assets) || release.assets.length !== 0) {
    refuse(
      "release_asset_conflict",
      "Composer library release must not upload a WordPress ZIP or any other asset",
    );
  }
  return true;
}

export function decidePublication(input) {
  const { candidateSha, identity, repository, repositoryId } = input;
  if (identity?.candidateSha !== candidateSha) {
    refuse(
      "candidate_identity_drift",
      "checked-out candidate identity differs from CI",
    );
  }
  validateEvent(input.event, repository, repositoryId, candidateSha);
  if (input.mainSha !== candidateSha) {
    refuse("main_moved", "main no longer points at the successful candidate");
  }

  const shaped = (input.pulls ?? []).filter(releaseShaped);
  const exactMerges = shaped.filter(
    (pull) =>
      pull?.state === "closed" &&
      typeof pull?.merged_at === "string" &&
      pull?.merge_commit_sha === candidateSha,
  );
  const staleMerges = shaped.filter(
    (pull) =>
      pull?.state === "closed" &&
      typeof pull?.merged_at === "string" &&
      pull?.merge_commit_sha !== candidateSha,
  );
  if (exactMerges.length === 0 && staleMerges.length === 0) {
    if (input.commit?.parentVersion !== identity.version) {
      refuse(
        "unrecognized_release_commit",
        "manifest changed without one exact eligible Release Please merge",
      );
    }
    return { action: "none", reason: "ordinary_main" };
  }
  if (exactMerges.length === 0) {
    refuse(
      "release_pr_stale",
      "candidate is associated only with a different merged release PR",
    );
  }
  if (exactMerges.length !== 1 || staleMerges.length !== 0) {
    refuse(
      "release_pr_ambiguous",
      "candidate is associated with multiple release-shaped PRs",
    );
  }
  const pull = exactMerges[0];
  const { labelState } = validateReleasePull(
    pull,
    repository,
    repositoryId,
    identity,
    input.commit,
  );
  const existingTagTarget = tagTarget(input.tagRef);

  if (input.release !== null && input.tagRef === null) {
    refuse("release_without_tag", "release exists without its exact tag ref");
  }
  if (existingTagTarget !== null && existingTagTarget !== candidateSha) {
    refuse("tag_target_conflict", "existing tag points at another commit");
  }
  if (input.release !== null) {
    verifyPublishedState(input.tagRef, input.release, identity);
    if (labelState === "missing") {
      refuse(
        "release_pr_label_conflict",
        "published candidate has no exact lifecycle label",
      );
    }
    return {
      action:
        labelState === "tagged" ? "already_published" : "reconcile_labels",
      pullNumber: pull.number,
    };
  }
  if (labelState !== "pending") {
    refuse(
      "release_pr_label_conflict",
      "unpublished candidate must have only autorelease: pending",
    );
  }
  if (input.tagRef !== null) {
    refuse(
      "partial_publication_state",
      "tag exists without one exact immutable release",
    );
  }
  if (input.immutableReleasesEnabled === false) {
    refuse(
      "immutable_releases_disabled",
      "immutable releases must be enabled before publication",
    );
  }

  return {
    action: "create_release",
    pullNumber: pull.number,
  };
}

function linkHasNext(value) {
  return typeof value === "string" && /<[^>]+>;\s*rel="next"/.test(value);
}

async function api(path, options = {}) {
  const token = process.env.GITHUB_TOKEN;
  if (!token) {
    refuse("token_missing", "GITHUB_TOKEN is required");
  }
  const response = await fetch(`https://api.github.com${path}`, {
    method: options.method ?? "GET",
    headers: {
      Accept: "application/vnd.github+json",
      Authorization: `Bearer ${token}`,
      "User-Agent": "ran-wp-github-release-updater-exact-publisher",
      "X-GitHub-Api-Version": options.apiVersion ?? API_VERSION,
    },
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    redirect: "error",
  });
  if (options.allow404 && response.status === 404) {
    return { data: null, headers: response.headers };
  }
  if (!response.ok) {
    refuse(
      "github_api_failed",
      `${options.method ?? "GET"} ${path} returned ${response.status}`,
    );
  }
  return {
    data: response.status === 204 ? null : await response.json(),
    headers: response.headers,
  };
}

async function associatedPulls(repository, candidateSha) {
  const pulls = [];
  for (let page = 1; page <= 10; page += 1) {
    const response = await api(
      `/repos/${repository}/commits/${candidateSha}/pulls?per_page=100&page=${page}`,
    );
    if (!Array.isArray(response.data)) {
      refuse(
        "pull_readback_invalid",
        "commit pull-request response is not a list",
      );
    }
    pulls.push(...response.data);
    if (!linkHasNext(response.headers.get("link"))) {
      return pulls;
    }
  }
  refuse(
    "pull_readback_unbounded",
    "commit pull-request response exceeded ten pages",
  );
}

async function hydrateReleasePullHeadTree(repository, candidateSha, pull) {
  const headSha = pull?.head?.sha;
  if (pull?.merge_commit_sha !== candidateSha || !FULL_SHA.test(headSha ?? "")) {
    refuse(
      "release_pr_invalid",
      "Release Please head identity is invalid",
    );
  }
  const response = await api(
    `/repos/${repository}/git/commits/${headSha}`,
  );
  if (
    response.data?.sha !== headSha ||
    !FULL_SHA.test(response.data?.tree?.sha ?? "")
  ) {
    refuse(
      "release_pr_head_tree_invalid",
      "Release Please head tree readback is invalid",
    );
  }
  return { ...pull, head_tree_sha: response.data.tree.sha };
}

async function hydrateReleasePullTrees(repository, candidateSha, pulls) {
  const shaped = pulls.filter(releaseShaped);
  const exactMerges = shaped.filter(
    (pull) =>
      pull?.state === "closed" &&
      typeof pull?.merged_at === "string" &&
      pull?.merge_commit_sha === candidateSha,
  );
  const staleMerges = shaped.filter(
    (pull) =>
      pull?.state === "closed" &&
      typeof pull?.merged_at === "string" &&
      pull?.merge_commit_sha !== candidateSha,
  );
  if (exactMerges.length !== 1 || staleMerges.length !== 0) {
    return pulls;
  }
  const hydrated = await hydrateReleasePullHeadTree(
    repository,
    candidateSha,
    exactMerges[0],
  );
  return pulls.map((pull) =>
    pull === exactMerges[0] ? hydrated : pull,
  );
}

async function remoteState(repository, tag) {
  const encoded = encodeURIComponent(tag);
  const [tagRef, release] = await Promise.all([
    api(`/repos/${repository}/git/ref/tags/${encoded}`, { allow404: true }),
    api(`/repos/${repository}/releases/tags/${encoded}`, {
      allow404: true,
      apiVersion: IMMUTABLE_RELEASES_API_VERSION,
    }),
  ]);
  return { release: release.data, tagRef: tagRef.data };
}

function git(root, args) {
  return execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
  }).trim();
}

function gitFile(root, revision, file) {
  const entry = git(root, ["ls-tree", revision, "--", file]);
  if (!/^100644 blob [a-f0-9]{40}\t/.test(entry)) {
    refuse(
      "release_content_drift",
      `${file} must remain one ordinary non-executable Git blob`,
    );
  }
  return execFileSync("git", ["show", `${revision}:${file}`], {
    cwd: root,
    encoding: "utf8",
  });
}

function sourceContentsAt(root, revision) {
  const read = (file) => gitFile(root, revision, file);
  return {
    artifactService: read("src/Artifact/GitHubReleaseArtifactService.php"),
    bootstrap: read("bootstrap.php"),
    changelog: read("CHANGELOG.md"),
    manifest: read(".release-please-manifest.json"),
    runtimeTest: read("tests/RuntimeTest.php"),
  };
}

function candidateIdentityAt(root, candidateSha) {
  return candidateIdentityFromContents(
    {
      ...sourceContentsAt(root, candidateSha),
      composer: gitFile(root, candidateSha, "composer.json"),
    },
    candidateSha,
  );
}

function localCommitFacts(root, candidateSha) {
  const parents = git(root, ["show", "--no-patch", "--format=%P", candidateSha])
    .split(/\s+/)
    .filter(Boolean);
  if (parents.length < 1 || parents.some((sha) => !FULL_SHA.test(sha))) {
    refuse(
      "candidate_history_invalid",
      "candidate parent history is unavailable or invalid",
    );
  }
  const treeSha = git(root, [
    "show",
    "--no-patch",
    "--format=%T",
    candidateSha,
  ]);
  const changedPaths = git(root, [
    "diff",
    "--name-only",
    parents[0],
    candidateSha,
  ])
    .split("\n")
    .filter(Boolean)
    .sort();
  const parentContents = sourceContentsAt(root, parents[0]);
  const candidateContents = sourceContentsAt(root, candidateSha);
  const versions =
    parentContents.manifest === candidateContents.manifest
      ? {
          candidateVersion: canonicalManifest(
            candidateContents.manifest,
            "candidate",
          ),
          parentVersion: canonicalManifest(parentContents.manifest, "parent"),
        }
      : verifyReleaseContentDelta(parentContents, candidateContents);

  return {
    commit: {
      changedPaths,
      parents: parents.map((sha) => ({ sha })),
      parentVersion: versions.parentVersion,
      sha: candidateSha,
      tree: { sha: treeSha },
    },
  };
}

async function reconcileLabels(repository, pullNumber, labels) {
  if (!labels.includes(TAGGED_LABEL)) {
    await api(`/repos/${repository}/issues/${pullNumber}/labels`, {
      method: "POST",
      body: { labels: [TAGGED_LABEL] },
    });
  }
  if (labels.includes(PENDING_LABEL)) {
    await api(
      `/repos/${repository}/issues/${pullNumber}/labels/${encodeURIComponent(PENDING_LABEL)}`,
      { method: "DELETE", allow404: true },
    );
  }
}

export async function runPublisher(root = process.cwd()) {
  const repository = process.env.GITHUB_REPOSITORY ?? "";
  if (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repository)) {
    refuse("repository_invalid", "GITHUB_REPOSITORY is invalid");
  }
  const eventPath = process.env.GITHUB_EVENT_PATH;
  if (!eventPath) {
    refuse("event_missing", "GITHUB_EVENT_PATH is required");
  }
  const payload = JSON.parse(readFileSync(eventPath, "utf8"));
  const event = payload.workflow_run;
  const repositoryId = payload?.repository?.id;
  const candidateSha = event?.head_sha ?? "";
  const localHead = git(root, ["rev-parse", "HEAD"]);
  if (localHead !== candidateSha) {
    refuse("checkout_drift", "checkout does not match workflow_run.head_sha");
  }
  const identity = candidateIdentityAt(root, candidateSha);
  const [main, pulls, state] = await Promise.all([
    api(`/repos/${repository}/git/ref/heads/main`),
    associatedPulls(repository, candidateSha),
    remoteState(repository, identity.tag),
  ]);
  const local = localCommitFacts(root, candidateSha);
  const pullsWithTrees = await hydrateReleasePullTrees(
    repository,
    candidateSha,
    pulls,
  );
  let input = {
    candidateSha,
    commit: local.commit,
    event,
    identity,
    mainSha: main.data?.object?.sha,
    pulls: pullsWithTrees,
    release: state.release,
    repository,
    repositoryId,
    tagRef: state.tagRef,
  };
  let decision = decidePublication(input);
  if (decision.action === "none") {
    process.stdout.write(`No publication: ${decision.reason}\n`);
    return decision;
  }
  if (process.env.RAN_RELEASE_PUBLISHER_MUTATE !== "1") {
    refuse(
      "mutation_disabled",
      "publisher mutation requires RAN_RELEASE_PUBLISHER_MUTATE=1",
    );
  }

  const [freshMain, freshPulls, freshState] = await Promise.all([
    api(`/repos/${repository}/git/ref/heads/main`),
    associatedPulls(repository, candidateSha),
    remoteState(repository, identity.tag),
  ]);
  const freshLocal = localCommitFacts(root, candidateSha);
  const freshPullsWithTrees = await hydrateReleasePullTrees(
    repository,
    candidateSha,
    freshPulls,
  );
  input = {
    ...input,
    commit: freshLocal.commit,
    immutableReleasesEnabled:
      freshState.release === null
        ? process.env
            .RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID ===
          String(repositoryId)
        : undefined,
    mainSha: freshMain.data?.object?.sha,
    pulls: freshPullsWithTrees,
    release: freshState.release,
    tagRef: freshState.tagRef,
  };
  decision = decidePublication(input);

  if (decision.action === "create_release") {
    await api(`/repos/${repository}/releases`, {
      apiVersion: IMMUTABLE_RELEASES_API_VERSION,
      method: "POST",
      body: {
        body: identity.notes,
        draft: false,
        generate_release_notes: false,
        name: identity.tag,
        prerelease: true,
        tag_name: identity.tag,
        target_commitish: candidateSha,
      },
    });
  }

  const readback = await remoteState(repository, identity.tag);
  verifyPublishedState(readback.tagRef, readback.release, identity);
  const originalPull = input.pulls.find(
    (pull) => pull.number === decision.pullNumber,
  );
  await reconcileLabels(
    repository,
    decision.pullNumber,
    labelsOf(originalPull),
  );
  const finalPull = (
    await api(`/repos/${repository}/pulls/${decision.pullNumber}`)
  ).data;
  const finalPullWithTree = await hydrateReleasePullHeadTree(
    repository,
    candidateSha,
    finalPull,
  );
  validateReleasePull(
    finalPullWithTree,
    repository,
    repositoryId,
    identity,
    input.commit,
  );
  const finalLabels = labelsOf(finalPull);
  if (
    !finalLabels.includes(TAGGED_LABEL) ||
    finalLabels.includes(PENDING_LABEL)
  ) {
    refuse(
      "release_label_readback_failed",
      "release PR labels did not reconcile to tagged",
    );
  }

  process.stdout.write(
    `Published ${identity.packageName} ${identity.tag} at ${candidateSha}; release ${readback.release.id} is immutable with zero uploaded assets\n`,
  );
  return { ...decision, releaseId: readback.release.id };
}

const invoked = process.argv[1]
  ? resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))
  : false;
if (invoked) {
  runPublisher().catch((error) => {
    process.stderr.write(
      `${error instanceof Error ? error.message : String(error)}\n`,
    );
    process.exitCode = 1;
  });
}
