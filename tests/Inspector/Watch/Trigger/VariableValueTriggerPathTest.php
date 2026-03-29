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

/**
 * Tests for --watch-var with path expressions (array/object access).
 */
class VariableValueTriggerPathTest extends TestCase
{
    public function testArrayKeyInGlobalExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'global::$cache[users]:count_gt:1000',
        );
        $this->assertSame('global', $parsed['scope']);
        // The name includes the path — VariableReader handles splitting
        $this->assertSame('$cache[users]', $parsed['name']);
        $this->assertSame('count_gt', $parsed['op']);
        $this->assertSame('1000', $parsed['value']);
    }

    public function testObjectPropertyInExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'global::$config->db->pool:gt:50',
        );
        $this->assertSame('global', $parsed['scope']);
        $this->assertSame('$config->db->pool', $parsed['name']);
        $this->assertSame('gt', $parsed['op']);
        $this->assertSame('50', $parsed['value']);
    }

    public function testMixedPathExpression(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'local::app->cache[users][0]->name:eq:admin',
        );
        $this->assertSame('local', $parsed['scope']);
        $this->assertSame(
            'app->cache[users][0]->name',
            $parsed['name'],
        );
        $this->assertSame('eq', $parsed['op']);
        $this->assertSame('admin', $parsed['value']);
    }

    public function testNestedArrayCountGt(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$data[items]:count_gt:500',
        );
        // The trigger's lookup_key includes the full path
        $this->assertSame(
            'global::$data[items]',
            $trigger->lookup_key,
        );

        // When VariableReader resolves the path and puts
        // the final value in context, the trigger evaluates it
        $context = $this->makeContext([
            'global::$data[items]' => new VariableValue(
                VariableValue::TYPE_ARRAY,
                null,
                1000,
            ),
        ]);
        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
        $this->assertStringContainsString('array(1000)', $event->description);
    }

    public function testObjectPropertyStringEq(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$config->mode:eq:debug',
        );

        $context = $this->makeContext([
            'global::$config->mode' => new VariableValue(
                VariableValue::TYPE_STRING,
                'debug',
                null,
            ),
        ]);
        $this->assertNotNull($trigger->evaluate($context));
    }

    public function testObjectPropertyStringNeq(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$config->mode:eq:debug',
        );

        $context = $this->makeContext([
            'global::$config->mode' => new VariableValue(
                VariableValue::TYPE_STRING,
                'production',
                null,
            ),
        ]);
        $this->assertNull($trigger->evaluate($context));
    }

    public function testDeepNestedIntGt(): void
    {
        $trigger = new VariableValueTrigger(
            'global::$app->db->pool[active]:gt:100',
        );

        $context = $this->makeContext([
            'global::$app->db->pool[active]' => new VariableValue(
                VariableValue::TYPE_LONG,
                150,
                null,
            ),
        ]);
        $event = $trigger->evaluate($context);
        $this->assertNotNull($event);
    }

    public function testStaticPropertyWithPath(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'static::App\Cache::$pools[default]->size:gt:10000',
        );
        $this->assertSame('static', $parsed['scope']);
        $this->assertSame(
            'App\Cache::$pools[default]->size',
            $parsed['name'],
        );
        $this->assertSame('gt', $parsed['op']);
    }

    public function testFuncStaticWithPath(): void
    {
        $parsed = VariableValueTrigger::parseExpression(
            'func_static::retry::$state->attempts:gte:5',
        );
        $this->assertSame('func_static', $parsed['scope']);
        $this->assertSame('retry::$state->attempts', $parsed['name']);
        $this->assertSame('gte', $parsed['op']);
        $this->assertSame('5', $parsed['value']);
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
            has_exception: null,
            timestamp: microtime(true),
            previous: null,
            variable_values: $vars,
        );
    }
}
