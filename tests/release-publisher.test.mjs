import assert from "node:assert/strict";
import { execFileSync } from "node:child_process";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";

import {
  PublisherRefusal,
  candidateIdentityFromContents,
  decidePublication,
  runPublisher,
  verifyReleaseContentDelta,
  verifyPublishedState,
} from "../scripts/release-publisher.mjs";

const CANDIDATE = "a".repeat(40);
const RELEASE_HEAD = "b".repeat(40);
const MAIN_PARENT = "c".repeat(40);
const TREE_SHA = "e".repeat(40);
const REPOSITORY = "RocketsAreNostalgic/ran-wp-github-release-updater";
const REPOSITORY_ID = 123456789;
const VERSION = "2.0.0-beta.5";

function sourceFixture(overrides = {}) {
  return {
    artifactService:
      "'User-Agent' => 'ran-wp-github-release-updater/2.0.0-beta.5', // x-release-please-version",
    bootstrap:
      "'package_version' => '2.0.0-beta.5', // x-release-please-version",
    changelog:
      "# Changelog\n\n## [2.0.0-beta.5](https://example.test) (2026-08-10)\n\n### Bug Fixes\n\n* exact publisher\n\n## [2.0.0-beta.4](https://example.test)\n\n* previous\n",
    composer: JSON.stringify({
      name: "ran/wp-github-release-updater",
      type: "library",
    }),
    manifest: JSON.stringify({ ".": VERSION }),
    runtimeTest:
      "assertSame( '2.0.0-beta.5', $diagnostics['selected_version'] ); // x-release-please-version\n" +
      "assertSame( '2.0.0-beta.5', $diagnostics['selected_version'] ); // x-release-please-version",
    ...overrides,
  };
}

function identityFixture() {
  return candidateIdentityFromContents(sourceFixture(), CANDIDATE);
}

function releaseSourcePair() {
  const candidate = sourceFixture();
  const parent = {
    ...candidate,
    artifactService: candidate.artifactService.replaceAll(
      VERSION,
      "2.0.0-beta.4",
    ),
    bootstrap: candidate.bootstrap.replaceAll(VERSION, "2.0.0-beta.4"),
    changelog:
      "# Changelog\n\n## [2.0.0-beta.4](https://example.test)\n\n* previous\n",
    manifest: JSON.stringify({ ".": "2.0.0-beta.4" }),
    runtimeTest: candidate.runtimeTest.replaceAll(VERSION, "2.0.0-beta.4"),
  };
  return { candidate, parent };
}

function pullFixture(overrides = {}) {
  return {
    base: {
      ref: "main",
      repo: { full_name: REPOSITORY, id: REPOSITORY_ID },
      sha: MAIN_PARENT,
    },
    draft: false,
    head: {
      ref: "release-please--branches--main--components--ran/wp-github-release-updater",
      repo: { full_name: REPOSITORY, id: REPOSITORY_ID },
      sha: RELEASE_HEAD,
    },
    head_tree_sha: TREE_SHA,
    labels: [{ name: "autorelease: pending" }],
    merge_commit_sha: CANDIDATE,
    merged_at: "2026-08-10T10:00:00Z",
    number: 12,
    state: "closed",
    title: "chore(main): release 2.0.0-beta.5",
    user: { login: "github-actions[bot]" },
    ...overrides,
  };
}

function tagFixture(sha = CANDIDATE) {
  return { object: { sha, type: "commit" }, ref: `refs/tags/v${VERSION}` };
}

function releaseFixture(identity = identityFixture(), overrides = {}) {
  return {
    assets: [],
    body: identity.notes,
    draft: false,
    id: 1234,
    immutable: true,
    name: identity.tag,
    prerelease: true,
    tag_name: identity.tag,
    target_commitish: CANDIDATE,
    ...overrides,
  };
}

