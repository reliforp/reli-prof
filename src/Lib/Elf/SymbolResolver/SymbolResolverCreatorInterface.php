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

namespace PhpProfiler\Lib\Elf\SymbolResolver;

use PhpProfiler\Lib\Elf\Parser\ElfParserException;

interface SymbolResolverCreatorInterface
{
    /**
     * @param string $path
     * @return Elf64AllSymbolResolver
     * @throws ElfParserException
     */
    public function createLinearScanResolverFromPath(string $path): Elf64AllSymbolResolver;

    /**
     * @param string $path
     * @return Elf64SymbolResolver
     * @throws ElfParserException
     */
    public function createDynamicResolverFromPath(string $path): Elf64SymbolResolver;
}
