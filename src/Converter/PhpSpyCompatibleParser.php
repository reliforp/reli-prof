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

final class PhpSpyCompatibleParser
{
    /**
     * @param resource $fp
     * @return iterable<ParsedCallTrace>
     */
    public function parseFile($fp): iterable
    {
        $buffer = [];
        $lineno_before_parse = 0;
        while (($line = fgets($fp)) !== false) {
            $lineno_before_parse++;
            $line = trim($line);
            if ($line !== '') {
                $buffer[] = [$line, $lineno_before_parse];
                continue;
            }
            yield $this->parsePhpSpyCompatible($buffer);
            $buffer = [];
        }
    }

    /** @param array{string, int}[] $buffer */
    private function parsePhpSpyCompatible(array $buffer): ParsedCallTrace
    {
        $frames = [];
        foreach ($buffer as [$line_buffer, $lineno_before_parse]) {
            $result = explode(' ', $line_buffer);
            [$depth, $name, $file_line] = $result;
            if ($depth === '#') { // comment
                continue;
            }
            [$file, $line] = $this->splitLineNumberAndFilePath($file_line, $lineno_before_parse);
            $frames[] = new ParsedCallFrame(
                $name,
                $file,
                $line,
                new PhpSpyCompatibleDataContext($lineno_before_parse),
            );
        }
        return new ParsedCallTrace(...$frames);
    }

    /**
     * @param string $file_line
     * @return array{non-empty-string, int}
     */
    private function splitLineNumberAndFilePath(string $file_line, int $lineno_before_parse): array
    {
        if ($file_line === '') {
            throw new PhpSpyCompatibleParserException(
                'missing line number and file path at line' . $lineno_before_parse,
                $lineno_before_parse,
            );
        }

        $separator_position = strrpos($file_line, ':');
        if ($separator_position === false) {
            throw new PhpSpyCompatibleParserException(
                'missing separator ":" between line number and file path at line ' . $lineno_before_parse,
                $lineno_before_parse,
            );
        }
        $line = substr($file_line, $separator_position + 1);
        $file = substr($file_line, 0, $separator_position);
        if ($file === '') {
            throw new PhpSpyCompatibleParserException(
                'missing file path at line ' . $lineno_before_parse,
                $lineno_before_parse,
            );
        }
        return [$file, Cast::toInt($line)];
    }
}
