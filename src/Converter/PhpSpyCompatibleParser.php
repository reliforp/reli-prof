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

use PhpCast\Cast;
use Webmozart\Assert\Assert;

final class PhpSpyCompatibleParser
{
    /**
     * @param resource $fp
     * @return iterable<ParsedCallTrace>
     */
    public function parseFile($fp): iterable
    {
        $buffer = [];
        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line !== '') {
                $buffer[] = $line;
                continue;
            }
            yield $this->parsePhpSpyCompatible($buffer);
            $buffer = [];
        }
    }

    /** @param string[] $buffer */
    private function parsePhpSpyCompatible(array $buffer): ParsedCallTrace
    {
        $frames = [];
        foreach ($buffer as $line_buffer) {
            $result = explode(' ', $line_buffer);
            [$depth, $name, $file_line] = $result;
            if ($depth === '#') { // comment
                continue;
            }
            Assert::stringNotEmpty($file_line);
            [$file, $line] = $this->splitLineNumberAndFilePath($file_line);
            $frames[] = new ParsedCallFrame(
                $name,
                $file,
                $line,
            );
        }
        return new ParsedCallTrace(...$frames);
    }

    /**
     * @param non-empty-string $file_line
     * @return array{non-empty-string, int}
     */
    private function splitLineNumberAndFilePath(string $file_line): array
    {
        $separator_position = strrpos($file_line, ':');
        Assert::notFalse($separator_position);
        $line = substr($file_line, $separator_position + 1);
        $file = substr($file_line, 0, $separator_position);
        Assert::stringNotEmpty($file);
        return [$file, Cast::toInt($line)];
    }
}
