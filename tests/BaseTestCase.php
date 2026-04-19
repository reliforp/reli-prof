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

namespace Reli;

use Mockery\Adapter\Phpunit\MockeryTestCase;

class BaseTestCase extends MockeryTestCase
{
    private const TRACE_LOG_PATH = '/tmp/reli-test/reli-test-trace.log';

    /**
     * Append a test lifecycle marker to a shared trace file so that when
     * an isolated child process dies without writing phpunit's result
     * marker ("Test was run in child process and ended unexpectedly"), we
     * can still see which test / which step it was on from the uploaded
     * artifact.
     *
     * Note: write to a file rather than STDERR because phpunit 12 turns
     * any non-empty child STDERR into an Exception.
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::traceLog('SETUP', $this->getTestIdentifier());
        register_shutdown_function(function (): void {
            $err = error_get_last();
            $is_fatal = $err !== null && in_array(
                $err['type'] ?? 0,
                [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR],
                true,
            );
            self::traceLog(
                $is_fatal ? 'SHUTDOWN_FATAL' : 'SHUTDOWN',
                $this->getTestIdentifier()
                    . ($is_fatal ? ' err=' . substr((string)($err['message'] ?? ''), 0, 200) : ''),
            );
        });
    }

    protected function tearDown(): void
    {
        self::traceLog('TEARDOWN', $this->getTestIdentifier());
        parent::tearDown();
    }

    private function getTestIdentifier(): string
    {
        return method_exists($this, 'name') ? (string)$this->name() : static::class;
    }

    private static function traceLog(string $phase, string $detail): void
    {
        $line = sprintf(
            "[%s] pid=%d %s %s\n",
            date('c'),
            getmypid(),
            $phase,
            $detail,
        );
        @file_put_contents(self::TRACE_LOG_PATH, $line, FILE_APPEND);
    }
}
