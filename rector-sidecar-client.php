<?php

/**
 * This file is part of the reliforp/reli-prof package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;
use Reli\Tools\Rector\DowngradeConstVisibilityToPhp70Rector;
use Reli\Tools\Rector\DowngradeNullableTypeToPhp70Rector;
use Reli\Tools\Rector\DowngradeVoidReturnToPhp70Rector;

/*
 * Downgrade the bundled sidecar-client tree to PHP 7.0 syntax.
 *
 * This config operates on `build/sidecar-client/`, populated by
 * `tools/build-sidecar-client.sh` from `src/Sidecar/Client/` and
 * `tests/Sidecar/Client/`. The monorepo source itself stays on the
 * project's main PHP target — only the published package artifact
 * is downgraded so users on legacy PHP can still embed the client.
 *
 * Why PHP 7.0:
 *   reli-prof itself supports profiling targets v70..v85, so the
 *   client floor matches the profiler floor for symmetry.
 *
 * Pipeline of transformations:
 *   1. Rector's official downgrade level set covers PHP 8.x -> 7.1
 *      via withDowngradeSets(php71: true).
 *   2. The 7.1 -> 7.0 gap (Rector ships no rules for it) is filled by
 *      our own rules in tools/rector/Downgrade*ToPhp70Rector:
 *        - nullable type hints (?T)  : params get `T = null`, returns get type dropped
 *        - void return type           : dropped
 *        - class const visibility     : private/protected/public stripped
 *   3. The 0o<digits> octal literal syntax (PHP 8.1+) is rewritten by
 *      a sed pass in the build script after Rector finishes — Rector's
 *      pretty-printer preserves the original token kind for unmodified
 *      LNumber nodes, so a textual pass is the simpler fix.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/build/sidecar-client',
    ])
    ->withPhpVersion(PhpVersion::PHP_70)
    ->withDowngradeSets(php71: true)
    ->withRules([
        DowngradeNullableTypeToPhp70Rector::class,
        DowngradeVoidReturnToPhp70Rector::class,
        DowngradeConstVisibilityToPhp70Rector::class,
    ]);
