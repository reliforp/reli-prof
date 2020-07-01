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

namespace PhpProfiler\Lib\Process\Search;

use PhpProfiler\Lib\File\CatFileReader;
use PHPUnit\Framework\TestCase;

class ProcessSearcherTest extends TestCase
{
    /** @var resource|null */
    private $child = null;

    public function tearDown(): void
    {
        if (!is_null($this->child)) {
            $child_status = proc_get_status($this->child);
            if (is_array($child_status)) {
                if ($child_status['running']) {
                    posix_kill($child_status['pid'], SIGKILL);
                }
            }
        }
    }

    public function testSearch()
    {
        $this->child = proc_open(
            [
                PHP_BINARY,
                '-r',
                'fputs(STDOUT, "test_ProcessSearcherTest`\n");fgets(STDIN);'
            ],
            [
                ['pipe', 'r'],
                ['pipe', 'w'],
                ['pipe', 'w']
            ],
            $pipes
        );
        fgets($pipes[1]);
        $child_status = proc_get_status($this->child);
        $child_pid = $child_status['pid'];

        $searcher = new ProcessSearcher(new CatFileReader());
        $this->assertSame(
            [$child_pid],
            $searcher->searchByRegex('/test_ProcessSearcherTest/')
        );
    }
}
