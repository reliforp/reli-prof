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
use Rector\Set\ValueObject\DowngradeLevelSetList;
use Rector\ValueObject\PhpVersion;

/*
 * Downgrade the bundled sidecar-client tree to PHP 7.0 syntax.
 *
 * This config operates on `build/sidecar-client/`, populated by
 * `tools/build-sidecar-client.sh` from `src/Sidecar/Client/` and
 * `tests/Sidecar/Client/`. The monorepo source itself stays on the
 * project's main PHP target — only the published package artifact
 * is downgraded so users on legacy PHP can still embed the client.
 *
 * The PHP version floor matches the profiler target floor
 * (see `--php-version` option: v70..v85).
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/build/sidecar-client',
    ])
    ->withPhpVersion(PhpVersion::PHP_70)
    ->withSets([
        DowngradeLevelSetList::DOWN_TO_PHP_70,
    ]);
