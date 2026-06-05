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

namespace Reli\Lib\File\PathResolver;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MappedPathResolverTest extends TestCase
{
    public function testExactKeyWins(): void
    {
        $resolver = new MappedPathResolver(['/proc/1/root' => '/container']);
        $this->assertSame('/container', $resolver->resolve(1, '/proc/1/root'));
    }

    public function testPrefixIsRewritten(): void
    {
        $resolver = new MappedPathResolver(['/proc/1/root' => '/container']);
        $this->assertSame(
            '/container/usr/lib/libphp.so',
            $resolver->resolve(1, '/proc/1/root/usr/lib/libphp.so'),
        );
    }

    public function testRootFallbackIsApplied(): void
    {
        $resolver = new MappedPathResolver(['/' => '/host']);
        $this->assertSame('/host/usr/lib/x.so', $resolver->resolve(1, '/usr/lib/x.so'));
    }

    public function testUnmappedAbsolutePathPassesThrough(): void
    {
        $resolver = new MappedPathResolver(['/proc/1/root' => '/container']);
        $this->assertSame('/usr/lib/x.so', $resolver->resolve(1, '/usr/lib/x.so'));
    }

    /**
     * A non-absolute path (e.g. a garbled link-map entry) must terminate
     * rather than spin forever: dirname() collapses it to '.' and then stays
     * '.', which previously never reached '/'.
     */
    #[DataProvider('nonAbsolutePathProvider')]
    public function testNonAbsolutePathTerminates(string $path): void
    {
        $resolver = new MappedPathResolver(['/proc/1/root' => '/container']);
        // No '/' fallback in the map -> returns the original path verbatim,
        // and crucially returns at all (the loop guard prevents a hang).
        $this->assertSame($path, $resolver->resolve(1, $path));
    }

    /** @return iterable<string, array{string}> */
    public static function nonAbsolutePathProvider(): iterable
    {
        yield 'relative segments' => ['relative/path/to.so'];
        yield 'single dot' => ['.'];
        yield 'bare name' => ['libphp.so'];
        yield 'empty' => [''];
    }
}
