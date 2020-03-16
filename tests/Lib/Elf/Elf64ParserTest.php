<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Elf;


use PhpProfiler\Lib\Binary\BinaryReader;
use PhpProfiler\ProcessReader\PhpBinaryFinder;
use PHPUnit\Framework\TestCase;

class Elf64ParserTest extends TestCase
{

    public function testParseElfHeader()
    {
        $parser = new Elf64Parser(new BinaryReader());
        $php_binary = file_get_contents((new PhpBinaryFinder())->findByProcessId(getmypid()));
        $elf_header = $parser->parseElfHeader($php_binary);
        var_dump($elf_header);
    }
}
