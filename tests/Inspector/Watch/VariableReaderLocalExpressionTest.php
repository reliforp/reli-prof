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

class VariableReaderLocalExpressionTest extends TestCase
{
    public function testSimpleVariable(): void
    {
        [$func, $var] = VariableReader::parseLocalExpression('$counter');
        $this->assertNull($func);
        $this->assertSame('counter', $var);
    }

    public function testWithoutDollar(): void
    {
        [$func, $var] = VariableReader::parseLocalExpression('counter');
        $this->assertNull($func);
        $this->assertSame('counter', $var);
    }

    public function testFunctionScoped(): void
    {
        [$func, $var] = VariableReader::parseLocalExpression(
            'handleRequest()::$items',
        );
        $this->assertSame('handleRequest', $func);
        $this->assertSame('items', $var);
    }

    public function testClassMethodScoped(): void
    {
        [$func, $var] = VariableReader::parseLocalExpression(
            'App\Service::process()::$retries',
        );
        $this->assertSame('App\Service::process', $func);
        $this->assertSame('retries', $var);
    }

    public function testMainScope(): void
    {
        [$func, $var] = VariableReader::parseLocalExpression(
            'main()::$counter',
        );
        $this->assertSame('main', $func);
        $this->assertSame('counter', $var);
    }

    public function testWithPathExpression(): void
    {
        [$func, $var] = VariableReader::parseLocalExpression(
            'App\Controller::index()::$response->body[errors]',
        );
        $this->assertSame('App\Controller::index', $func);
        $this->assertSame('response->body[errors]', $var);
    }
}
