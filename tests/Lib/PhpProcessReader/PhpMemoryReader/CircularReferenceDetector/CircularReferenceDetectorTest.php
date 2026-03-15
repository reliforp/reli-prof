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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\CircularReferenceDetector;

use Reli\BaseTestCase;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendArrayMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\MemoryLocation\ZendObjectMemoryLocation;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ArrayHeaderContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ObjectPropertiesContext;

final class CircularReferenceDetectorTest extends BaseTestCase
{
    public function testNoCircularReferences(): void
    {
        $object_a = new ObjectContext(
            new ZendObjectMemoryLocation(0x1000, 64, 1, 0, 'ClassA')
        );
        $object_b = new ObjectContext(
            new ZendObjectMemoryLocation(0x2000, 64, 1, 0, 'ClassB')
        );

        $properties_a = new ObjectPropertiesContext();
        $properties_a->add('b', $object_b);
        $object_a->add('object_properties', $properties_a);

        $root = new ObjectPropertiesContext();
        $root->add('a', $object_a);

        $detector = new CircularReferenceDetector();
        $result = $detector->detect($root);

        $this->assertCount(0, $result->circular_references);
    }

    public function testDirectCircularReference(): void
    {
        $object_a = new ObjectContext(
            new ZendObjectMemoryLocation(0x1000, 64, 2, 0, 'ClassA')
        );
        $object_b = new ObjectContext(
            new ZendObjectMemoryLocation(0x2000, 64, 2, 0, 'ClassB')
        );

        $properties_a = new ObjectPropertiesContext();
        $properties_a->add('b', $object_b);
        $object_a->add('object_properties', $properties_a);

        $properties_b = new ObjectPropertiesContext();
        $properties_b->add('a', $object_a);
        $object_b->add('object_properties', $properties_b);

        $root = new ObjectPropertiesContext();
        $root->add('obj_a', $object_a);

        $detector = new CircularReferenceDetector();
        $result = $detector->detect($root);

        $this->assertCount(1, $result->circular_references);

        $cycle = $result->circular_references[0];
        $this->assertGreaterThanOrEqual(2, count($cycle->path));
    }

    public function testSelfReference(): void
    {
        $object_a = new ObjectContext(
            new ZendObjectMemoryLocation(0x1000, 64, 2, 0, 'SelfRef')
        );

        $properties = new ObjectPropertiesContext();
        $properties->add('self', $object_a);
        $object_a->add('object_properties', $properties);

        $root = new ObjectPropertiesContext();
        $root->add('obj', $object_a);

        $detector = new CircularReferenceDetector();
        $result = $detector->detect($root);

        $this->assertCount(1, $result->circular_references);
    }

    public function testTriangleCircularReference(): void
    {
        $object_a = new ObjectContext(
            new ZendObjectMemoryLocation(0x1000, 64, 2, 0, 'A')
        );
        $object_b = new ObjectContext(
            new ZendObjectMemoryLocation(0x2000, 64, 2, 0, 'B')
        );
        $object_c = new ObjectContext(
            new ZendObjectMemoryLocation(0x3000, 64, 2, 0, 'C')
        );

        $props_a = new ObjectPropertiesContext();
        $props_a->add('b', $object_b);
        $object_a->add('object_properties', $props_a);

        $props_b = new ObjectPropertiesContext();
        $props_b->add('c', $object_c);
        $object_b->add('object_properties', $props_b);

        $props_c = new ObjectPropertiesContext();
        $props_c->add('a', $object_a);
        $object_c->add('object_properties', $props_c);

        $root = new ObjectPropertiesContext();
        $root->add('start', $object_a);

        $detector = new CircularReferenceDetector();
        $result = $detector->detect($root);

        $this->assertCount(1, $result->circular_references);

        $cycle = $result->circular_references[0];
        $this->assertGreaterThanOrEqual(3, count($cycle->path));
    }

    public function testResultToArray(): void
    {
        $object_a = new ObjectContext(
            new ZendObjectMemoryLocation(0x1000, 64, 2, 0, 'ClassA')
        );
        $object_b = new ObjectContext(
            new ZendObjectMemoryLocation(0x2000, 128, 2, 0, 'ClassB')
        );

        $properties_a = new ObjectPropertiesContext();
        $properties_a->add('b', $object_b);
        $object_a->add('object_properties', $properties_a);

        $properties_b = new ObjectPropertiesContext();
        $properties_b->add('a', $object_a);
        $object_b->add('object_properties', $properties_b);

        $root = new ObjectPropertiesContext();
        $root->add('obj_a', $object_a);

        $detector = new CircularReferenceDetector();
        $result = $detector->detect($root);

        $array = $result->toArray();
        $this->assertSame(1, $array['circular_reference_count']);
        $this->assertArrayHasKey('circular_references', $array);
        $this->assertArrayHasKey('cycle_length', $array['circular_references'][0]);
        $this->assertArrayHasKey('total_size', $array['circular_references'][0]);
        $this->assertArrayHasKey('path', $array['circular_references'][0]);
    }

    public function testArrayCircularReference(): void
    {
        $array_a = new ArrayHeaderContext(
            new ZendArrayMemoryLocation(0x5000, 256, 2, 0)
        );
        $array_b = new ArrayHeaderContext(
            new ZendArrayMemoryLocation(0x6000, 256, 2, 0)
        );

        $array_a->add('element_0', $array_b);
        $array_b->add('element_0', $array_a);

        $root = new ObjectPropertiesContext();
        $root->add('arr', $array_a);

        $detector = new CircularReferenceDetector();
        $result = $detector->detect($root);

        $this->assertCount(1, $result->circular_references);
    }
}
