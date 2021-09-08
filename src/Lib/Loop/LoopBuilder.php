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

namespace PhpProfiler\Lib\Loop;

use LogicException;

final class LoopBuilder
{
    /** @var array<int, class-string<LoopMiddlewareInterface>> */
    private array $process_stack = [];
    /** @var array<int, array> */
    private array $parameter_stack = [];

    /**
     * @param class-string<LoopMiddlewareInterface> $process
     */
    public function addProcess(string $process, array $parameters): self
    {
        if (!is_a($process, LoopMiddlewareInterface::class, true)) {
            throw new LogicException('1st argument must be a name of a class implements LoopMiddlewareInterface');
        }
        $self = clone $this;
        $self->process_stack[] = $process;
        $self->parameter_stack[] = $parameters;
        return $self;
    }

    public function build(): Loop
    {
        $process = null;
        $stack_num = count($this->process_stack);
        for ($i = $stack_num - 1; $i >= 0; $i--) {
            $parameters = $this->parameter_stack[$i];
            if (!is_null($process)) {
                $parameters[] = $process;
            }
            $loop_class_name = $this->process_stack[$i];
            $process = new $loop_class_name(...$parameters);
        }
        if (is_null($process)) {
            throw new LogicException('no LoopProcess specified');
        }
        return new Loop($process);
    }
}
