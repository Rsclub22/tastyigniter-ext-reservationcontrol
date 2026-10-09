#!/usr/bin/env bash
# PostToolUse: format an edited PHP file with Pint inside the dev container.
f=$(jq -r '.tool_input.file_path // empty')
[[ "$f" == *.php ]] || exit 0
[[ "$f" == */vendor/* ]] && exit 0
cd "$CLAUDE_PROJECT_DIR" || exit 0
rel="${f#"$CLAUDE_PROJECT_DIR"/}"
DEV_UID=$(id -u) DEV_GID=$(id -g) docker compose -f docker-compose.dev.yml run --rm --no-deps -T php vendor/bin/pint --quiet "$rel" >/dev/null 2>&1
exit 0
