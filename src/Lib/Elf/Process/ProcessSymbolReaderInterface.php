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

namespace PhpProfiler\Lib\Elf\Process;

use FFI\CData;

interface ProcessSymbolReaderInterface
{
    /**
     * @return \FFI\CArray<int>|null
     * @throws ProcessSymbolReaderException
     */
    public function read(string $symbol_name): ?CData;

    /**
     * @throws ProcessSymbolReaderException
     */
    public function resolveAddress(string $symbol_name): ?int;
}
