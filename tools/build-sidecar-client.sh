#!/usr/bin/env bash

# Build the standalone reliforp/reli-prof-sidecar-client package artifact.
#
# Pipeline:
#   1. Copy src/Sidecar/Client/*.php (production) into build/sidecar-client/
#   2. Copy src/Sidecar/Client/composer.json into build/sidecar-client/
#   3. Copy tests/Sidecar/Client/*.php into build/sidecar-client/tests/
#   4. Run Rector with rector-sidecar-client.php to downgrade everything
#      under build/sidecar-client/ to PHP 7.0 syntax.
#
# After this script the build/sidecar-client/ tree is the publishable
# artifact: FFI-free, PHP 7.0+ compatible, ready to be force-pushed to
# the read-only mirror repository (reliforp/reli-prof-sidecar-client).
#
# Verification on a real PHP 7.0 runtime is intentionally not part of
# this script — that step belongs in CI where a 7.0 container is
# available.
#
# Usage:
#   tools/build-sidecar-client.sh

set -euo pipefail

cd "$(dirname "$0")/.."

BUILD_DIR=build/sidecar-client
SRC_DIR=src/Sidecar/Client
TEST_DIR=tests/Sidecar/Client
RECTOR_BIN=vendor/bin/rector

if [ ! -x "$RECTOR_BIN" ]; then
  echo "error: $RECTOR_BIN not found. Run: composer install" >&2
  exit 1
fi

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/tests"

find "$SRC_DIR" -maxdepth 1 -type f -name '*.php' -exec cp {} "$BUILD_DIR/" \;
cp "$SRC_DIR/composer.json" "$BUILD_DIR/composer.json"
find "$TEST_DIR" -maxdepth 1 -type f -name '*.php' -exec cp {} "$BUILD_DIR/tests/" \;

"$RECTOR_BIN" process --config=rector-sidecar-client.php --no-progress-bar

echo "built $BUILD_DIR"
