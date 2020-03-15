<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace PhpProfiler\ProcessReader;


use PhpProfiler\Lib\String\LineFetcher;
use PHPUnit\Framework\TestCase;

class ProcessMemoryMapParserTest extends TestCase
{
    public function testParse()
    {

        $reader = new ProcessMemoryMapReader();
        $parser = new ProcessMemoryMapParser(new LineFetcher());
        $result = $parser->parse($reader->read(getmypid()));
        var_dump($result);
    }
}