#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
export NODE_PATH="${NODE_PATH:-$(npm root --global)}"
exec node "$root/tests/Browser/run.mjs"
