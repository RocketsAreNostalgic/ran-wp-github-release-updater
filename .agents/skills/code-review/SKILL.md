---
name: code-review
description: RAN code-review policy. Use for every pull request or code review. Apply Addy Osmani's latest stable code-review-and-quality skill as the review foundation, then enforce this repository's own instructions, complete verification matrix, security boundaries, and RAN quality standards.
---

# RAN Code Review

Use this skill for every pull request, patch, refactor, dependency change, or implementation review.

## Upstream foundation

Use Addy Osmani's `code-review-and-quality` skill from `addyosmani/agent-skills` as the review foundation. Keep the RAN wrapper and installed project skills in the portable `.agents/skills` directory.

When refreshing the upstream skill, resolve the latest stable GitHub release, preview that exact revision, review it, and install that same revision:

```sh
UPSTREAM=addyosmani/agent-skills
VERSION="$(gh release view --repo "$UPSTREAM" --json tagName --jq '.tagName')"
gh skill preview "$UPSTREAM" "skills/code-review-and-quality@${VERSION}"
# STOP: review the exact preview before installing it.
gh skill install "$UPSTREAM" "skills/code-review-and-quality@${VERSION}" --dir .agents/skills --force
```

Never preview one revision and install another. Do not use an unscoped `gh skill update --force`: it can scan and overwrite user-scoped or other agent-host installations. If an update command is used, constrain it to `.agents/skills` and preview the exact candidate revision first. Treat third-party skill refreshes as separate maintenance changes, not incidental edits in feature/fix PRs.

If the upstream skill cannot be loaded, apply its core axes here: correctness, readability and simplicity, architecture, security, performance, tests, verification evidence, dependency discipline, dead-code hygiene, and sensible change sizing. Approve changes that improve or preserve code health and satisfy the task; do not block on personal style preferences.

## Repository and organization rules are authoritative

Inspect `AGENTS.md`, Copilot/agent instructions, contributing docs, CI workflows, `composer.json`, `package.json`, lockfiles, build/release scripts, and lint/test/static-analysis configuration before reviewing. Existing repository configuration wins over generic preferences.

RAN uses Conventional Commits consistently. Commit subjects must follow `type(scope?): description` (for example, `fix(updater): validate release metadata`), subject to any stricter local rule. Where squash merges derive history from the PR title, the PR title should also be Conventional Commit compatible.

Never invent a conflicting style rule when the repository already has an authoritative formatter, linter, coding-standard, or compatibility configuration.

## Safe and complete verification

At the start of review, record the exact base and head SHAs. Re-check them before the verdict. If either SHA changes, the prior review/verification is stale: review the new range and rerun the affected checks before approving.

Build a verification matrix from repository instructions and CI. A convenient aggregate command such as `composer check` or `pnpm check` is one applicable check, not automatically the whole matrix. Treat it as complete only when its definition demonstrably covers every required lint, format, static-analysis, type-check, test, build, generated-file, packaging, and release check relevant to the change. Otherwise run the aggregate plus the missing checks.

Treat execution as a security boundary. Before running repository scripts or dependency installation from a PR, inspect their definitions and compare executable configuration with the base revision. A PR can redefine `check`, install hooks, build scripts, workflows, or other commands to execute arbitrary code. For external/untrusted changes or PR-modified executable scripts, use trusted CI or an isolated credential-free environment, or obtain explicit human approval before local execution. Do not run untrusted PR code with reviewer credentials or secrets.

Never claim a check passed without evidence. Record what ran, against which head SHA, and what could not be run.

## RAN quality baseline

For substantive PHP/WordPress code, expect the strongest applicable combination of PHPCS with WordPress Coding Standards and PHP compatibility checks, static analysis such as PHPStan where established, PHPUnit/integration/characterization/contract tests, and WordPress security practices: validate and sanitize untrusted input, escape output at the correct boundary, verify nonces where appropriate, enforce capabilities/authorization, use prepared database queries, and treat remote/API data as untrusted. Respect this repository's declared PHP and WordPress compatibility range. Never copy suppressions from another repository without a local justification.

For maintained JavaScript/TypeScript/CSS/Sass, expect the strongest applicable combination of Prettier, ESLint (prefer WordPress rules for WordPress-facing JavaScript where appropriate), Stylelint, TypeScript checking, and asset/unit tests. Follow the package manager and versions declared by this repository.

Do not force irrelevant PHP, JS, CSS, static-analysis, or test tooling onto fixture, template, configuration-only, generated, or minimal repositories. Distinguish a defect in the current change from a repository-level tooling gap. Do not block an unrelated sound change solely for legacy tooling debt, but do block expansion of a risky code surface without reasonable verification.

## Review procedure

1. Capture the exact base/head SHAs and understand the requested behavior, scope, and acceptance criteria.
2. Read tests and verification changes before implementation where practical.
3. Review correctness and security first, including trust boundaries around scripts, workflows, dependencies, and remote data.
4. Review readability, simplicity, architecture, ownership boundaries, and performance.
5. Review dependencies, lockfile changes, licensing implications, and supply-chain risk.
6. Check dead code, duplicate helpers, compatibility shims, and abstractions that merely relocate complexity.
7. Check change/file size. Rough guidance: ~100 changed lines is easy to review, ~300 may be reasonable for one logical change, and ~1000 usually warrants splitting; files around 1000 total lines deserve architectural inspection rather than automatic failure.
8. Build and execute the complete applicable verification matrix safely.
9. Re-check base/head SHAs. If either changed, invalidate the verdict and review the new range.

Prefer structural remedies that remove moving parts: split orchestration from business logic, extract focused modules, collapse duplicate branches, reuse canonical helpers, clarify type boundaries, and delete pass-through abstractions. Keep refactors separate from behavior changes when that makes review safer.

## Findings and verdict

- **Critical:** blocks merge for a security vulnerability, data-loss risk, broken behavior, or similarly severe issue.
- **Required:** must be addressed before merge.
- **Optional / Consider:** worthwhile but non-blocking.
- **Nit:** minor polish; optional, especially where automated tooling is authoritative.
- **FYI:** context only.

Lead with high-leverage findings and give concrete locations, impact, and remedies. Do not accept "clean it up later" for debt introduced by the current change.

Every verdict should record:

```markdown
Reviewed range: <base-sha>...<head-sha>

Required findings:
- None / findings with concrete locations and remedies

Verification:
- commands/checks run or trusted CI observed
- checks not run and why
- trust/isolation constraints that affected execution

Verdict:
- Approve / Request changes
```

Approve only when the reviewed SHA range satisfies its intent, follows repository and RAN conventions, has no unresolved required findings, and has a credible verification story for that exact head SHA.
