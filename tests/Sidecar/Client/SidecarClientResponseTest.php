<?php

declare(strict_types=1);

namespace Reli\Sidecar\Client;

use Reli\BaseTestCase;

class SidecarClientResponseTest extends BaseTestCase
{
    public function testFromJsonOk(): void
    {
        $json = json_encode([
            'status' => 'ok',
            'path' => '/tmp/dump.bin',
            'bytes' => 2048,
            'trace' => ['A::b c.php:1'],
            'error_context' => ['file' => 'x.php', 'line' => 10],
            'memory_stats' => ['memory_usage' => 5000],
        ]);

        $response = SidecarClientResponse::fromJson($json);
        $this->assertNotNull($response);
        $this->assertTrue($response->isOk());
        $this->assertSame('/tmp/dump.bin', $response->path);
        $this->assertSame(2048, $response->bytes);
        $this->assertSame(['A::b c.php:1'], $response->trace);
        $this->assertSame(['file' => 'x.php', 'line' => 10], $response->error_context);
        $this->assertSame(['memory_usage' => 5000], $response->memory_stats);
    }

    public function testFromJsonError(): void
    {
        $json = json_encode(['status' => 'error', 'message' => 'fail']);
        $response = SidecarClientResponse::fromJson($json);

        $this->assertNotNull($response);
        $this->assertFalse($response->isOk());
        $this->assertSame('fail', $response->message);
    }

    public function testFromJsonInvalid(): void
    {
        $this->assertNull(SidecarClientResponse::fromJson('not json'));
    }

    public function testFromJsonMissingStatus(): void
    {
        $this->assertNull(SidecarClientResponse::fromJson(json_encode(['path' => '/tmp'])));
    }

    public function testFromJsonMinimal(): void
    {
        $response = SidecarClientResponse::fromJson(json_encode(['status' => 'ok']));
        $this->assertNotNull($response);
        $this->assertTrue($response->isOk());
        $this->assertNull($response->path);
        $this->assertNull($response->bytes);
        $this->assertSame([], $response->trace);
        $this->assertNull($response->memory_stats);
    }

    public function testProtocolVersionParsed(): void
    {
        $response = SidecarClientResponse::fromJson(
            json_encode(['status' => 'ok', 'protocol_version' => 1]),
        );
        $this->assertNotNull($response);
        $this->assertSame(1, $response->protocol_version);
        $this->assertTrue($response->isCompatible());
    }

    public function testIsCompatibleWithoutVersion(): void
    {
        $response = SidecarClientResponse::fromJson(json_encode(['status' => 'ok']));
        $this->assertNotNull($response);
        $this->assertNull($response->protocol_version);
        $this->assertTrue($response->isCompatible());
    }

    public function testIsCompatibleWithFutureVersion(): void
    {
        $response = SidecarClientResponse::fromJson(
            json_encode(['status' => 'ok', 'protocol_version' => 999]),
        );
        $this->assertNotNull($response);
        $this->assertFalse($response->isCompatible());
    }
}
