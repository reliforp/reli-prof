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

namespace Reli\Lib\Elf\SymbolResolver;

use Reli\Lib\ByteStream\IntegerByteSequence\LittleEndianReader;
use Reli\Lib\ByteStream\StringByteReader;
use Reli\Lib\Elf\Parser\Elf64Parser;
use Reli\Lib\Elf\Structure\Elf64\Elf64SymbolTableEntry;
use PHPUnit\Framework\TestCase;

class Elf64DynamicSymbolResolverTest extends TestCase
{
    public function testLoad()
    {
        $php_binary = new StringByteReader(
            file_get_contents(__DIR__ . '/../Parser/TestCase/test000.so')
        );
        $parser = new Elf64Parser(new LittleEndianReader());
        $resolver = Elf64DynamicSymbolResolver::load($parser, $php_binary);
        $main = $resolver->resolve('main');
        $this->assertFalse($main->isUndefined());
        $this->assertSame(Elf64SymbolTableEntry::STT_FUNC, $main->getType());
    }
}
