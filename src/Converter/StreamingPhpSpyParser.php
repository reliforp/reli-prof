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

namespace Reli\Converter;

/**
 * Incremental phpspy text parser. Bytes arrive from a non-blocking pipe
 * in arbitrary chunks; the parser holds line state across feed() calls
 * and yields a ParsedCallTrace each time it sees a blank line that
 * terminates a non-empty trace.
 *
 * One instance per logical phpspy stream — the daemon mode keeps one
 * parser per target PID so byte-level interleaving across sources is
 * impossible.
 */
final class StreamingPhpSpyParser
{
    private string $byte_buffer = '';

    /** @var array{string, int}[] */
    private array $current_lines = [];

    private int $lineno = 0;

    private PhpSpyCompatibleParser $parser;

    public function __construct()
    {
        $this->parser = new PhpSpyCompatibleParser();
    }

    /**
     * @return iterable<ParsedCallTrace>
     */
    public function feed(string $chunk): iterable
    {
        if ($chunk === '') {
            return;
        }
        $this->byte_buffer .= $chunk;
        while (($newline_pos = strpos($this->byte_buffer, "\n")) !== false) {
            $line = substr($this->byte_buffer, 0, $newline_pos);
            $this->byte_buffer = substr($this->byte_buffer, $newline_pos + 1);
            $this->lineno++;
            $line = rtrim($line, "\r");
            if ($line !== '') {
                $this->current_lines[] = [$line, $this->lineno];
                continue;
            }
            if ($this->current_lines !== []) {
                yield $this->parser->parsePhpSpyCompatible($this->current_lines);
                $this->current_lines = [];
            }
        }
    }

    /**
     * Emit the last in-flight trace if the stream ended without a
     * trailing blank line.
     *
     * @return iterable<ParsedCallTrace>
     */
    public function flush(): iterable
    {
        if ($this->current_lines !== []) {
            yield $this->parser->parsePhpSpyCompatible($this->current_lines);
            $this->current_lines = [];
        }
    }
}
