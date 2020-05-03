<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PhpProfiler\Lib\Elf\Process;

use FFI\CData;

/**
 * Interface ProcessSymbolReaderInterface
 * @package PhpProfiler\Lib\Elf\Process
 */
interface ProcessSymbolReaderInterface
{
    /**
     * @param string $symbol_name
     * @return \FFI\CArray|null
     * @throws ProcessSymbolReaderException
     */
    public function read(string $symbol_name): ?CData;

    /**
     * @param string $symbol_name
     * @return int|null
     * @throws ProcessSymbolReaderException
     */
    public function resolveAddress(string $symbol_name): ?int;
}
