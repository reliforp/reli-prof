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

class VariableValueTest extends TestCase
{
    public function testLong(): void
    {
        $v = new VariableValue(VariableValue::TYPE_LONG, 42, null);
        $this->assertSame('long', $v->type);
        $this->assertSame(42, $v->scalar_value);
        $this->assertNull($v->array_count);
    }

    public function testDouble(): void
    {
        $v = new VariableValue(VariableValue::TYPE_DOUBLE, 3.14, null);
        $this->assertSame('double', $v->type);
        $this->assertSame(3.14, $v->scalar_value);
    }

    public function testString(): void
    {
        $v = new VariableValue(VariableValue::TYPE_STRING, 'hello', null);
        $this->assertSame('string', $v->type);
        $this->assertSame('hello', $v->scalar_value);
    }

    public function testBool(): void
    {
        $v = new VariableValue(VariableValue::TYPE_BOOL, true, null);
        $this->assertSame('bool', $v->type);
        $this->assertTrue($v->scalar_value);
    }

    public function testArray(): void
    {
        $v = new VariableValue(VariableValue::TYPE_ARRAY, null, 100);
        $this->assertSame('array', $v->type);
        $this->assertNull($v->scalar_value);
        $this->assertSame(100, $v->array_count);
    }

    public function testNull(): void
    {
        $v = new VariableValue(VariableValue::TYPE_NULL, null, null);
        $this->assertSame('null', $v->type);
    }

    public function testUnknown(): void
    {
        $v = new VariableValue(VariableValue::TYPE_UNKNOWN, null, null);
        $this->assertSame('unknown', $v->type);
    }

    public function testTypeConstants(): void
    {
        $this->assertSame('long', VariableValue::TYPE_LONG);
        $this->assertSame('double', VariableValue::TYPE_DOUBLE);
        $this->assertSame('string', VariableValue::TYPE_STRING);
        $this->assertSame('bool', VariableValue::TYPE_BOOL);
        $this->assertSame('array', VariableValue::TYPE_ARRAY);
        $this->assertSame('object', VariableValue::TYPE_OBJECT);
        $this->assertSame('null', VariableValue::TYPE_NULL);
        $this->assertSame('recursion', VariableValue::TYPE_RECURSION);
        $this->assertSame('unknown', VariableValue::TYPE_UNKNOWN);
    }

    public function testRecursiveStructure(): void
    {
        $child = new VariableValue(VariableValue::TYPE_LONG, 1, null);
        $arr = new VariableValue(
            VariableValue::TYPE_ARRAY,
            null,
            1,
            [['k', $child]],
        );
        $this->assertSame('array', $arr->type);
        $this->assertSame(1, $arr->array_count);
        $this->assertNotNull($arr->children);
        $this->assertCount(1, $arr->children);
        $this->assertSame('k', $arr->children[0][0]);
        $this->assertSame($child, $arr->children[0][1]);
    }

    public function testObjectMetadata(): void
    {
        $obj = new VariableValue(
            VariableValue::TYPE_OBJECT,
            null,
            0,
            [],
            'Foo\\Bar',
            42,
        );
        $this->assertSame('Foo\\Bar', $obj->class_name);
        $this->assertSame(42, $obj->object_id);
    }
}
