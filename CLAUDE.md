# Working on slimfony/core

Read [GUIDELINES.md](GUIDELINES.md) before changing anything. Section 6 (Definition of done) is binding for every change, including yours as an AI assistant.

- PHP 8.4+. Every package at its latest stable release: check `composer outdated`, never rely on memory.
- Run `composer ci` before every commit and check its **exit code**. Commit small and often on `main` (trunk-based while the foundation is built), push, and confirm the GitHub Actions run is green.
- `plan.md` and `docs/` are local and gitignored: never commit them.
- New code follows the style in GUIDELINES.md (final, `@api`/`@internal`, typed constants, `#[\Override]`, property hooks for validated settings, injected instances instead of statics, errors from the `SlimfonyException` hierarchy). `tests/ArchitectureTest.php` enforces most of it.
- Keep the library tiny: justify every new dependency or abstraction (GUIDELINES section 1). Prefer deleting code to adding it.
- Tests are few, behavioural and deterministic; reuse `tests/Support`. The README samples are executed by `tests/ReadmeTest.php`: keep them working.
