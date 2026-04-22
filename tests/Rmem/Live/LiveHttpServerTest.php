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

namespace Reli\Rmem\Live;

use PHPUnit\Framework\TestCase;

class LiveHttpServerTest extends TestCase
{
    private LiveHttpServer $server;
    private int $port = 0;
    /** @var resource */
    private $listener;

    #[\Override]
    protected function setUp(): void
    {
        // Bind on an ephemeral port and drive the server by hand through
        // its tick interface; this lets us exercise request parsing,
        // routing, gzip, and SSE without spawning a process.
        $this->server = new LiveHttpServer('<html>ok</html>', '127.0.0.1', 0);
        $addr = $this->server->bind();
        self::assertMatchesRegularExpression('#tcp://127\.0\.0\.1:\d+#', $addr);
        // Extract the actual port from the listening socket.
        $listenerSocket = $this->getListenerSocket();
        $name = stream_socket_get_name($listenerSocket, false);
        self::assertNotFalse($name);
        $parts = explode(':', $name);
        $this->port = (int)end($parts);
        self::assertGreaterThan(0, $this->port);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->server->close();
    }

    public function testGetRootServesHtml(): void
    {
        $resp = $this->requestAndTick("GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 200 OK', $resp);
        self::assertStringContainsString("Content-Type: text/html; charset=utf-8", $resp);
        self::assertStringContainsString('<html>ok</html>', $resp);
        // Body must be the actual length (no truncation).
        self::assertMatchesRegularExpression('#Content-Length: 15\r\n#', $resp);
    }

    public function testGzipWhenClientAcceptsAndBodyLargeEnough(): void
    {
        // Body small (15 bytes) — must NOT gzip (threshold is 1024).
        $plain = $this->requestAndTick(
            "GET / HTTP/1.1\r\nHost: x\r\nAccept-Encoding: gzip\r\n\r\n",
        );
        self::assertStringNotContainsStringIgnoringCase('Content-Encoding:', $plain);

        // Rebuild with a large body and verify gzip kicks in.
        $this->server->close();
        $bigBody = str_repeat('A', 4096);
        $this->server = new LiveHttpServer($bigBody, '127.0.0.1', 0);
        $this->server->bind();
        $listenerSocket = $this->getListenerSocket();
        $name = stream_socket_get_name($listenerSocket, false);
        self::assertNotFalse($name);
        $parts = explode(':', $name);
        $this->port = (int)end($parts);

        $gzipped = $this->requestAndTick(
            "GET / HTTP/1.1\r\nHost: x\r\nAccept-Encoding: gzip\r\n\r\n",
        );
        self::assertStringContainsString('Content-Encoding: gzip', $gzipped);
        // Strip headers; the body must decompress back to 4096 bytes.
        $bodyOffset = strpos($gzipped, "\r\n\r\n");
        self::assertNotFalse($bodyOffset);
        $body = substr($gzipped, $bodyOffset + 4);
        $decoded = @gzdecode($body);
        self::assertSame(4096, strlen($decoded ?: ''));
    }

    public function testPostNavigateBroadcastsFocusNode(): void
    {
        $captured = [];
        $this->server->setBroadcastHook(static function (string $payload) use (&$captured): void {
            $captured[] = $payload;
        });

        $status = $this->requestAndTick(
            "POST /api/navigate HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\n"
            . "Content-Length: 16\r\n\r\n"
            . '{"node_id":1234}',
        );
        self::assertStringStartsWith('HTTP/1.1 204', $status);
        self::assertCount(1, $captured);
        self::assertStringContainsString('event: focus_node', $captured[0]);
        self::assertStringContainsString('"node_id":1234', $captured[0]);
    }

    public function testPostNavigateRejectsMalformedBody(): void
    {
        $resp = $this->requestAndTick(
            "POST /api/navigate HTTP/1.1\r\nHost: x\r\nContent-Length: 7\r\n\r\n"
            . 'garbage',
        );
        self::assertStringStartsWith('HTTP/1.1 400', $resp);
    }

    public function testNavigateCallbackFiresAfterBroadcast(): void
    {
        $captured = null;
        $this->server->setNavigateCallback(static function (int $nodeId) use (&$captured): void {
            $captured = $nodeId;
        });

        $this->requestAndTick(
            "POST /api/navigate HTTP/1.1\r\nContent-Length: 14\r\n\r\n"
            . '{"node_id":42}',
        );
        self::assertSame(42, $captured);
    }

    public function testPostHighlightSetBroadcasts(): void
    {
        $captured = [];
        $this->server->setBroadcastHook(static function (string $payload) use (&$captured): void {
            $captured[] = $payload;
        });
        $capturedIds = null;
        $this->server->setHighlightSetCallback(static function (array $ids) use (&$capturedIds): void {
            $capturedIds = $ids;
        });

        $body = '{"node_ids":[1,2,3,"ignored",null,4]}';
        $resp = $this->requestAndTick(
            "POST /api/highlight_set HTTP/1.1\r\nContent-Length: "
            . strlen($body) . "\r\n\r\n" . $body,
        );
        self::assertStringStartsWith('HTTP/1.1 204', $resp);
        self::assertCount(1, $captured);
        self::assertStringContainsString('event: highlight_set', $captured[0]);
        // Non-integer entries are filtered out.
        self::assertSame([1, 2, 3, 4], $capturedIds);
    }

    public function testFocusEnricherAugmentsPayload(): void
    {
        $this->server->setFocusEnricher(static fn (int $id): array => [
            'path_node_ids' => [0, 5, 10, $id],
            'label' => "node $id",
        ]);
        $captured = [];
        $this->server->setBroadcastHook(static function (string $payload) use (&$captured): void {
            $captured[] = $payload;
        });

        $this->requestAndTick(
            "POST /api/navigate HTTP/1.1\r\nContent-Length: 13\r\n\r\n"
            . '{"node_id":7}',
        );
        self::assertCount(1, $captured);
        self::assertStringContainsString('"node_id":7', $captured[0]);
        self::assertStringContainsString('"path_node_ids":[0,5,10,7]', $captured[0]);
        self::assertStringContainsString('"label":"node 7"', $captured[0]);
    }

    public function testUnknownRouteReturns404(): void
    {
        $resp = $this->requestAndTick("GET /nope HTTP/1.1\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 404', $resp);
    }

    public function testMalformedRequestLineReturns400(): void
    {
        $resp = $this->requestAndTick("garbage\r\n\r\n");
        self::assertStringStartsWith('HTTP/1.1 400', $resp);
    }

    public function testGetReadStreamsIncludesListener(): void
    {
        $streams = $this->server->getReadStreams();
        self::assertArrayHasKey('__listener', $streams);
    }

    public function testLargeBodyIsWrittenEntirely(): void
    {
        // Rebuild with a body bigger than the kernel's default send
        // buffer (~64 KB on Linux). The pre-fix sendResponse wrote only
        // the first chunk; the fix blocks until every byte flushes.
        $this->server->close();
        $bigBody = str_repeat('X', 256 * 1024);
        $this->server = new LiveHttpServer($bigBody, '127.0.0.1', 0);
        $this->server->bind();
        $listenerSocket = $this->getListenerSocket();
        $name = stream_socket_get_name($listenerSocket, false);
        self::assertNotFalse($name);
        $parts = explode(':', $name);
        $this->port = (int)end($parts);

        $resp = $this->requestAndTick(
            "GET / HTTP/1.1\r\nHost: x\r\n\r\n",
            readBytes: 300 * 1024,
        );
        $bodyOffset = strpos($resp, "\r\n\r\n");
        self::assertNotFalse($bodyOffset);
        self::assertSame(256 * 1024, strlen(substr($resp, $bodyOffset + 4)));
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Open a TCP client, send the raw request, drive the server's
     * event loop until it writes a response, then read it back.
     */
    private function requestAndTick(string $rawRequest, int $readBytes = 65536): string
    {
        $client = @stream_socket_client(
            "tcp://127.0.0.1:{$this->port}",
            $errno,
            $errstr,
            timeout: 2.0,
        );
        self::assertNotFalse($client, "client connect: {$errstr}");
        stream_set_blocking($client, true);

        // Listener needs a tick to accept the pending connection.
        $this->server->tickForReadable(['__listener' => $this->getListenerSocket()]);

        // Write the request (server side is now blocking on select for
        // the new client socket).
        self::assertIsInt(fwrite($client, $rawRequest));

        // Let the server drain the client buffer and respond. The loop
        // may need a couple of ticks for larger payloads.
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline) {
            $streams = $this->server->getReadStreams();
            unset($streams['__listener']);
            if ($streams === []) {
                break;
            }
            $read = $streams;
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 50_000);
            if ($changed > 0) {
                $this->server->tickForReadable($read);
            }
        }

        stream_set_timeout($client, 1, 0);
        $resp = '';
        while (!feof($client)) {
            $chunk = fread($client, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $resp .= $chunk;
            if (strlen($resp) >= $readBytes) {
                break;
            }
        }
        fclose($client);
        return $resp;
    }

    /**
     * @return resource
     */
    private function getListenerSocket()
    {
        $r = new \ReflectionClass($this->server);
        $p = $r->getProperty('listener');
        $p->setAccessible(true);
        /** @var resource $sock */
        $sock = $p->getValue($this->server);
        return $sock;
    }
}
