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

final class JsonCircularReferenceDetectorTest extends BaseTestCase
{
    public function testNoCircularReferences(): void
    {
        $context = [
            'objects_store' => [
                '#node_id' => 0,
                '#type' => 'ObjectsStoreContext',
                'object_0' => [
                    '#node_id' => 1,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 4096, 'size' => 64, 'class_name' => 'Foo'],
                    ],
                    'object_properties' => [
                        '#node_id' => 2,
                        '#type' => 'ObjectPropertiesContext',
                        '#count' => 1,
                        'name' => [
                            '#node_id' => 3,
                            '#type' => 'StringContext',
                            '#locations' => [
                                ['address' => 8192, 'size' => 32],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($context);

        $this->assertSame(0, $result['circular_reference_count']);
        $this->assertSame([], $result['circular_references']);
    }

    public function testDirectCircularReference(): void
    {
        // A -> B -> (back to A)
        $context = [
            'objects_store' => [
                '#node_id' => 0,
                '#type' => 'ObjectsStoreContext',
                'object_0' => [
                    '#node_id' => 1,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 4096, 'size' => 64, 'class_name' => 'ClassA'],
                    ],
                    'object_properties' => [
                        '#node_id' => 2,
                        '#type' => 'ObjectPropertiesContext',
                        '#count' => 1,
                        'ref_to_b' => [
                            '#node_id' => 3,
                            '#type' => 'ObjectContext',
                            '#locations' => [
                                ['address' => 8192, 'size' => 128, 'class_name' => 'ClassB'],
                            ],
                            'object_properties' => [
                                '#node_id' => 4,
                                '#type' => 'ObjectPropertiesContext',
                                '#count' => 1,
                                'ref_to_a' => [
                                    '#reference_node_id' => 1,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($context);

        $this->assertSame(1, $result['circular_reference_count']);
        $cycle = $result['circular_references'][0];
        $this->assertSame('ref_to_a', $cycle['back_edge']);
        $this->assertGreaterThanOrEqual(2, $cycle['cycle_length']);

        $class_names = array_filter(
            array_column($cycle['path'], 'class_name'),
        );
        $this->assertContains('ClassA', $class_names);
    }

    public function testSelfReference(): void
    {
        $context = [
            'objects_store' => [
                '#node_id' => 0,
                '#type' => 'ObjectsStoreContext',
                'object_0' => [
                    '#node_id' => 1,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 4096, 'size' => 64, 'class_name' => 'SelfRef'],
                    ],
                    'object_properties' => [
                        '#node_id' => 2,
                        '#type' => 'ObjectPropertiesContext',
                        '#count' => 1,
                        'self' => [
                            '#reference_node_id' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($context);

        $this->assertSame(1, $result['circular_reference_count']);
        $cycle = $result['circular_references'][0];
        $this->assertSame('self', $cycle['back_edge']);
    }

    public function testTriangleCircularReference(): void
    {
        // A -> B -> C -> (back to A)
        $context = [
            'objects_store' => [
                '#node_id' => 0,
                '#type' => 'ObjectsStoreContext',
                'object_0' => [
                    '#node_id' => 1,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 0x1000, 'size' => 64, 'class_name' => 'A'],
                    ],
                    'props' => [
                        '#node_id' => 2,
                        '#type' => 'ObjectPropertiesContext',
                        'b' => [
                            '#node_id' => 3,
                            '#type' => 'ObjectContext',
                            '#locations' => [
                                ['address' => 0x2000, 'size' => 64, 'class_name' => 'B'],
                            ],
                            'props' => [
                                '#node_id' => 4,
                                '#type' => 'ObjectPropertiesContext',
                                'c' => [
                                    '#node_id' => 5,
                                    '#type' => 'ObjectContext',
                                    '#locations' => [
                                        ['address' => 0x3000, 'size' => 64, 'class_name' => 'C'],
                                    ],
                                    'props' => [
                                        '#node_id' => 6,
                                        '#type' => 'ObjectPropertiesContext',
                                        'back_to_a' => [
                                            '#reference_node_id' => 1,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($context);

        $this->assertSame(1, $result['circular_reference_count']);
        $cycle = $result['circular_references'][0];
        $this->assertSame('back_to_a', $cycle['back_edge']);
        $this->assertGreaterThanOrEqual(3, $cycle['cycle_length']);
    }

    public function testBackReferenceToNonAncestorIsNotCycle(): void
    {
        // Shared node referenced from two places (diamond, not cycle)
        $context = [
            'objects_store' => [
                '#node_id' => 0,
                '#type' => 'ObjectsStoreContext',
                'object_a' => [
                    '#node_id' => 1,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 0x1000, 'size' => 64, 'class_name' => 'A'],
                    ],
                    'props' => [
                        '#node_id' => 2,
                        '#type' => 'ObjectPropertiesContext',
                        'shared' => [
                            '#node_id' => 3,
                            '#type' => 'ObjectContext',
                            '#locations' => [
                                ['address' => 0x3000, 'size' => 64, 'class_name' => 'Shared'],
                            ],
                        ],
                    ],
                ],
                'object_b' => [
                    '#node_id' => 4,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 0x2000, 'size' => 64, 'class_name' => 'B'],
                    ],
                    'props' => [
                        '#node_id' => 5,
                        '#type' => 'ObjectPropertiesContext',
                        'also_shared' => [
                            '#reference_node_id' => 3,
                        ],
                    ],
                ],
            ],
        ];

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($context);

        $this->assertSame(0, $result['circular_reference_count']);
    }

    public function testMultipleCycles(): void
    {
        $context = [
            'objects_store' => [
                '#node_id' => 0,
                '#type' => 'ObjectsStoreContext',
                'cycle_1_start' => [
                    '#node_id' => 1,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 0x1000, 'size' => 64, 'class_name' => 'Cycle1A'],
                    ],
                    'props' => [
                        '#node_id' => 2,
                        '#type' => 'ObjectPropertiesContext',
                        'back' => [
                            '#reference_node_id' => 1,
                        ],
                    ],
                ],
                'cycle_2_start' => [
                    '#node_id' => 3,
                    '#type' => 'ObjectContext',
                    '#locations' => [
                        ['address' => 0x2000, 'size' => 128, 'class_name' => 'Cycle2A'],
                    ],
                    'props' => [
                        '#node_id' => 4,
                        '#type' => 'ObjectPropertiesContext',
                        'back' => [
                            '#reference_node_id' => 3,
                        ],
                    ],
                ],
            ],
        ];

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($context);

        $this->assertSame(2, $result['circular_reference_count']);
    }

    public function testTotalSizeCalculation(): void
    {
        $context = [
            'obj' => [
                '#node_id' => 0,
                '#type' => 'ObjectContext',
                '#locations' => [
                    ['address' => 0x1000, 'size' => 100, 'class_name' => 'A'],
                ],
                'props' => [
                    '#node_id' => 1,
                    '#type' => 'ObjectPropertiesContext',
                    'other' => [
                        '#node_id' => 2,
                        '#type' => 'ObjectContext',
                        '#locations' => [
                            ['address' => 0x2000, 'size' => 200, 'class_name' => 'B'],
                        ],
                        'props' => [
                            '#node_id' => 3,
                            '#type' => 'ObjectPropertiesContext',
                            'back' => [
                                '#reference_node_id' => 0,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $detector = new JsonCircularReferenceDetector();
        $result = $detector->detect($context);

        $this->assertSame(1, $result['circular_reference_count']);
        $cycle = $result['circular_references'][0];
        // A(100) + props(0) + B(200) + props(0) = 300
        $this->assertSame(300, $cycle['total_size']);
    }
}
