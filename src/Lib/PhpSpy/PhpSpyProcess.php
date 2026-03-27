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

namespace Reli\Lib\PhpSpy;

use Reli\Inspector\Settings\PhpSpySettings\PhpSpySettings;

final class PhpSpyProcess
{
    /** @var resource|false|null */
    private $process = null;

    /** @var resource|null */
    private $stdout_pipe = null;

    private string $phpspy_path = '';

    public function __construct(
        private PhpSpyFinder $phpspy_finder,
    ) {
    }

    /**
     * @return list<string>
     */
    public function buildArgs(
        int $pid,
        int $eg_address,
        int $depth,
        PhpSpySettings $settings,
    ): array {
        $args = [
            '-p', (string)$pid,
            '-e', '0x' . dechex($eg_address),
            '-n', (string)$depth,
        ];

        if ($settings->sleep_us !== PhpSpySettings::DEFAULT_SLEEP_US) {
            $args[] = '-s';
            $args[] = (string)$settings->sleep_us;
        }

        if ($settings->buffer_size !== PhpSpySettings::DEFAULT_BUFFER_SIZE) {
            $args[] = '-b';
            $args[] = (string)$settings->buffer_size;
        }

        if ($settings->rate_hz !== PhpSpySettings::DEFAULT_RATE_HZ) {
            $args[] = '-H';
            $args[] = (string)$settings->rate_hz;
        }

        return $args;
    }

    /**
     * @param resource $output_stream
     */
    public function start(
        int $pid,
        int $eg_address,
        int $depth,
        PhpSpySettings $settings,
        $output_stream = \STDOUT,
    ): void {
        $this->phpspy_path = $this->phpspy_finder->find($settings->phpspy_path);
        $args = $this->buildArgs($pid, $eg_address, $depth, $settings);

        $command = escapeshellarg($this->phpspy_path);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }

        if ($settings->extra_args !== null) {
            $command .= ' ' . $settings->extra_args;
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $this->process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Failed to start phpspy process: ' . $command);
        }

        // Close stdin - phpspy doesn't need it
        fclose($pipes[0]);
        $this->stdout_pipe = $pipes[1];

        // Set stdout to non-blocking for polling
        stream_set_blocking($this->stdout_pipe, false);

        // Close stderr (or we could log it)
        fclose($pipes[2]);
    }

    /**
     * @return resource|null
     */
    public function getStdoutPipe()
    {
        return $this->stdout_pipe;
    }

    public function isRunning(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        return $status['running'];
    }

    /**
     * Read available output from the phpspy process and write to the output stream.
     *
     * @param resource $output_stream
     * @return bool true if data was written
     */
    public function passthrough($output_stream): bool
    {
        if ($this->stdout_pipe === null) {
            return false;
        }
        $data = stream_get_contents($this->stdout_pipe);
        if ($data !== false && $data !== '') {
            fwrite($output_stream, $data);
            return true;
        }
        return false;
    }

    public function stop(): void
    {
        $pipe = $this->stdout_pipe;
        $this->stdout_pipe = null;
        if (is_resource($pipe)) {
            fclose($pipe);
        }
        if (is_resource($this->process)) {
            // Send SIGTERM first
            proc_terminate($this->process, 15);
            // Give it a moment, then force kill if needed
            $status = proc_get_status($this->process);
            if ($status['running']) {
                usleep(100_000); // 100ms
                proc_terminate($this->process, 9);
            }
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
