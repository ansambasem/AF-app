---
name: powercollect-check
description: Run PowerCollect website and Flutter mobile checks, show saved results, and investigate failures.
disable-model-invocation: true
argument-hint: "[web|mobile] [apk]"
---

Run the existing project tests and build checks from the repository root. Inspect `.claude/automation/plan.mjs` for the current checks.

- No arguments: `npm run check` (PHP suite, JavaScript and loading regressions, Vite build, Flutter analysis and tests).
- `web`: `npm run check -- --web`.
- `mobile`: `npm run check -- --mobile`.
- `apk`: add `--apk` to include a debug Android build; release signing and publishing remain separate actions.
- `npm run check:status` shows the latest result and log path. A busy, missing, interrupted or failed check is not a pass.

Use only these named arguments; never interpolate arbitrary `$ARGUMENTS` into a shell command. Run the command, wait for completion, inspect every failed step in the saved log, and report website and mobile outcomes separately. Fix regressions caused by the current authorized work and rerun through `npm run check:changed` so database isolation remains active. Report existing unrelated failures without expanding the task. The test runner isolates PHP tests in SQLite memory even when local config is cached. Do not run migrations on the local or hosted application's database.

For changes outside Claude Code, `npm run check:watch` starts a foreground watcher. It runs affected checks after saves with a debounce and one check process at a time. The Claude hooks run affected checks at the end of an editing turn; they do not replace this full check.
