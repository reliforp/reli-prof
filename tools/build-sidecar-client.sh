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

# PHP 8.1+ explicit-octal literal (0o700) -> PHP 7.0 implicit-octal (0700).
# php-parser's pretty-printer preserves the original token kind for unmodified
# LNumber nodes, so a textual pass is the simpler fix than a Rector rule.
find "$BUILD_DIR" -type f -name '*.php' -exec sed -i -E 's/\b0[oO]([0-7]+)\b/0\1/g' {} +

# Replace the monorepo-internal `Reli\BaseTestCase` (extends MockeryTestCase
# and adds trace logging used only inside the parent project) with vanilla
# `PHPUnit\Framework\TestCase` so the standalone artifact's tests run with
# nothing more than phpunit available — no Reli internals required.
find "$BUILD_DIR/tests" -type f -name '*.php' -exec sed -i \
    -e 's|use Reli\\BaseTestCase;|use PHPUnit\\Framework\\TestCase;|g' \
    -e 's|extends BaseTestCase|extends TestCase|g' \
    -e 's|expectExceptionMessageMatches|expectExceptionMessageRegExp|g' {} +

echo "built $BUILD_DIR"
