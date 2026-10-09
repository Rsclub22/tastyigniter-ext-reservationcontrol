#!/usr/bin/env bash
# PreToolUse: refuse edits to generated files and the frozen PHPStan baseline.
f=$(jq -r '.tool_input.file_path // empty')
case "$f" in
  */vendor/*|*/.phpunit.cache/*|*/phpstan-baseline.neon|*/composer.lock)
    echo "Blocked: $f is generated or frozen (see CLAUDE.md). Ask the user first." >&2
    exit 2 ;;
esac
exit 0