function inputFixture(overrides = {}) {
  const identity = identityFixture();
  return {
    candidateSha: CANDIDATE,
    commit: {
      changedPaths: [
        ".release-please-manifest.json",
        "CHANGELOG.md",
        "bootstrap.php",
        "src/Artifact/GitHubReleaseArtifactService.php",
        "tests/RuntimeTest.php",
      ],
      parents: [{ sha: MAIN_PARENT }, { sha: RELEASE_HEAD }],
      parentVersion: "2.0.0-beta.4",
      sha: CANDIDATE,
      tree: { sha: TREE_SHA },
    },
    event: {
      conclusion: "success",
      event: "push",
      head_branch: "main",
      head_repository: { full_name: REPOSITORY, id: REPOSITORY_ID },
      head_sha: CANDIDATE,
    },
    identity,
    immutableReleasesEnabled: true,
    mainSha: CANDIDATE,
    pulls: [pullFixture()],
    release: null,
    repository: REPOSITORY,
    repositoryId: REPOSITORY_ID,
    tagRef: null,
    ...overrides,
  };
}

function refusal(code, callback) {
  assert.throws(callback, (error) => {
    assert.ok(error instanceof PublisherRefusal);
    assert.equal(error.code, code);
    return true;
  });
}

function gitRun(root, args) {
  return execFileSync("git", args, {
    cwd: root,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  }).trim();
}

function writeSources(root, sources) {
  mkdirSync(join(root, "src/Artifact"), { recursive: true });
  mkdirSync(join(root, "tests"), { recursive: true });
  const files = {
    ".release-please-manifest.json": sources.manifest,
    "CHANGELOG.md": sources.changelog,
    "bootstrap.php": sources.bootstrap,
    "composer.json": sources.composer,
    "src/Artifact/GitHubReleaseArtifactService.php": sources.artifactService,
    "tests/RuntimeTest.php": sources.runtimeTest,
  };
  for (const [file, contents] of Object.entries(files)) {
    writeFileSync(join(root, file), contents);
  }
}

function publisherRepositoryFixture(options = {}) {
  const root = mkdtempSync(join(tmpdir(), "updater-publisher-"));
  gitRun(root, ["init", "--initial-branch=main"]);
  gitRun(root, ["config", "user.email", "publisher@example.test"]);
  gitRun(root, ["config", "user.name", "Publisher Fixture"]);
  const sources = releaseSourcePair();
  writeSources(root, sources.parent);
  gitRun(root, ["add", "."]);
  gitRun(root, ["commit", "-m", "fix: parent"]);
  const parentSha = gitRun(root, ["rev-parse", "HEAD"]);
  gitRun(root, ["checkout", "-b", "release-candidate"]);
  writeSources(root, sources.candidate);
  gitRun(root, ["add", "."]);
  gitRun(root, ["commit", "-m", "chore: release beta.5"]);
  const headSha = gitRun(root, ["rev-parse", "HEAD"]);
  const headTree = gitRun(root, ["show", "--no-patch", "--format=%T", "HEAD"]);
  gitRun(root, ["checkout", "main"]);
  if (options.normalMerge ?? true) {
    gitRun(root, [
      "merge",
      "--no-ff",
      "release-candidate",
      "-m",
      "release beta.5",
    ]);
  } else {
    gitRun(root, ["merge", "--squash", "release-candidate"]);
    gitRun(root, ["commit", "-m", "release beta.5"]);
  }
  const candidateSha = gitRun(root, ["rev-parse", "HEAD"]);
  const eventPath = join(root, "event.json");
  writeFileSync(
    eventPath,
    JSON.stringify({
      repository: { id: REPOSITORY_ID },
      workflow_run: {
        conclusion: "success",
        event: "push",
        head_branch: "main",
        head_repository: { full_name: REPOSITORY, id: REPOSITORY_ID },
        head_sha: candidateSha,
      },
    }),
  );
  const identity = candidateIdentityFromContents(
    sources.candidate,
    candidateSha,
  );
  const pull = pullFixture({
    base: { ...pullFixture().base, sha: parentSha },
    head: { ...pullFixture().head, sha: headSha },
    head_tree_sha: headTree,
    merge_commit_sha: candidateSha,
  });
  return { candidateSha, eventPath, identity, pull, root };
}

