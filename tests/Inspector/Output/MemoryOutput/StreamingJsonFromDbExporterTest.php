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

namespace Reli\Inspector\Output\MemoryOutput;

use Reli\BaseTestCase;
use Reli\Inspector\Output\MemoryOutput\PdoDriver\SqliteDriver;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\PdoContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendStringMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayHeaderContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\EdgeStrength;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ReferenceContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StringContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\Process\MemoryLocation;

class StreamingJsonFromDbExporterTest extends BaseTestCase
{
    private string $db_path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db_path = tempnam(sys_get_temp_dir(), 'reli_test_') . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->db_path);
        parent::tearDown();
    }

    public function testExportProducesValidJson(): void
    {
        $pdo_output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $context = $this->createTreeContext();
        $result = new MemoryAnalysisResult(
            [['memory_get_usage' => 1024]],
            $context,
        );
        $pdo_output->output($result);

        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $run_id = (int)$db->query('SELECT MAX(run_id) FROM runs')->fetchColumn();

        $exporter = new StreamingJsonFromDbExporter($db, $run_id);
        $fp = fopen('php://memory', 'w+');
        assert($fp !== false);
        $exporter->export(
            [['memory_get_usage' => 1024]],
            ['TestLocation' => ['count' => 1, 'memory_usage' => 32]],
            null,
            $fp,
        );

        rewind($fp);
        $json = stream_get_contents($fp);
        fclose($fp);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded, 'Output must be valid JSON: ' . json_last_error_msg());
        $this->assertArrayHasKey('summary', $decoded);
        $this->assertArrayHasKey('context', $decoded);
        $this->assertArrayHasKey('location_types_summary', $decoded);
        $this->assertArrayHasKey('class_objects_summary', $decoded);
    }

    public function testExportContainsNodeData(): void
    {
        $pdo_output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $str_loc = new ZendStringMemoryLocation(0x1000, 32, 1, 0, 'hello');
        $str_ctx = new StringContext($str_loc);

        $root = \Mockery::mock(ReferenceContext::class);
        $root_memo = null;
        $root->allows('getMemoNodeId')->andReturnUsing(
            static function () use (&$root_memo): ?int {
                return $root_memo;
            }
        );
        $root->allows('setMemoNodeId')->andReturnUsing(
            static function (?int $id) use (&$root_memo): void {
                $root_memo = $id;
            }
        );
        $root->allows('getName')->andReturn('Root');
        $root->allows('getLinks')->andReturn(['child' => $str_ctx]);
        $root->allows('getLocations')->andReturn([]);
        $root->allows('getContexts')->andReturn([]);
        $root->allows('releaseLinks');
        $root->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);

        $wrapper = \Mockery::mock(ReferenceContext::class);
        $wrapper_memo = null;
        $wrapper->allows('getMemoNodeId')->andReturnUsing(
            static function () use (&$wrapper_memo): ?int {
                return $wrapper_memo;
            }
        );
        $wrapper->allows('setMemoNodeId')->andReturnUsing(
            static function (?int $id) use (&$wrapper_memo): void {
                $wrapper_memo = $id;
            }
        );
        $wrapper->allows('getName')->andReturn('Wrapper');
        $wrapper->allows('getLinks')->andReturn(['root' => $root]);
        $wrapper->allows('getLocations')->andReturn([]);
        $wrapper->allows('getContexts')->andReturn([]);
        $wrapper->allows('releaseLinks');
        $wrapper->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);

        $result = new MemoryAnalysisResult([['test' => 1]], $wrapper);
        $pdo_output->output($result);

        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $run_id = (int)$db->query('SELECT MAX(run_id) FROM runs')->fetchColumn();

        $exporter = new StreamingJsonFromDbExporter($db, $run_id);
        $fp = fopen('php://memory', 'w+');
        assert($fp !== false);
        $exporter->export([['test' => 1]], null, null, $fp);

        rewind($fp);
        $json = stream_get_contents($fp);
        fclose($fp);

        $decoded = json_decode($json, true);
        $this->assertNotNull($decoded);
        // The context tree should contain the root node
        $this->assertArrayHasKey('context', $decoded);
        $this->assertNotEmpty($decoded['context']);
    }

    public function testExportWithPrettyPrint(): void
    {
        $pdo_output = new PdoMemoryOutput(new SqliteDriver($this->db_path));
        $result = new MemoryAnalysisResult(
            [['key' => 'value']],
            $this->createTreeContext(),
        );
        $pdo_output->output($result);

        $db = new \PDO("sqlite:{$this->db_path}");
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $run_id = (int)$db->query('SELECT MAX(run_id) FROM runs')->fetchColumn();

        $exporter = new StreamingJsonFromDbExporter($db, $run_id, pretty_print: true);
        $fp = fopen('php://memory', 'w+');
        assert($fp !== false);
        $exporter->export([['key' => 'value']], null, null, $fp);

        rewind($fp);
        $json = stream_get_contents($fp);
        fclose($fp);

        $this->assertStringContainsString("\n", $json);
        $this->assertNotNull(json_decode($json, true));
    }

    private function createTreeContext(): ReferenceContext
    {
        $context = \Mockery::mock(ReferenceContext::class);
        $memo = null;
        $context->allows('getMemoNodeId')->andReturnUsing(
            static function () use (&$memo): ?int {
                return $memo;
            }
        );
        $context->allows('setMemoNodeId')->andReturnUsing(
            static function (?int $id) use (&$memo): void {
                $memo = $id;
            }
        );
        $context->allows('getName')->andReturn('TestContext');
        $context->allows('getLinks')->andReturn([]);
        $context->allows('getLocations')->andReturn([]);
        $context->allows('getContexts')->andReturn([]);
        $context->allows('releaseLinks');
        $context->allows('getLinkStrength')->andReturns(EdgeStrength::Strong);
        return $context;
    }
}
