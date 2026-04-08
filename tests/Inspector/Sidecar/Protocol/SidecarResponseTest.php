<?php

declare(strict_types=1);

namespace Reli\Inspector\Sidecar\Protocol;

use Reli\BaseTestCase;

class SidecarResponseTest extends BaseTestCase
{
    public function testOkResponse(): void
    {
        $response = SidecarResponse::ok(
            path: '/tmp/dump.bin',
            bytes: 1024,
            trace: ['func1 file.php:10'],
            error_context: ['file' => 'a.php', 'line' => 5],
            memory_stats: ['memory_usage' => 1000, 'rss' => 2000],
        );

        $this->assertSame('ok', $response->status);
        $this->assertSame('/tmp/dump.bin', $response->path);
        $this->assertSame(1024, $response->bytes);
        $this->assertSame(['func1 file.php:10'], $response->trace);
        $this->assertSame(['file' => 'a.php', 'line' => 5], $response->error_context);
        $this->assertSame(['memory_usage' => 1000, 'rss' => 2000], $response->memory_stats);
        $this->assertNull($response->message);
    }

    public function testErrorResponse(): void
    {
        $response = SidecarResponse::error('process not found');

        $this->assertSame('error', $response->status);
        $this->assertSame('process not found', $response->message);
        $this->assertNull($response->path);
    }

    public function testToJsonOk(): void
    {
        $response = SidecarResponse::ok('/tmp/d.bin', 512);
        $decoded = json_decode($response->toJson(), true);

        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('/tmp/d.bin', $decoded['path']);
        $this->assertSame(512, $decoded['bytes']);
        $this->assertArrayNotHasKey('message', $decoded);
        $this->assertArrayNotHasKey('trace', $decoded);
    }

    public function testToJsonError(): void
    {
        $response = SidecarResponse::error('boom');
        $decoded = json_decode($response->toJson(), true);

        $this->assertSame('error', $decoded['status']);
        $this->assertSame('boom', $decoded['message']);
        $this->assertArrayNotHasKey('path', $decoded);
    }

    public function testToJsonIncludesAllFieldsWhenSet(): void
    {
        $response = SidecarResponse::ok(
            '/p',
            1,
            ['trace line'],
            ['file' => 'x'],
            ['memory_usage' => 100],
        );
        $decoded = json_decode($response->toJson(), true);

        $this->assertArrayHasKey('trace', $decoded);
        $this->assertArrayHasKey('error_context', $decoded);
        $this->assertArrayHasKey('memory_stats', $decoded);
    }
}
