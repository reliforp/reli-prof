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

namespace Reli\Command\PhpSpy;

use Reli\Converter\BinaryTrace\BinaryTraceWriter;
use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettings;

/**
 * Owns the lifecycle of an rbt output stream for phpspy:trace and
 * phpspy:daemon: opens the destination (file or stdout), optionally
 * routes through a php://temp buffer for gzip compression, and
 * finalises the rbt stream (checkpoint + segment_end) on close.
 */
final class PhpSpyRbtSink
{
    /** @var resource */
    private $writer_stream;

    /** @var resource|null */
    private $final_stream;

    /** @var resource|null */
    private $temp_buffer = null;

    private bool $owns_final_stream;

    private BinaryTraceWriter $writer;

    private bool $closed = false;

    public function __construct(
        ?string $output_path,
        bool $compress,
        int $sampling_period_us,
        bool $has_timestamps = false,
    ) {
        if ($output_path === null) {
            $this->final_stream = \STDOUT;
            $this->owns_final_stream = false;
        } else {
            $stream = fopen($output_path, 'w');
            if ($stream === false) {
                throw new \RuntimeException('Failed to open output file: ' . $output_path);
            }
            $this->final_stream = $stream;
            $this->owns_final_stream = true;
        }

        if ($compress) {
            $buffer = fopen('php://temp', 'r+');
            assert($buffer !== false);
            $this->temp_buffer = $buffer;
            $this->writer_stream = $buffer;
        } else {
            $this->writer_stream = $this->final_stream;
        }

        $this->writer = new BinaryTraceWriter(
            $this->writer_stream,
            $sampling_period_us,
            $has_timestamps,
        );
        $this->writer->writeHeader();
    }

    public function getWriter(): BinaryTraceWriter
    {
        return $this->writer;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        $this->writer->writeCheckpoint();
        $this->writer->writeSegmentEnd();

        $final = $this->final_stream;
        assert($final !== null);

        if ($this->temp_buffer !== null) {
            $temp = $this->temp_buffer;
            $this->temp_buffer = null;
            rewind($temp);
            $raw = stream_get_contents($temp);
            fclose($temp);
            assert($raw !== false);
            $compressed = gzencode($raw);
            if ($compressed === false) {
                throw new \RuntimeException('Failed to gzip-compress rbt output');
            }
            fwrite($final, $compressed);
        }

        if ($this->owns_final_stream) {
            fclose($final);
        }
        $this->final_stream = null;
    }

    public static function derivePeriodUs(PhpSpySettings $settings): int
    {
        if ($settings->sleep_ns !== PhpSpySettings::DEFAULT_SLEEP_NS) {
            return max(1, intdiv($settings->sleep_ns, 1000));
        }
        if ($settings->rate_hz <= 0) {
            return 10000;
        }
        return max(1, (int) round(1_000_000 / $settings->rate_hz));
    }
}
