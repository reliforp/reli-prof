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

namespace Reli\Inspector\Watch\Trigger;

use PHPUnit\Framework\TestCase;
use Reli\Inspector\Watch\HeapStats;
use Reli\Inspector\Watch\VariableValue;
use Reli\Inspector\Watch\WatchContext;

class VariableValueTriggerTest extends TestCase
{
    public function testParseGlobalIntExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'global::$counter:gt:1000'
        );
        $this->assertSame('global', $parsed['scope']);
        $this->assertSame('$counter', $parsed['name']);
        $this->assertSame('gt', $parsed['op']);
        $this->assertSame('1000', $parsed['value']);
    }

    public function testParseStaticPropertyExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'static::App\Cache::$entries:count_gt:50000'
        );
        $this->assertSame('static', $parsed['scope']);
        $this->assertSame('App\Cache::$entries', $parsed['name']);
        $this->assertSame('count_gt', $parsed['op']);
        $this->assertSame('50000', $parsed['value']);
    }

    public function testParseFuncStaticExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'func_static::App\retry()$attempt:gte:10'
        );
        $this->assertSame('func_static', $parsed['scope']);
        $this->assertSame('App\retry()$attempt', $parsed['name']);
        $this->assertSame('gte', $parsed['op']);
        $this->assertSame('10', $parsed['value']);
    }

    public function testParseLocalContains(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'local::message:contains:error'
        );
        $this->assertSame('local', $parsed['scope']);
        $this->assertSame('message', $parsed['name']);
        $this->assertSame('contains', $parsed['op']);
        $this->assertSame('error', $parsed['value']);
    }

    public function testParseIsNull(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'global::$result:is_null'
        );
        $this->assertSame('global', $parsed['scope']);
        $this->assertSame('$result', $parsed['name']);
        $this->assertSame('is_null', $parsed['op']);
        $this->assertSame('', $parsed['value']);
    }

    public function testParseInvalidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableValueTrigger::parseExpression('invalid');
    }

    public function testParseNoOperatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariableValueTrigger::parseExpression('global::$name');
    }

    public function testLocalWithoutFuncSpecThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VariableValueTrigger('local::$counter:gt:0');
    }

    public function testFuncStaticWithoutFuncSpecThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VariableValueTrigger('func_static::$count:gt:0');
    }

    public function testGlobalWithoutDollarThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VariableValueTrigger('global::counter:gt:0');
    }

    public function testIntGtFires(): void
    {
        $trigger = new VariableValueTrigger('global::$counter:gt:100');
        $context = $this->makeContext([
            'global::$counter' => new VariableValue(
                VariableValue::TYPE_LONG,
                150,
                null,
            ),
        ]);
        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertSame('watch-var', $event->trigger_name);
    }

    public function testIntGtDoesNotFire(): void
    {
        $trigger = new VariableValueTrigger('global::$counter:gt:100');
        $context = $this->makeContext([
            'global::$counter' => new VariableValue(
                VariableValue::TYPE_LONG,
                50,
                null,
            ),
        ]);
        $this->assertNull($trigger->evaluate($context));
    }

    public function testArrayCountGt(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$cache:count_gt:1000'
        );
        $context = $this->makeContext([
            'global::$cache' => new VariableValue(
                VariableValue::TYPE_ARRAY,
                null,
                5000,
            ),
        ]);
        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertStringContainsString('array(5000)', $event->description);
    }

    public function testArrayCountGtDoesNotFire(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$cache:count_gt:1000'
        );
        $context = $this->makeContext([
            'global::$cache' => new VariableValue(
                VariableValue::TYPE_ARRAY,
                null,
                500,
            ),
        ]);
        $this->assertNull($trigger->evaluate($context));
    }

    public function testStringEq(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$status:eq:error'
        );
        $context = $this->makeContext([
            'global::$status' => new VariableValue(
                VariableValue::TYPE_STRING,
                'error',
                null,
            ),
        ]);
        $this->assertNotNull($trigger->evaluate($context));
    }

    public function testStringContains(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$msg:contains:fatal'
        );
        $context = $this->makeContext([
            'global::$msg' => new VariableValue(
                VariableValue::TYPE_STRING,
                'a fatal error occurred',
                null,
            ),
        ]);
        $this->assertNotNull($trigger->evaluate($context));
    }

    public function testBoolEq(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$debug:eq:true'
        );
        $context = $this->makeContext([
            'global::$debug' => new VariableValue(
                VariableValue::TYPE_BOOL,
                true,
                null,
            ),
        ]);
        $this->assertNotNull($trigger->evaluate($context));
    }

    public function testIsNull(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$result:is_null'
        );
        $context = $this->makeContext([
            'global::$result' => new VariableValue(
                VariableValue::TYPE_NULL,
                null,
                null,
            ),
        ]);
        $this->assertNotNull($trigger->evaluate($context));
    }

    public function testIsNullDoesNotFireOnNonNull(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$result:is_null'
        );
        $context = $this->makeContext([
            'global::$result' => new VariableValue(
                VariableValue::TYPE_LONG,
                42,
                null,
            ),
        ]);
        $this->assertNull($trigger->evaluate($context));
    }

    public function testMissingVariableReturnsNull(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$nonexistent:gt:0'
        );
        $context = $this->makeContext([]);
        $this->assertNull($trigger->evaluate($context));
    }

    public function testProperties(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$x:gt:0'
        );
        $this->assertSame('watch-var', $trigger->name());
        $this->assertFalse($trigger->requiresCallTrace());
        $this->assertTrue($trigger->requiresDeepInspection());
    }

    public function testParseMemoryUsageExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'memory::memory_get_usage:gt:104857600'
        );
        $this->assertSame('memory', $parsed['scope']);
        $this->assertSame('memory_get_usage', $parsed['name']);
        $this->assertSame('gt', $parsed['op']);
        $this->assertSame('104857600', $parsed['value']);
    }

    public function testParseMemoryPeakUsageExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'memory::memory_get_peak_usage:gte:134217728'
        );
        $this->assertSame('memory', $parsed['scope']);
        $this->assertSame('memory_get_peak_usage', $parsed['name']);
        $this->assertSame('gte', $parsed['op']);
        $this->assertSame('134217728', $parsed['value']);
    }

    public function testMemoryUsageGtFires(): void
    {
        $trigger = new VariableValueTrigger(
            'memory::memory_get_usage:gt:104857600'
        );
        $context = $this->makeContext([
            'memory::memory_get_usage' => new VariableValue(
                VariableValue::TYPE_LONG,
                209715200,
                null,
            ),
        ]);
        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertSame('watch-var', $event->trigger_name);
    }

    public function testMemoryUsageGtDoesNotFire(): void
    {
        $trigger = new VariableValueTrigger(
            'memory::memory_get_usage:gt:104857600'
        );
        $context = $this->makeContext([
            'memory::memory_get_usage' => new VariableValue(
                VariableValue::TYPE_LONG,
                52428800,
                null,
            ),
        ]);
        $this->assertNull($trigger->evaluate($context));
    }

    public function testMemoryInvalidNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VariableValueTrigger('memory::invalid_name:gt:0');
    }

    /**
     * @param array<string, VariableValue> $vars
     */
    private function makeContext(array $vars): WatchContext
    {
        return new WatchContext(
            pid: 1,
            heap_stats: new HeapStats(0, 0, 0, 0),
            call_trace: null,
            timestamp: microtime(true),
            previous: null,
            variable_values: $vars,
        );
    }
}
