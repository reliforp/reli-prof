<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Elf\SymbolResolver;

use PhpProfiler\Lib\Binary\BinaryReader;
use PhpProfiler\Lib\Binary\StringByteReader;
use PhpProfiler\Lib\Elf\Parser\Elf64Parser;
use PhpProfiler\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use PHPUnit\Framework\TestCase;

class Elf64DynamicSymbolResolverTest extends TestCase
{

    public function testLoad()
    {
        $php_binary = new StringByteReader(
            file_get_contents(__DIR__ . '/../Parser/TestCase/test000.so')
        );
        $parser = new Elf64Parser(new BinaryReader());
        $resolver = Elf64DynamicSymbolResolver::load($parser, $php_binary);
        $main = $resolver->resolve('main');
        $this->assertFalse($main->isUndefined());
        $this->assertSame(Elf64SymbolTableEntry::STT_FUNC, $main->getType());
    }
}
