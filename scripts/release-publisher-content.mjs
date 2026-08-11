export const CANONICAL_VERSION =
  /^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-beta\.(0|[1-9][0-9]*)$/;

export class PublisherRefusal extends Error {
  constructor(code, message) {
    super(`${code}: ${message}`);
    this.name = "PublisherRefusal";
    this.code = code;
  }
}

export function refuse(code, message) {
  throw new PublisherRefusal(code, message);
}

export function canonicalManifest(raw, source) {
  let manifest;
  try {
    manifest = JSON.parse(raw);
  } catch {
    refuse("release_manifest_invalid", `${source} manifest is not valid JSON`);
  }
  if (
    manifest === null ||
    Array.isArray(manifest) ||
    typeof manifest !== "object" ||
    Object.keys(manifest).length !== 1 ||
    !Object.hasOwn(manifest, ".") ||
    typeof manifest["."] !== "string" ||
    !CANONICAL_VERSION.test(manifest["."])
  ) {
    refuse(
      "release_manifest_invalid",
      `${source} manifest must contain only one canonical beta prerelease`,
    );
  }
  return manifest["."];
}

function exactAnnotatedReplacement(
  parent,
  candidate,
  expression,
  expectedCount,
  parentVersion,
  candidateVersion,
  source,
) {
  let count = 0;
  const expected = parent.replace(expression, (match, version) => {
    count += 1;
    if (version !== parentVersion) {
      refuse(
        "release_content_drift",
        `${source} parent marker disagrees with its manifest`,
      );
    }
    return match.replace(version, candidateVersion);
  });
  if (count !== expectedCount || candidate !== expected) {
    refuse(
      "release_content_drift",
      `${source} may change only its exact annotated version token`,
    );
  }
}

export function verifyReleaseContentDelta(parent, candidate) {
  const parentVersion = canonicalManifest(parent.manifest, "parent");
  const candidateVersion = canonicalManifest(candidate.manifest, "candidate");
  const parts = (version) =>
    version.match(CANONICAL_VERSION).slice(1).map(Number);
  const parentParts = parts(parentVersion);
  const candidateParts = parts(candidateVersion);
  const difference = candidateParts.findIndex(
    (part, index) => part !== parentParts[index],
  );
  if (difference < 0 || candidateParts[difference] < parentParts[difference]) {
    refuse(
      "release_version_not_advanced",
      "Release Please merge must strictly advance the canonical beta version",
    );
  }

  const parentToken = JSON.stringify(parentVersion);
  if (
    parent.manifest.split(parentToken).length !== 2 ||
    candidate.manifest !==
      parent.manifest.replace(parentToken, JSON.stringify(candidateVersion))
  ) {
    refuse(
      "release_content_drift",
      "manifest may change only its canonical version value",
    );
  }

  exactAnnotatedReplacement(
    parent.bootstrap,
    candidate.bootstrap,
    /'package_version'\s*=>\s*'([^']+)'\s*,\s*\/\/ x-release-please-version/g,
    1,
    parentVersion,
    candidateVersion,
    "bootstrap.php",
  );
  exactAnnotatedReplacement(
    parent.artifactService,
    candidate.artifactService,
    /'User-Agent'\s*=>\s*'ran-wp-github-release-updater\/([^']+)'\s*,\s*\/\/ x-release-please-version/g,
    1,
    parentVersion,
    candidateVersion,
    "GitHubReleaseArtifactService.php",
  );
  exactAnnotatedReplacement(
    parent.runtimeTest,
    candidate.runtimeTest,
    /assertSame\(\s*'([^']+)'\s*,\s*\$diagnostics\['selected_version'\]\s*\)\s*;\s*\/\/ x-release-please-version/g,
    2,
    parentVersion,
    candidateVersion,
    "RuntimeTest.php",
  );

  const parentHeading = /^## \[([^\]]+)\]/m.exec(parent.changelog);
  const candidateHeading = /^## \[([^\]]+)\]/m.exec(candidate.changelog);
  if (
    parentHeading?.[1] !== parentVersion ||
    candidateHeading?.[1] !== candidateVersion ||
    parentHeading.index !== candidateHeading.index ||
    parent.changelog.slice(0, parentHeading.index) !==
      candidate.changelog.slice(0, candidateHeading.index)
  ) {
    refuse(
      "release_content_drift",
      "CHANGELOG heading or prefix is not an exact prepend",
    );
  }
  const candidateTail = candidate.changelog.slice(candidateHeading.index);
  const nextHeading = candidateTail.slice(1).search(/^## \[/m);
  if (nextHeading < 0) {
    refuse(
      "release_content_drift",
      "CHANGELOG prepend did not retain prior history",
    );
  }
  const historyOffset = nextHeading + 1;
  const newSection = candidateTail.slice(0, historyOffset);
  if (
    newSection !== `${newSection.trimEnd()}\n\n` ||
    candidateTail.slice(historyOffset) !==
      parent.changelog.slice(parentHeading.index)
  ) {
    refuse(
      "release_content_drift",
      "CHANGELOG prior history must remain byte-identical",
    );
  }

  return { candidateVersion, parentVersion };
}
