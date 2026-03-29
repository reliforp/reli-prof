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

namespace Reli\Inspector\Watch;

use PHPUnit\Framework\TestCase;

class VariableReaderTest extends TestCase
{
    public function testParseSimpleName(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression('cache');
        $this->assertSame('cache', $root);
        $this->assertSame([], $segments);
    }

    public function testParseArrayKey(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'cache[users]',
        );
        $this->assertSame('cache', $root);
        $this->assertSame([['[]', 'users']], $segments);
    }

    public function testParseNestedArrayKeys(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'data[users][0]',
        );
        $this->assertSame('data', $root);
        $this->assertSame(
            [['[]', 'users'], ['[]', '0']],
            $segments,
        );
    }

    public function testParseObjectProperty(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'obj->items',
        );
        $this->assertSame('obj', $root);
        $this->assertSame([['->',  'items']], $segments);
    }

    public function testParseMixedAccess(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'app->cache[users][0]->name',
        );
        $this->assertSame('app', $root);
        $this->assertSame(
            [
                ['->', 'cache'],
                ['[]', 'users'],
                ['[]', '0'],
                ['->', 'name'],
            ],
            $segments,
        );
    }

    public function testParseDeepObjectChain(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'container->services->cache->pool',
        );
        $this->assertSame('container', $root);
        $this->assertSame(
            [
                ['->', 'services'],
                ['->', 'cache'],
                ['->', 'pool'],
            ],
            $segments,
        );
    }

    public function testParseArrayThenProperty(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'items[key]->count',
        );
        $this->assertSame('items', $root);
        $this->assertSame(
            [
                ['[]', 'key'],
                ['->', 'count'],
            ],
            $segments,
        );
    }

    public function testParseEmptyBrackets(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'arr[]',
        );
        $this->assertSame('arr', $root);
        $this->assertSame([['[]', '']], $segments);
    }

    public function testParseNumericKeys(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'list[0][1][2]',
        );
        $this->assertSame('list', $root);
        $this->assertSame(
            [['[]', '0'], ['[]', '1'], ['[]', '2']],
            $segments,
        );
    }

    public function testParseSingleArrow(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'obj->x',
        );
        $this->assertSame('obj', $root);
        $this->assertSame([['->',  'x']], $segments);
    }

    public function testParsePropertyChainedWithArray(): void
    {
        [$root, $segments] = VariableReader::parsePathExpression(
            'svc->pool[default]->connections[0]->host',
        );
        $this->assertSame('svc', $root);
        $this->assertSame(
            [
                ['->', 'pool'],
                ['[]', 'default'],
                ['->', 'connections'],
                ['[]', '0'],
                ['->', 'host'],
            ],
            $segments,
        );
    }
}
