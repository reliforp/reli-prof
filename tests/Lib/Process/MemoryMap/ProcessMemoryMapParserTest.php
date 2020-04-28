<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\Process\MemoryMap;

use PhpProfiler\Lib\String\LineFetcher;
use PHPUnit\Framework\TestCase;

class ProcessMemoryMapParserTest extends TestCase
{
    public function testParse()
    {
        $test_data = <<<PROC_MAPS
            55fd83849000-55fd8397f000 r--p 00000000 08:07 6829431                    /usr/bin/php
            55fd84049000-55fd84867000 r--p 00800000 08:07 6829431                    /usr/bin/php
            55fd83a49000-55fd83e94000 r-xp 00200000 08:07 6829431                    /usr/bin/php
            55fd84b8d000-55fd84c49000 r--p 01144000 08:07 6829431                    /usr/bin/php
            55fd84c49000-55fd84c4e000 rw-p 01200000 08:07 6829431                    /usr/bin/php
            PROC_MAPS;
        $parser = new ProcessMemoryMapParser(new LineFetcher());
        $result = $parser->parse($test_data);
        $all = $result->findByNameRegex('/.*/');
        $this->assertCount(5, $all);
        $this->assertSame('55fd83849000', $all[0]->begin);
        $this->assertSame('55fd8397f000', $all[0]->end);
        $this->assertSame('00000000', $all[0]->file_offset);
        $this->assertSame('/usr/bin/php', $all[0]->name);
        $this->assertSame(true, $all[0]->attribute->read);
        $this->assertSame(false, $all[0]->attribute->execute);
        $this->assertSame(false, $all[0]->attribute->write);
        $this->assertSame(true, $all[0]->attribute->protected);
        $this->assertSame('55fd83a49000', $all[2]->begin);
        $this->assertSame('55fd83e94000', $all[2]->end);
        $this->assertSame('00200000', $all[2]->file_offset);
        $this->assertSame('/usr/bin/php', $all[2]->name);
        $this->assertSame(true, $all[2]->attribute->read);
        $this->assertSame(true, $all[2]->attribute->execute);
        $this->assertSame(false, $all[2]->attribute->write);
        $this->assertSame(true, $all[2]->attribute->protected);
        $this->assertSame('55fd84c49000', $all[4]->begin);
        $this->assertSame('55fd84c4e000', $all[4]->end);
        $this->assertSame('01200000', $all[4]->file_offset);
        $this->assertSame('/usr/bin/php', $all[4]->name);
        $this->assertSame(true, $all[4]->attribute->read);
        $this->assertSame(false, $all[4]->attribute->execute);
        $this->assertSame(true, $all[4]->attribute->write);
        $this->assertSame(true, $all[4]->attribute->protected);
    }
}
