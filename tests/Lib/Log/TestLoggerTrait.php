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

namespace Reli\Lib\Log;

use PHPUnit\Framework\TestCase;
use Stringable;

trait TestLoggerTrait
{
    public function __construct(private TestCase $test_case)
    {
    }
    public function emergency(string|Stringable $message, array $context = []): void
    {
    }
    public function alert(string|Stringable $message, array $context = []): void
    {
    }
    public function critical(string|Stringable $message, array $context = []): void
    {
    }
    public function error(string|Stringable $message, array $context = []): void
    {
    }
    public function warning(string|Stringable $message, array $context = []): void
    {
    }
    public function notice(string|Stringable $message, array $context = []): void
    {
    }
    public function info(string|Stringable $message, array $context = []): void
    {
    }
    public function debug(string|Stringable $message, array $context = []): void
    {
    }
    public function log($level, string|Stringable $message, array $context = []): void
    {
    }
}
