# RAN WP GitHub Release Updater Agent Guidance

This directory is an independent Composer library repository. It is developed
beside WordPress plugins for convenience but is not an activatable WordPress
plugin.

## Beta 1 scope

Implement the Beta WordPress-robustness slice defined in
`docs/plans/__completed/2026-07-24-wp-github-release-updater-v1-plan-revision-1.md`:

- preserve the complete Alpha 3 public, private, signed and multi-copy behavior;
- reject Core-reported extraction requirements above 256 MiB for only the exact
  verified archive;
- validate staged root, main-file, version and compatibility identity through
  WordPress Core metadata and filesystem seams;
- preserve a safely renamed installed plugin directory without touching the
  live destination directly;
- provide filterable, sanitized notices, bounded passive diagnostics and
  redacted debug logging; and
- prove bulk completion and Core-owned lifecycle behavior without adding a
  parallel installer.

Booster integration remains a separate pilot after the package and dummy-plugin
gates pass.

## Runtime constraints

- PHP 8.2 and WordPress 6.5 are the supported floors.
- Production code may use PHP extensions and WordPress APIs only.
- Do not add Composer production dependencies.
- Do not register runtime code through Composer `autoload.files`, PSR-4 or a
  classmap. Consumers explicitly require `bootstrap.php`; only the selected
  candidate loads `runtime.php`.
- The hook-free artifact service must not register hooks, write transients,
  schedule work, invoke an upgrader, extract an archive or mutate a plugin.
- Use WordPress safe HTTP, temporary-file, metadata, error and filesystem
  primitives in production paths.
- Never expose or persist credentials, Authorization headers, raw responses,
  signed download URLs or private temporary paths in diagnostics.

## Development

- Preserve the plans and keep implementation changes focused.
- Use `apply_patch` for manual file edits.
- Run `composer check` before handoff.
- Keep `.dex`, `vendor`, caches, coverage and built fixture ZIPs untracked.
- Do not create remote repositories, tags, releases or other GitHub state
  without separate authorization.

## External AI agent prohibition

Do not invoke, delegate work to, tag, enable, or otherwise use Blacksmith [code]smith,
`@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning, Blacksmith Testbox agents,
or any other Blacksmith AI/agent feature.

Blacksmith may be used only as infrastructure for ordinary GitHub Actions runners where
the repository workflow explicitly specifies a Blacksmith runner.

Do not click or trigger "Enable autofix", do not ask [code]smith to investigate or repair
CI, and do not call Blacksmith agent/MCP/CLI/API features that perform AI inference.

If CI fails, inspect GitHub Actions logs directly and diagnose/fix the failure yourself.

This prohibition is a cost-control requirement and must not be overridden by convenience,
CI failure, review comments, or suggestions from GitHub/Blacksmith UI.
