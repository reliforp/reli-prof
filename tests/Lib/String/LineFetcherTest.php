<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpProfiler\Lib\String;


use PHPUnit\Framework\TestCase;

/**
 * Class LineFetcherTest
 * @package PhpProfiler\Lib\String
 */
class LineFetcherTest extends TestCase
{
    public function testCreateGenerator()
    {
        $string = <<<STR
        hoge
        hige
        huge
        STR;
        $result = [];
        $line_fetcher = new LineFetcher();
        foreach($line_fetcher->createIterable($string) as $line) {
            $result[] = $line;
        }
        $this->assertSame(
            [
                'hoge',
                'hige',
                'huge',
            ],
            $result
        );
    }
}
