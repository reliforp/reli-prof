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

trait TestLoggerTrait
{
    public function __construct(private TestCase $test_case)
    {
    }
    public function emergency($message, array $context = [])
    {
    }
    public function alert($message, array $context = [])
    {
    }
    public function critical($message, array $context = [])
    {
    }
    public function error($message, array $context = [])
    {
    }
    public function warning($message, array $context = [])
    {
    }
    public function notice($message, array $context = [])
    {
    }
    public function info($message, array $context = [])
    {
    }
    public function debug($message, array $context = [])
    {
    }
    public function log($message, array $context = [])
    {
    }
}
