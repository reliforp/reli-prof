<?php

declare(strict_types=1);

namespace Reli\Inspector\Sidecar\Protocol;

use Reli\BaseTestCase;

class SidecarRequestTest extends BaseTestCase
{
    public function testFromJsonValidDump(): void
    {
        $json = json_encode(['command' => 'dump', 'pid' => 1234]);
        $request = SidecarRequest::fromJson($json);

        $this->assertNotNull($request);
        $this->assertSame('dump', $request->command);
        $this->assertSame(1234, $request->pid);
        $this->assertNull($request->error_file);
        $this->assertNull($request->error_line);
        $this->assertNull($request->label);
        $this->assertSame([], $request->metadata);
    }

    public function testFromJsonWithAllFields(): void
    {
        $json = json_encode([
            'command' => 'dump',
            'pid' => 5678,
            'file' => '/app/src/Foo.php',
            'line' => 42,
            'label' => 'after-fixtures',
            'metadata' => ['version' => '2.4.0', 'commit' => 'abc123'],
        ]);
        $request = SidecarRequest::fromJson($json);

        $this->assertNotNull($request);
        $this->assertSame(5678, $request->pid);
        $this->assertSame('/app/src/Foo.php', $request->error_file);
        $this->assertSame(42, $request->error_line);
        $this->assertSame('after-fixtures', $request->label);
        $this->assertSame(['version' => '2.4.0', 'commit' => 'abc123'], $request->metadata);
    }

    public function testFromJsonInvalidJson(): void
    {
        $this->assertNull(SidecarRequest::fromJson('not json'));
    }

    public function testFromJsonMissingCommand(): void
    {
        $this->assertNull(SidecarRequest::fromJson(json_encode(['pid' => 1])));
    }

    public function testFromJsonMissingPid(): void
    {
        $this->assertNull(SidecarRequest::fromJson(json_encode(['command' => 'dump'])));
    }

    public function testFromJsonWrongTypes(): void
    {
        $this->assertNull(SidecarRequest::fromJson(json_encode(['command' => 123, 'pid' => 'abc'])));
    }

    public function testFromJsonIgnoresNonStringMetadata(): void
    {
        $json = json_encode([
            'command' => 'dump',
            'pid' => 1,
            'metadata' => ['good' => 'value', 'bad' => 123, 'also_bad' => true],
        ]);
        $request = SidecarRequest::fromJson($json);
        $this->assertNotNull($request);
        $this->assertSame(['good' => 'value'], $request->metadata);
    }
}