function response(data, status = 200) {
  return new Response(data === null ? null : JSON.stringify(data), {
    headers: { "content-type": "application/json" },
    status,
  });
}

function publisherTransport(fixture, options = {}) {
  const calls = [];
  const state = {
    labels: ["autorelease: pending"],
    loseAcknowledgement: options.loseAcknowledgement ?? false,
    postCreateImmutable: options.postCreateImmutable ?? true,
    release: null,
    tagRef: null,
  };
  const prefix = `/repos/${REPOSITORY}`;
  const fetch = async (url, request = {}) => {
    const method = request.method ?? "GET";
    const pathname = new URL(url).pathname;
    const body = request.body ? JSON.parse(request.body) : null;
    calls.push({ body, method, pathname });
    if (method === "GET" && pathname === `${prefix}/git/ref/heads/main`) {
      return response({ object: { sha: fixture.candidateSha } });
    }
    if (
      method === "GET" &&
      pathname.includes(`/commits/${fixture.candidateSha}/pulls`)
    ) {
      return response(
        options.associatedPulls ?? [
          { ...fixture.pull, labels: state.labels.map((name) => ({ name })) },
        ],
      );
    }
    if (method === "GET" && pathname.includes("/git/commits/")) {
      return response({
        sha: fixture.pull.head.sha,
        tree: { sha: options.remoteHeadTree ?? fixture.pull.head_tree_sha },
      });
    }
    if (method === "GET" && pathname.includes("/git/ref/tags/")) {
      return state.tagRef === null
        ? response(null, 404)
        : response(state.tagRef);
    }
    if (method === "GET" && pathname.includes("/releases/tags/")) {
      return state.release === null
        ? response(null, 404)
        : response(state.release);
    }
    if (method === "POST" && pathname === `${prefix}/releases`) {
      state.tagRef = {
        object: { sha: fixture.candidateSha, type: "commit" },
        ref: `refs/tags/${fixture.identity.tag}`,
      };
      state.release = {
        assets: [],
        body: fixture.identity.notes,
        draft: false,
        id: 9876,
        immutable: state.postCreateImmutable,
        name: fixture.identity.tag,
        prerelease: true,
        tag_name: fixture.identity.tag,
        target_commitish: fixture.candidateSha,
      };
      if (state.loseAcknowledgement) {
        state.loseAcknowledgement = false;
        return response({ message: "lost acknowledgement" }, 502);
      }
      return response(state.release, 201);
    }
    if (method === "POST" && pathname === `${prefix}/issues/12/labels`) {
      state.labels = [...new Set([...state.labels, ...body.labels])];
      return response(state.labels.map((name) => ({ name })));
    }
    if (method === "DELETE" && pathname.includes("/issues/12/labels/")) {
      state.labels = state.labels.filter(
        (name) => name !== "autorelease: pending",
      );
      return response(null, 204);
    }
    if (method === "GET" && pathname === `${prefix}/pulls/12`) {
      return response({
        ...fixture.pull,
        labels: state.labels.map((name) => ({ name })),
      });
    }
    throw new Error(`Unhandled publisher request: ${method} ${pathname}`);
  };
  return { calls, fetch, state };
}

function installPublisherEnvironment(fixture, fetch) {
  const originalFetch = globalThis.fetch;
  const names = [
    "GITHUB_EVENT_PATH",
    "GITHUB_REPOSITORY",
    "GITHUB_TOKEN",
    "RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID",
    "RAN_RELEASE_PUBLISHER_MUTATE",
  ];
  const original = Object.fromEntries(
    names.map((name) => [name, process.env[name]]),
  );
  globalThis.fetch = fetch;
  process.env.GITHUB_EVENT_PATH = fixture.eventPath;
  process.env.GITHUB_REPOSITORY = REPOSITORY;
  process.env.GITHUB_TOKEN = "fixture-token";
  process.env.RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID =
    String(REPOSITORY_ID);
  process.env.RAN_RELEASE_PUBLISHER_MUTATE = "1";
  return () => {
    globalThis.fetch = originalFetch;
    for (const name of names) {
      if (original[name] === undefined) delete process.env[name];
      else process.env[name] = original[name];
    }
    rmSync(fixture.root, { force: true, recursive: true });
  };
}

