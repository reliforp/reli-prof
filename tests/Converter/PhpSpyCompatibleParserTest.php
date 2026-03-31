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

use Reli\BaseTestCase;

class PhpSpyCompatibleParserTest extends BaseTestCase
{
    /**
     * @return resource
     */
    private function createStream(string $content)
    {
        $fp = fopen('php://memory', 'r+');
        fwrite($fp, $content);
        rewind($fp);
        return $fp;
    }

    public function testParseSingleTrace(): void
    {
        $input = "0 func_a /path/to/file.php:10\n1 func_b /path/to/file.php:20\n\n";
        $parser = new PhpSpyCompatibleParser();
        $traces = iterator_to_array($parser->parseFile($this->createStream($input)));

        $this->assertCount(1, $traces);
        $trace = $traces[0];
        $this->assertCount(2, $trace->call_frames);

        $this->assertSame('func_a', $trace->call_frames[0]->function_name);
        $this->assertSame('/path/to/file.php', $trace->call_frames[0]->file_name);
        $this->assertSame(10, $trace->call_frames[0]->lineno);

        $this->assertSame('func_b', $trace->call_frames[1]->function_name);
        $this->assertSame('/path/to/file.php', $trace->call_frames[1]->file_name);
        $this->assertSame(20, $trace->call_frames[1]->lineno);
    }

    public function testParseMultipleTraces(): void
    {
        $input = "0 func_a /path/to/a.php:1\n\n0 func_b /path/to/b.php:2\n\n";
        $parser = new PhpSpyCompatibleParser();
        $traces = iterator_to_array($parser->parseFile($this->createStream($input)));

        $this->assertCount(2, $traces);
        $this->assertSame('func_a', $traces[0]->call_frames[0]->function_name);
        $this->assertSame('func_b', $traces[1]->call_frames[0]->function_name);
    }

    public function testCommentLinesAreSkipped(): void
    {
        $input = "# this is a comment\n0 func_a /path/to/file.php:10\n\n";
        $parser = new PhpSpyCompatibleParser();
        $traces = iterator_to_array($parser->parseFile($this->createStream($input)));

        $this->assertCount(1, $traces);
        $this->assertCount(1, $traces[0]->call_frames);
        $this->assertSame('func_a', $traces[0]->call_frames[0]->function_name);
    }

    public function testOriginalDataContextIsSet(): void
    {
        $input = "0 func_a /path/to/file.php:10\n\n";
        $parser = new PhpSpyCompatibleParser();
        $traces = iterator_to_array($parser->parseFile($this->createStream($input)));

        $context = $traces[0]->call_frames[0]->original_context;
        $this->assertInstanceOf(PhpSpyCompatibleDataContext::class, $context);
        $this->assertSame('line:1', $context->toString());
    }

    public function testEmptyInputYieldsNothing(): void
    {
        $parser = new PhpSpyCompatibleParser();
        $traces = iterator_to_array($parser->parseFile($this->createStream('')));

        $this->assertCount(0, $traces);
    }

    public function testMissingSeparatorThrowsException(): void
    {
        $input = "0 func_a no_colon_here\n\n";
        $parser = new PhpSpyCompatibleParser();

        $this->expectException(PhpSpyCompatibleParserException::class);
        $this->expectExceptionMessage('missing separator ":"');
        iterator_to_array($parser->parseFile($this->createStream($input)));
    }

    public function testEmptyFileLineThrowsException(): void
    {
        // double space between name and file_line causes explode to produce '' as the third element
        $input = "0 func_a  /file.php:10\n\n";
        $parser = new PhpSpyCompatibleParser();

        $this->expectException(PhpSpyCompatibleParserException::class);
        $this->expectExceptionMessage('missing line number and file path');
        iterator_to_array($parser->parseFile($this->createStream($input)));
    }

    public function testMissingFilePathThrowsException(): void
    {
        $input = "0 func_a :10\n\n";
        $parser = new PhpSpyCompatibleParser();

        $this->expectException(PhpSpyCompatibleParserException::class);
        $this->expectExceptionMessage('missing file path');
        iterator_to_array($parser->parseFile($this->createStream($input)));
    }

    public function testExceptionContainsLineNumber(): void
    {
        $input = "0 func_a /valid/path.php:1\n0 func_b no_colon_here\n\n";
        $parser = new PhpSpyCompatibleParser();

        try {
            iterator_to_array($parser->parseFile($this->createStream($input)));
            $this->fail('Expected PhpSpyCompatibleParserException');
        } catch (PhpSpyCompatibleParserException $e) {
            $this->assertSame(2, $e->lineno_before_parse);
        }
    }

    public function testFilePathWithColonInPath(): void
    {
        $input = "0 func_a /path/to/C:/file.php:10\n\n";
        $parser = new PhpSpyCompatibleParser();
        $traces = iterator_to_array($parser->parseFile($this->createStream($input)));

        $this->assertCount(1, $traces);
        $this->assertSame('/path/to/C:/file.php', $traces[0]->call_frames[0]->file_name);
        $this->assertSame(10, $traces[0]->call_frames[0]->lineno);
    }
}
