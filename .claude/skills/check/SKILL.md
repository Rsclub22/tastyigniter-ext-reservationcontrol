---
name: check
description: Run the three CI gates (pest, pint --test, phpstan) in the dev container and summarize failures
disable-model-invocation: true
---

Run these in order from the repo root, stopping to report after all three (do not stop at the first failure):

```bash
export DEV_UID=$(id -u) DEV_GID=$(id -g)
D="docker compose -f docker-compose.dev.yml run --rm php"
$D vendor/bin/pest
$D vendor/bin/pint --test
$D vendor/bin/phpstan analyse --no-progress
```

Report per gate: pass/fail, and for failures the file, line and cause. Do not edit
`phpstan-baseline.neon` to make phpstan pass. Offer `pint` (without `--test`) for style
failures. If phpstan dies with `Child process error (exit code 255)`, the image is stale:
`docker compose -f docker-compose.dev.yml build php`. Finish with `docker compose -f docker-compose.dev.yml down`.
