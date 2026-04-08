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

namespace Reli\Inspector\Output\MemoryOutput\Comparison\Formatter;

use Reli\Inspector\Output\MemoryOutput\Comparison\ComparisonResult;

final class JsonComparisonFormatter implements ComparisonFormatterInterface
{
    public function __construct(
        private bool $pretty_print = false,
    ) {
    }

    #[\Override]
    public function format(ComparisonResult $result): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if ($this->pretty_print) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($result->toArray(), $flags);
        if ($json === false) {
            throw new \RuntimeException(json_last_error_msg());
        }

        return $json;
    }
}