test("candidate identity binds every Release Please version source and exact notes", () => {
  const identity = identityFixture();
  assert.equal(identity.candidateSha, CANDIDATE);
  assert.equal(identity.packageName, "ran/wp-github-release-updater");
  assert.equal(identity.version, VERSION);
  assert.equal(identity.tag, `v${VERSION}`);
  assert.match(identity.notes, /^## \[2\.0\.0-beta\.5\]/);
  assert.doesNotMatch(identity.notes, /2\.0\.0-beta\.4/);
});

test("candidate identity rejects version-source drift", () => {
  refusal("version_source_drift", () =>
    candidateIdentityFromContents(
      sourceFixture({
        bootstrap:
          "'package_version' => '2.0.0-beta.6', // x-release-please-version",
      }),
      CANDIDATE,
    ),
  );
  refusal("release_notes_missing", () =>
    candidateIdentityFromContents(
      sourceFixture({
        changelog:
          "# Changelog\n\n## [2.0.0-beta.6](https://example.test)\n\n* newer\n\n## [2.0.0-beta.5](https://example.test)\n\n* stale candidate\n",
      }),
      CANDIDATE,
    ),
  );
});

test("candidate identity rejects a Composer version field", () => {
  refusal("composer_identity_invalid", () =>
    candidateIdentityFromContents(
      sourceFixture({
        composer: JSON.stringify({
          name: "ran/wp-github-release-updater",
          type: "library",
          version: VERSION,
        }),
      }),
      CANDIDATE,
    ),
  );
});

test("candidate identity requires canonical beta SemVer", () => {
  for (const version of [
    "01.2.3-beta.1",
    "1.02.3-beta.1",
    "1.2.03-beta.1",
    "1.2.3-beta.01",
    "1.2.3-beta.",
    "1.2.3-beta..1",
    "1.2.3-alpha.1",
  ]) {
    refusal("version_source_invalid", () =>
      candidateIdentityFromContents(
        sourceFixture({ manifest: JSON.stringify({ ".": version }) }),
        CANDIDATE,
      ),
    );
  }
});

test("Release Please content delta permits only annotated tokens and a changelog prepend", () => {
  const { candidate, parent } = releaseSourcePair();
  assert.deepEqual(verifyReleaseContentDelta(parent, candidate), {
    candidateVersion: VERSION,
    parentVersion: "2.0.0-beta.4",
  });

  refusal("release_content_drift", () =>
    verifyReleaseContentDelta(parent, {
      ...candidate,
      bootstrap: `${candidate.bootstrap}\n// in-family extra edit`,
    }),
  );
  refusal("release_content_drift", () =>
    verifyReleaseContentDelta(parent, {
      ...candidate,
      changelog: candidate.changelog.replace(
        "* previous",
        "* rewritten history",
      ),
    }),
  );
  const downgraded = {
    ...candidate,
    manifest: JSON.stringify({ ".": "2.0.0-beta.3" }),
  };
  refusal("release_version_not_advanced", () =>
    verifyReleaseContentDelta(parent, downgraded),
  );
});

test("ordinary main commits have no publication side effect", () => {
  const ordinary = pullFixture({
    head: {
      ref: "feature",
      repo: { full_name: REPOSITORY, id: REPOSITORY_ID },
      sha: RELEASE_HEAD,
    },
    labels: [],
    title: "fix: ordinary change",
  });
  const ordinaryCommit = { ...inputFixture().commit, parentVersion: VERSION };
  assert.deepEqual(
    decidePublication(
      inputFixture({ commit: ordinaryCommit, pulls: [ordinary] }),
    ),
    { action: "none", reason: "ordinary_main" },
  );
  const openReleasePlease = pullFixture({
    merge_commit_sha: null,
    merged_at: null,
    state: "open",
  });
  assert.deepEqual(
    decidePublication(
      inputFixture({ commit: ordinaryCommit, pulls: [openReleasePlease] }),
    ),
    { action: "none", reason: "ordinary_main" },
  );
});

test("failed or foreign CI cannot select a candidate", () => {
  const failed = inputFixture();
  failed.event.conclusion = "failure";
  refusal("quality_identity_invalid", () => decidePublication(failed));

  const foreign = inputFixture();
  foreign.event.head_repository.full_name = "attacker/fork";
  refusal("quality_identity_invalid", () => decidePublication(foreign));

  const foreignId = inputFixture();
  foreignId.event.head_repository.id = REPOSITORY_ID + 1;
  refusal("quality_identity_invalid", () => decidePublication(foreignId));
});

test("main movement after the successful run fails closed", () => {
  refusal("main_moved", () =>
    decidePublication(inputFixture({ mainSha: "d".repeat(40) })),
  );
});

test("one exact normal Release Please merge selects tag and release creation", () => {
  assert.deepEqual(decidePublication(inputFixture()), {
    action: "create_release",
    pullNumber: 12,
  });
});

test("squash/rebase and stale merge identities fail closed", () => {
  const squashed = inputFixture({
    commit: { ...inputFixture().commit, parents: [{ sha: MAIN_PARENT }] },
  });
  refusal("release_pr_not_normal_merge", () => decidePublication(squashed));

  const stale = inputFixture({
    pulls: [pullFixture({ merge_commit_sha: "d".repeat(40) })],
  });
  refusal("release_pr_stale", () => decidePublication(stale));
});

test("normal merge ordering, tree identity and version-only paths are exact", () => {
  const reversed = inputFixture({
    commit: {
      ...inputFixture().commit,
      parents: [{ sha: RELEASE_HEAD }, { sha: MAIN_PARENT }],
    },
  });
  refusal("release_pr_not_normal_merge", () => decidePublication(reversed));

  const wrongTree = inputFixture({
    commit: { ...inputFixture().commit, tree: { sha: "f".repeat(40) } },
  });
  refusal("release_pr_not_normal_merge", () => decidePublication(wrongTree));

  const wrongBase = inputFixture({
    pulls: [
      pullFixture({ base: { ...pullFixture().base, sha: "f".repeat(40) } }),
    ],
  });
  refusal("release_pr_not_normal_merge", () => decidePublication(wrongBase));

  const extraPath = inputFixture({
    commit: {
      ...inputFixture().commit,
      changedPaths: [...inputFixture().commit.changedPaths, "src/Runtime.php"],
    },
  });
  refusal("release_paths_invalid", () => decidePublication(extraPath));

  const unchanged = inputFixture({
    commit: { ...inputFixture().commit, parentVersion: VERSION },
  });
  refusal("release_version_unchanged", () => decidePublication(unchanged));

  for (const parentVersion of [undefined, "beta.4"]) {
    const malformedParent = inputFixture({
      commit: { ...inputFixture().commit, parentVersion },
    });
    refusal("release_parent_version_invalid", () =>
      decidePublication(malformedParent),
    );
  }
});

test("a manifest change without one exact Release Please merge is refused", () => {
  refusal("unrecognized_release_commit", () =>
    decidePublication(inputFixture({ pulls: [] })),
  );
});

test("forked or ambiguous release PR identity fails closed", () => {
  const forked = pullFixture({
    head: {
      ref: "release-please--branches--main--components--ran/wp-github-release-updater",
      repo: { full_name: "attacker/fork", id: REPOSITORY_ID + 1 },
      sha: RELEASE_HEAD,
    },
  });
  refusal("release_pr_invalid", () =>
    decidePublication(inputFixture({ pulls: [forked] })),
  );
  refusal("release_pr_ambiguous", () =>
    decidePublication(
      inputFixture({ pulls: [pullFixture(), pullFixture({ number: 13 })] }),
    ),
  );
});

test("an exact but partial tag state fails closed", () => {
  refusal("partial_publication_state", () =>
    decidePublication(inputFixture({ tagRef: tagFixture() })),
  );
});

test("a conflicting tag or release without a tag fails closed", () => {
  refusal("tag_target_conflict", () =>
    decidePublication(inputFixture({ tagRef: tagFixture("d".repeat(40)) })),
  );
  refusal("release_without_tag", () =>
    decidePublication(inputFixture({ release: releaseFixture() })),
  );
});

test("unpublished candidates require the exact pending label", () => {
  const tagged = pullFixture({ labels: [{ name: "autorelease: tagged" }] });
  refusal("release_pr_label_conflict", () =>
    decidePublication(inputFixture({ pulls: [tagged] })),
  );
  const missing = pullFixture({ labels: [] });
  refusal("release_pr_label_conflict", () =>
    decidePublication(inputFixture({ pulls: [missing] })),
  );
});

test("publication refuses before mutation when immutable releases are disabled", () => {
  refusal("immutable_releases_disabled", () =>
    decidePublication(inputFixture({ immutableReleasesEnabled: false })),
  );
});

test("exact immutable publication is idempotent and repairs interrupted labels", () => {
  const identity = identityFixture();
  const state = {
    release: releaseFixture(identity),
    tagRef: tagFixture(),
  };
  const tagged = pullFixture({ labels: [{ name: "autorelease: tagged" }] });
  assert.deepEqual(
    decidePublication(inputFixture({ ...state, identity, pulls: [tagged] })),
    { action: "already_published", pullNumber: 12 },
  );
  const mixed = pullFixture({
    labels: [{ name: "autorelease: pending" }, { name: "autorelease: tagged" }],
  });
  assert.deepEqual(
    decidePublication(inputFixture({ ...state, identity, pulls: [mixed] })),
    { action: "reconcile_labels", pullNumber: 12 },
  );
});

test("mutable, mistargeted or asset-bearing releases fail readback", () => {
  const identity = identityFixture();
  refusal("release_state_conflict", () =>
    verifyPublishedState(
      tagFixture(),
      releaseFixture(identity, { immutable: false }),
      identity,
    ),
  );
  refusal("release_state_conflict", () =>
    verifyPublishedState(
      tagFixture(),
      releaseFixture(identity, { target_commitish: "d".repeat(40) }),
      identity,
    ),
  );
  refusal("release_asset_conflict", () =>
    verifyPublishedState(
      tagFixture(),
      releaseFixture(identity, {
        assets: [{ id: 99, name: "ran-wp-github-release-updater.zip" }],
      }),
      identity,
    ),
  );
});

test("runPublisher publishes once and reads exact immutable state before labels", async (context) => {
  const fixture = publisherRepositoryFixture();
  const transport = publisherTransport(fixture);
  context.after(installPublisherEnvironment(fixture, transport.fetch));

  const result = await runPublisher(fixture.root);
  assert.equal(result.action, "create_release");
  assert.equal(result.releaseId, 9876);
  assert.deepEqual(transport.state.labels, ["autorelease: tagged"]);
  assert.deepEqual(transport.state.release, {
    assets: [],
    body: fixture.identity.notes,
    draft: false,
    id: 9876,
    immutable: true,
    name: fixture.identity.tag,
    prerelease: true,
    tag_name: fixture.identity.tag,
    target_commitish: fixture.candidateSha,
  });

  const calls = transport.calls;
  const releasePosts = calls
    .map((call, index) => ({ ...call, index }))
    .filter(
      (call) => call.method === "POST" && call.pathname.endsWith("/releases"),
    );
  assert.equal(releasePosts.length, 1);
  assert.equal(
    calls.filter(
      (call) => call.method === "POST" && call.pathname.includes("/git/refs"),
    ).length,
    0,
  );
  const labelWrite = calls.findIndex(
    (call) =>
      call.method !== "GET" && call.pathname.includes("/issues/12/labels"),
  );
  const tagReadback = calls.findIndex(
    (call, index) =>
      index > releasePosts[0].index &&
      call.method === "GET" &&
      call.pathname.includes("/git/ref/tags/"),
  );
  const releaseReadback = calls.findIndex(
    (call, index) =>
      index > releasePosts[0].index &&
      call.method === "GET" &&
      call.pathname.includes("/releases/tags/"),
  );
  const finalPullReadback = calls.findIndex((call) =>
    call.pathname.endsWith("/pulls/12"),
  );
  assert.equal(
    calls.filter((call) =>
      call.pathname.endsWith("/immutable-releases"),
    ).length,
    0,
  );
  assert.ok(tagReadback > releasePosts[0].index && tagReadback < labelWrite);
  assert.ok(
    releaseReadback > releasePosts[0].index && releaseReadback < labelWrite,
  );
  assert.ok(finalPullReadback > labelWrite);
  assert.equal(
    calls.filter((call) => call.pathname.includes("/git/commits/")).length,
    3,
  );
});

test("runPublisher refuses a one-parent Release Please candidate without writes", async (context) => {
  const fixture = publisherRepositoryFixture({ normalMerge: false });
  const transport = publisherTransport(fixture);
  context.after(installPublisherEnvironment(fixture, transport.fetch));

  await assert.rejects(runPublisher(fixture.root), (error) => {
    assert.equal(error.code, "release_pr_not_normal_merge");
    return true;
  });
  assert.equal(transport.calls.filter((call) => call.method !== "GET").length, 0);
});

test("runPublisher fails closed when the remote Release Please head tree is malformed", async (context) => {
  const fixture = publisherRepositoryFixture();
  const transport = publisherTransport(fixture, { remoteHeadTree: "not-a-sha" });
  context.after(installPublisherEnvironment(fixture, transport.fetch));

  await assert.rejects(runPublisher(fixture.root), (error) => {
    assert.equal(error.code, "release_pr_head_tree_invalid");
    return true;
  });
  assert.equal(transport.calls.filter((call) => call.method !== "GET").length, 0);
});

test("runPublisher does not hydrate a head tree for an ordinary main commit", async (context) => {
  const fixture = publisherRepositoryFixture();
  writeFileSync(join(fixture.root, "ordinary.txt"), "ordinary main change\n");
  gitRun(fixture.root, ["add", "ordinary.txt"]);
  gitRun(fixture.root, ["commit", "-m", "fix: ordinary main change"]);
  fixture.candidateSha = gitRun(fixture.root, ["rev-parse", "HEAD"]);
  fixture.identity = candidateIdentityFromContents(
    releaseSourcePair().candidate,
    fixture.candidateSha,
  );
  writeFileSync(
    fixture.eventPath,
    JSON.stringify({
      repository: { id: REPOSITORY_ID },
      workflow_run: {
        conclusion: "success",
        event: "push",
        head_branch: "main",
        head_repository: { full_name: REPOSITORY, id: REPOSITORY_ID },
        head_sha: fixture.candidateSha,
      },
    }),
  );
  const transport = publisherTransport(fixture, { associatedPulls: [] });
  context.after(installPublisherEnvironment(fixture, transport.fetch));

  assert.deepEqual(await runPublisher(fixture.root), {
    action: "none",
    reason: "ordinary_main",
  });
  assert.equal(
    transport.calls.filter((call) => call.pathname.includes("/git/commits/")).length,
    0,
  );
});

test("runPublisher recovers a lost release acknowledgement only after exact readback", async (context) => {
  const fixture = publisherRepositoryFixture();
  const transport = publisherTransport(fixture, { loseAcknowledgement: true });
  context.after(installPublisherEnvironment(fixture, transport.fetch));

  await assert.rejects(runPublisher(fixture.root), (error) => {
    assert.equal(error.code, "github_api_failed");
    return true;
  });
  assert.deepEqual(transport.state.labels, ["autorelease: pending"]);

  delete process.env
    .RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID;
  const result = await runPublisher(fixture.root);
  assert.equal(result.action, "reconcile_labels");
  assert.equal(result.releaseId, 9876);
  assert.deepEqual(transport.state.labels, ["autorelease: tagged"]);

  const calls = transport.calls;
  const releasePosts = calls
    .map((call, index) => ({ ...call, index }))
    .filter(
      (call) => call.method === "POST" && call.pathname.endsWith("/releases"),
    );
  assert.equal(releasePosts.length, 1);
  assert.deepEqual(releasePosts[0].body, {
    body: fixture.identity.notes,
    draft: false,
    generate_release_notes: false,
    name: fixture.identity.tag,
    prerelease: true,
    tag_name: fixture.identity.tag,
    target_commitish: fixture.candidateSha,
  });
  assert.equal(
    calls.filter(
      (call) => call.method === "POST" && call.pathname.includes("/git/refs"),
    ).length,
    0,
  );
  const labelWrite = calls.findIndex(
    (call) =>
      call.method !== "GET" && call.pathname.includes("/issues/12/labels"),
  );
  const tagReadback = calls.findIndex(
    (call, index) =>
      index > releasePosts[0].index &&
      call.method === "GET" &&
      call.pathname.includes("/git/ref/tags/"),
  );
  const releaseReadback = calls.findIndex(
    (call, index) =>
      index > releasePosts[0].index &&
      call.method === "GET" &&
      call.pathname.includes("/releases/tags/"),
  );
  assert.equal(
    calls.filter((call) =>
      call.pathname.endsWith("/immutable-releases"),
    ).length,
    0,
  );
  assert.ok(tagReadback > releasePosts[0].index && tagReadback < labelWrite);
  assert.ok(
    releaseReadback > releasePosts[0].index && releaseReadback < labelWrite,
  );
  assert.ok(
    calls.findIndex((call) => call.pathname.endsWith("/pulls/12")) > labelWrite,
  );
});

test("runPublisher refuses a missing or mismatched immutable repository acknowledgement before any write", async (context) => {
  const fixture = publisherRepositoryFixture();
  const transport = publisherTransport(fixture);
  context.after(installPublisherEnvironment(fixture, transport.fetch));

  for (const acknowledgement of [
    undefined,
    "",
    "1",
    String(REPOSITORY_ID + 1),
    ` ${REPOSITORY_ID} `,
  ]) {
    if (acknowledgement === undefined) {
      delete process.env
        .RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID;
    } else {
      process.env
        .RAN_RELEASE_PUBLISHER_IMMUTABLE_RELEASES_ACKNOWLEDGED_REPOSITORY_ID =
        acknowledgement;
    }
    await assert.rejects(runPublisher(fixture.root), (error) => {
      assert.equal(error.code, "immutable_releases_disabled");
      return true;
    });
  }
  assert.equal(
    transport.calls.filter((call) => call.method !== "GET").length,
    0,
  );
  assert.equal(
    transport.calls.filter((call) =>
      call.pathname.endsWith("/immutable-releases"),
    ).length,
    0,
  );
});

test("runPublisher refuses a mutable post-create release before labels", async (context) => {
  const fixture = publisherRepositoryFixture();
  const transport = publisherTransport(fixture, { postCreateImmutable: false });
  context.after(installPublisherEnvironment(fixture, transport.fetch));

  await assert.rejects(runPublisher(fixture.root), (error) => {
    assert.equal(error.code, "release_state_conflict");
    return true;
  });
  assert.deepEqual(transport.state.labels, ["autorelease: pending"]);
  assert.equal(
    transport.calls.filter(
      (call) =>
        call.method !== "GET" &&
        call.pathname.includes("/issues/12/labels"),
    ).length,
    0,
  );
  assert.equal(
    transport.calls.filter((call) =>
      call.pathname.endsWith("/immutable-releases"),
    ).length,
    0,
  );
});
