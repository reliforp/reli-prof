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

namespace Reli\Inspector\TargetProcess;

use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettings;
use Reli\Lib\Process\Exec\TraceeExecutor;
use PHPUnit\Framework\TestCase;

class TargetProcessResolverTest extends TestCase
{
    public function testResolve()
    {
        $tracee_executor = \Mockery::mock(TraceeExecutor::class);
        $resolver = new TargetProcessResolver($tracee_executor);
        $process_specifier = $resolver->resolve(new TargetProcessSettings(123));
        $this->assertSame(123, $process_specifier->pid);

        $tracee_executor->expects()->execute('command', ['arg1', 'arg2'])->andReturns(456);
        $process_specifier = $resolver->resolve(
            new TargetProcessSettings(null, 'command', ['arg1', 'arg2'])
        );
        $this->assertSame(456, $process_specifier->pid);
    }
}
