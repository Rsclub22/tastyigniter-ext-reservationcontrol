---
name: release
description: Prepare a release - draft the CHANGELOG entry from commits since the last tag, run the checks, propose a tag
disable-model-invocation: true
---

1. `git describe --tags --abbrev=0` (if none, use the first commit) and `git log <tag>..HEAD --oneline`.
2. Run the `check` skill; stop if any gate fails.
3. Turn `## Unreleased` in `CHANGELOG.md` into `## <version> - <date>` (propose the semver bump:
   breaking = major, `### Added` = minor, only `### Fixed`/`### Changed` = patch) and add a fresh empty `## Unreleased`.
   Match the existing entries' style (Fixed / Changed / Added, full explanatory sentences).
4. Show the diff and the proposed `git tag -a vX.Y.Z`. Do NOT commit, tag or push without explicit confirmation.
