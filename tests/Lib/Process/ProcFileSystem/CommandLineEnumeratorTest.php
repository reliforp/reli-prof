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

namespace PhpProfiler\Lib\Process\ProcFileSystem;

use PhpProfiler\Lib\File\NativeFileReader;
use PHPUnit\Framework\TestCase;

class CommandLineEnumeratorTest extends TestCase
{
    /** @var resource|null */
    private $child = null;

    protected function tearDown(): void
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

    public function testGetIterator()
    {
        $this->child = proc_open(
            [
                PHP_BINARY,
                '-r',
                'fputs(STDOUT, "a\n");fgets(STDIN);'
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

        $target = null;
        foreach (new CommandLineEnumerator(new NativeFileReader()) as $pid => $command_line) {
            if ($pid === $child_pid) {
                $target = $command_line;
                break;
            }
        }
        $this->assertNotNull($target);
        $this->assertStringContainsString(PHP_BINARY, $target);
        $this->assertStringContainsString('fputs(STDOUT, "a\n");fgets(STDIN);', $target);
    }
}
