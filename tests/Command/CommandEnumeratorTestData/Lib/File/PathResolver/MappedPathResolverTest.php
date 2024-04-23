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

namespace Reli\Command\Lib\File\PathResolver;

use PHPUnit\Framework\Attributes\DataProvider;
use Reli\Lib\File\PathResolver\MappedPathResolver;
use PHPUnit\Framework\TestCase;

class MappedPathResolverTest extends TestCase
{
    #[DataProvider('provideResolve')]
    public function testResolve(array $expects, array $path_map, array $paths)
    {
        $resolver = new MappedPathResolver($path_map);
        $this->assertSame(
            $expects,
            array_map(fn($path) => $resolver->resolve(1, $path), $paths),
        );
    }

    public static function provideResolve()
    {
        return [
            'empty map' => [
                'expects' => [
                    '/proc/1',
                ],
                'path_map' => [
                ],
                'paths' => [
                    '/proc/1',
                ],
            ],
            'map to same path' => [
                'expects' => [
                    '/proc/1',
                ],
                'path_map' => [
                    '/proc' => '/proc',
                ],
                'paths' => [
                    '/proc/1',
                ],
            ],
            'map to different file' => [
                'expects' => [
                    '/proc/2',
                ],
                'path_map' => [
                    '/proc/1' => '/proc/2',
                ],
                'paths' => [
                    '/proc/1',
                ],
            ],
            'map to different directory' => [
                'expects' => [
                    '/proc/2/1',
                ],
                'path_map' => [
                    '/proc/1' => '/proc/2',
                ],
                'paths' => [
                    '/proc/1/1',
                ],
            ],
        ];
    }
}
