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

/**
 * Manual HTTP/1.1 server with SSE fan-out built on stream_socket_server +
 * stream_select. Mirrors the I/O multiplexing style already used by
 * RmemExploreCommand's query child so the live mode stays consistent
 * with the rest of the codebase and adds zero composer dependencies.
 *
 * Endpoints:
 *   GET  /                  → viz HTML (passed in at construction time)
 *   GET  /api/events        → SSE stream of focus / highlight events
 *   POST /api/navigate      → broadcast a focus_node event to all SSE clients
 *
 * @phpstan-type ClientState array{
 *   socket: resource,
 *   buf: string,
 *   mode: 'http'|'sse',
 * }
 */
final class LiveHttpServer
{
    private const READ_CHUNK = 8192;
    private const MAX_REQUEST_BYTES = 1024 * 1024;

    /** @var resource|null */
    private $listener = null;

    /** @var array<int, array{socket: resource, buf: string, mode: 'http'|'sse'}> */
    private array $clients = [];

    private int $nextClientId = 1;

    private bool $running = true;

    /** @var (callable(int): void)|null fired on every POST /api/navigate */
    private $onNavigate = null;

    /** @var (callable(list<int>): void)|null fired on every POST /api/highlight_set */
    private $onHighlightSet = null;

    /**
     * @param callable(string): string|null $broadcastHook  Optional callback
     *        invoked with each broadcast payload (for tests / logging). null
     *        return is ignored.
     */
    public function __construct(
        private readonly string $html,
        private readonly string $bindHost = '127.0.0.1',
        private readonly int $port = 8080,
        private $broadcastHook = null,
    ) {
    }

