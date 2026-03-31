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

class VariableSpecTest extends TestCase
{
    public function testParseGlobal(): void
    {
        $spec = VariableSpec::parse('global::$counter');
        $this->assertSame('global', $spec->scope);
        $this->assertSame('$counter', $spec->var_name);
        $this->assertSame('global::$counter', $spec->lookup_key);
    }

    public function testParseLocal(): void
    {
        $spec = VariableSpec::parse('local::App\Service::process()$retries');
        $this->assertSame('local', $spec->scope);
        $this->assertSame('App\Service::process()$retries', $spec->var_name);
        $this->assertSame(
            'local::App\Service::process()$retries',
            $spec->lookup_key,
        );
    }

    public function testParseLocalMain(): void
    {
        $spec = VariableSpec::parse('local::<main>()$var');
        $this->assertSame('local', $spec->scope);
        $this->assertSame('<main>()$var', $spec->var_name);
    }

    public function testParseStaticProperty(): void
    {
        $spec = VariableSpec::parse('static::App\Cache::$entries');
        $this->assertSame('static', $spec->scope);
        $this->assertSame('App\Cache::$entries', $spec->var_name);
        $this->assertSame(
            'static::App\Cache::$entries',
            $spec->lookup_key,
        );
    }

    public function testParseFuncStatic(): void
    {
        $spec = VariableSpec::parse('func_static::App\retry()$attempt');
        $this->assertSame('func_static', $spec->scope);
        $this->assertSame('App\retry()$attempt', $spec->var_name);
    }

    public function testParseGlobalWithPath(): void
    {
        $spec = VariableSpec::parse('global::$config[database][host]');
        $this->assertSame('global', $spec->scope);
        $this->assertSame('$config[database][host]', $spec->var_name);
    }

    public function testParseGlobalWithObjectPath(): void
    {
        $spec = VariableSpec::parse('global::$app->cache->size');
        $this->assertSame('global', $spec->scope);
        $this->assertSame('$app->cache->size', $spec->var_name);
    }

    public function testInvalidExpressionNoScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableSpec::parse('$counter');
    }

    public function testInvalidExpressionEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableSpec::parse('global::');
    }

    public function testInvalidScopeGlobalNoDollar(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableSpec::parse('global::counter');
    }

    public function testInvalidScopeLocalNoFunc(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableSpec::parse('local::$var');
    }

    public function testInvalidScopeFuncStaticNoFunc(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableSpec::parse('func_static::$var');
    }

    public function testInvalidScopeUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableSpec::parse('invalid::$var');
    }
}
