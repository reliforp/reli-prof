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

use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ArrayContextTreeSink;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ContextAnalyzer\ContextAnalyzer;

final class JsonMemoryOutput implements MemoryOutputInterface
{
    public function __construct(
        private bool $pretty_print = false,
        private ?string $output_path = null,
    ) {
    }

    #[\Override]
    public function output(MemoryAnalysisResult $result): void
    {
        $sink = new ArrayContextTreeSink();
        $analyzer = new ContextAnalyzer();
        $analyzer->analyze($result->context, $sink);

        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($this->pretty_print) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $json = json_encode(
            [
                'summary' => $result->summary,
                'location_types_summary' => $result->location_types_summary ?? [],
                'class_objects_summary' => $result->class_objects_summary ?? [],
                'context' => $sink->getResult(),
            ],
            $flags,
            2147483647
        );
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(json_last_error_msg());
        }

        if ($this->output_path !== null && $json !== false) {
            file_put_contents($this->output_path, $json);
        } elseif ($json !== false) {
            echo $json;
        }
    }
}