    public function bind(): string
    {
        $addr = "tcp://{$this->bindHost}:{$this->port}";
        $listener = @stream_socket_server($addr, $errno, $errstr);
        if ($listener === false) {
            throw new \RuntimeException("cannot bind {$addr}: {$errstr}");
        }
        stream_set_blocking($listener, false);
        $this->listener = $listener;
        return $addr;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Install a callback invoked for every successful POST /api/navigate
     * (after the focus_node SSE has been broadcast). Used by the explore
     * bridge child to forward browser / AI navigation back to the TUI.
     *
     * @param callable(int): void $cb
     */
    public function setNavigateCallback(callable $cb): void
    {
        $this->onNavigate = $cb;
    }

    /**
     * Install a callback invoked for every successful POST /api/highlight_set.
     *
     * @param callable(list<int>): void $cb
     */
    public function setHighlightSetCallback(callable $cb): void
    {
        $this->onHighlightSet = $cb;
    }

    public function run(): void
    {
        if ($this->listener === null) {
            $this->bind();
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn () => $this->stop());
            pcntl_signal(SIGINT, fn () => $this->stop());
        }

        while ($this->running) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->listener === null) {
                break;
            }
            $read = $this->getReadStreams();
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 1);
            if ($changed === false || $changed === 0) {
                continue;
            }
            $this->tickForReadable($read);
        }

        $this->close();
    }

    /**
     * Event-loop-friendly view of the streams this server wants to watch:
     * the listening socket (keyed by '__listener') plus every connected
     * HTTP client (keyed by integer id). SSE clients are write-only after
     * upgrade so they are deliberately excluded.
     *
     * @return array<int|string, resource>
     */
    public function getReadStreams(): array
    {
        $streams = [];
        if ($this->listener !== null) {
            $streams['__listener'] = $this->listener;
        }
        foreach ($this->clients as $id => $c) {
            if ($c['mode'] === 'http') {
                $streams[$id] = $c['socket'];
            }
        }
        return $streams;
    }

    /**
     * Handle whichever of this server's streams stream_select reported as
     * readable. Safe to call from an external event loop — pass the same
     * keys that came back from getReadStreams().
     *
     * @param array<int|string, resource> $readable
     */
    public function tickForReadable(array $readable): void
    {
        foreach ($readable as $key => $_sock) {
            if ($key === '__listener') {
                $this->acceptNew();
                continue;
            }
            $this->readClient((int)$key);
        }
    }

    public function close(): void
    {
        foreach ($this->clients as $id => $c) {
            @fclose($c['socket']);
            unset($this->clients[$id]);
        }
        $listener = $this->listener;
        $this->listener = null;
        if ($listener !== null) {
            @fclose($listener);
        }
    }

    /**
     * Push a focus_node event to every connected SSE client. Safe to call
     * from outside the event loop (writes are best-effort and drop dead
     * subscribers silently).
     */
    public function broadcastFocusNode(int $nodeId): int
    {
        return $this->broadcast('focus_node', ['node_id' => $nodeId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function broadcast(string $event, array $data): int
    {
        $payload = "event: {$event}\n"
            . 'data: ' . (string)json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            )
            . "\n\n";

        if (is_callable($this->broadcastHook)) {
            ($this->broadcastHook)($payload);
        }

        $sent = 0;
        foreach ($this->clients as $id => $c) {
            if ($c['mode'] !== 'sse') {
                continue;
            }
            $written = @fwrite($c['socket'], $payload);
            if ($written === false || $written === 0) {
                @fclose($c['socket']);
                unset($this->clients[$id]);
                continue;
            }
            $sent++;
        }
        return $sent;
    }

    public function clientCount(): int
    {
        return count($this->clients);
    }

    private function acceptNew(): void
    {
        if ($this->listener === null) {
            return;
        }
        $client = @stream_socket_accept($this->listener, 0);
        if ($client === false) {
            return;
        }
        stream_set_blocking($client, false);
        $id = $this->nextClientId++;
        $this->clients[$id] = ['socket' => $client, 'buf' => '', 'mode' => 'http'];
    }

    private function readClient(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $client = $this->clients[$id];
        $chunk = @fread($client['socket'], self::READ_CHUNK);
        if ($chunk === false || ($chunk === '' && feof($client['socket']))) {
            @fclose($client['socket']);
            unset($this->clients[$id]);
            return;
        }
        $client['buf'] .= $chunk;
        if (strlen($client['buf']) > self::MAX_REQUEST_BYTES) {
            $this->sendStatus($id, 413, 'request too large');
            return;
        }
        $this->clients[$id] = $client;

        $req = $this->tryParseRequest($client['buf']);
        if ($req === null) {
            return; // wait for more bytes
        }
        $this->dispatch($id, $req);
    }

    /**
     * @return array{method: string, path: string, headers: array<string, string>, body: string}|null
     */
    private function tryParseRequest(string $buf): ?array
    {
        $headerEnd = strpos($buf, "\r\n\r\n");
        if ($headerEnd === false) {
            return null;
        }
        $headerBlock = substr($buf, 0, $headerEnd);
        $bodyStart = $headerEnd + 4;

        $lines = explode("\r\n", $headerBlock);
        $requestLine = array_shift($lines);
        if (!preg_match('#^(GET|POST|PUT|DELETE|OPTIONS|HEAD)\s+(\S+)\s+HTTP/1\.[01]$#', $requestLine, $m)) {
            return ['method' => 'BAD', 'path' => '/', 'headers' => [], 'body' => ''];
        }
        $method = $m[1];
        $path = $m[2];

        $headers = [];
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            $headers[$name] = $value;
        }

        $bodyLen = isset($headers['content-length']) ? (int)$headers['content-length'] : 0;
        if ($bodyLen > 0 && strlen($buf) - $bodyStart < $bodyLen) {
            return null; // wait for full body
        }
        $body = $bodyLen > 0 ? substr($buf, $bodyStart, $bodyLen) : '';

        return ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];
    }

    /** @param array{method: string, path: string, headers: array<string, string>, body: string} $req */
    private function dispatch(int $id, array $req): void
    {
        if ($req['method'] === 'BAD') {
            $this->sendStatus($id, 400, 'bad request');
            return;
        }

        $parsedPath = parse_url($req['path'], PHP_URL_PATH);
        $path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';

        if ($req['method'] === 'GET' && $path === '/') {
            $this->sendResponse($id, 200, [
                'Content-Type' => 'text/html; charset=utf-8',
            ], $this->html);
            return;
        }
        if ($req['method'] === 'GET' && $path === '/api/events') {
            $this->upgradeSse($id);
            return;
        }
        if ($req['method'] === 'POST' && $path === '/api/navigate') {
            /** @var mixed $decoded */
            $decoded = json_decode($req['body'], true);
            if (!is_array($decoded) || !isset($decoded['node_id']) || !is_int($decoded['node_id'])) {
                $this->sendStatus($id, 400, 'expected JSON {"node_id": int}');
                return;
            }
            $this->broadcastFocusNode($decoded['node_id']);
            if ($this->onNavigate !== null) {
                ($this->onNavigate)($decoded['node_id']);
            }
            $this->sendStatus($id, 204, '');
            return;
        }
        if ($req['method'] === 'POST' && $path === '/api/highlight_set') {
            /** @var mixed $decoded */
            $decoded = json_decode($req['body'], true);
            if (!is_array($decoded) || !isset($decoded['node_ids']) || !is_array($decoded['node_ids'])) {
                $this->sendStatus($id, 400, 'expected JSON {"node_ids": [int, ...]}');
                return;
            }
            /** @var list<int> $ids */
            $ids = [];
            /** @var mixed $entry */
            foreach ($decoded['node_ids'] as $entry) {
                if (is_int($entry)) {
                    $ids[] = $entry;
                }
            }
            $this->broadcast('highlight_set', ['node_ids' => $ids]);
            if ($this->onHighlightSet !== null) {
                ($this->onHighlightSet)($ids);
            }
            $this->sendStatus($id, 204, '');
            return;
        }
        $this->sendStatus($id, 404, 'not found');
    }

    private function upgradeSse(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $head = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: text/event-stream\r\n"
            . "Cache-Control: no-cache\r\n"
            . "Connection: keep-alive\r\n"
            . "X-Accel-Buffering: no\r\n"
            . "Access-Control-Allow-Origin: *\r\n"
            . "\r\n"
            . ": connected\n\n";
        $written = @fwrite($this->clients[$id]['socket'], $head);
        if ($written === false) {
            @fclose($this->clients[$id]['socket']);
            unset($this->clients[$id]);
            return;
        }
        $this->clients[$id]['mode'] = 'sse';
        $this->clients[$id]['buf'] = '';
    }

    /**
     * @param array<string, string> $headers
     */
    private function sendResponse(int $id, int $status, array $headers, string $body): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }
        $headers['Content-Length'] = (string)strlen($body);
        $headers['Connection'] = 'close';
        $headers['Access-Control-Allow-Origin'] = $headers['Access-Control-Allow-Origin'] ?? '*';

        $head = "HTTP/1.1 {$status} " . self::statusText($status) . "\r\n";
        foreach ($headers as $k => $v) {
            $head .= "{$k}: {$v}\r\n";
        }
        $head .= "\r\n";
        @fwrite($this->clients[$id]['socket'], $head . $body);
        @fclose($this->clients[$id]['socket']);
        unset($this->clients[$id]);
    }

    private function sendStatus(int $id, int $status, string $message): void
    {
        $this->sendResponse($id, $status, ['Content-Type' => 'text/plain; charset=utf-8'], $message);
    }

    private static function statusText(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            204 => 'No Content',
            400 => 'Bad Request',
            404 => 'Not Found',
            413 => 'Payload Too Large',
            500 => 'Internal Server Error',
            default => 'Status ' . $code,
        };
    }
}
