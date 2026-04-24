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

namespace Reli\Lib\String;

use Reli\BaseTestCase;

class PathMapTest extends BaseTestCase
{
    public function testEmptyPathMapReturnsPathUnchanged(): void
    {
        $map = PathMap::empty();
        $this->assertTrue($map->isEmpty());
        $this->assertSame('/var/www/html/foo.php', $map->map('/var/www/html/foo.php'));
    }

    public function testSinglePrefixIsSubstituted(): void
    {
        $map = new PathMap(['/var/www/html' => '/home/me/project']);
        $this->assertSame(
            '/home/me/project/src/App.php',
            $map->map('/var/www/html/src/App.php'),
        );
    }

    public function testLongestMatchingPrefixWins(): void
    {
        $map = new PathMap([
            '/var/www' => '/wrong',
            '/var/www/html/vendor' => '/opt/vendor',
            '/var/www/html' => '/home/me/project',
        ]);
        $this->assertSame(
            '/opt/vendor/foo/bar.php',
            $map->map('/var/www/html/vendor/foo/bar.php'),
        );
        $this->assertSame(
            '/home/me/project/src/App.php',
            $map->map('/var/www/html/src/App.php'),
        );
        $this->assertSame(
            '/wrong/misc/x.php',
            $map->map('/var/www/misc/x.php'),
        );
    }

    public function testUnmatchedPathIsReturnedAsIs(): void
    {
        $map = new PathMap(['/var/www' => '/home/me']);
        $this->assertSame('/tmp/other.php', $map->map('/tmp/other.php'));
    }

    public function testParseOptionAcceptsWellFormedEntries(): void
    {
        $map = PathMap::parseOption([
            '/var/www/html=/home/me/project',
            '/opt/app=/Users/me/app',
        ]);
        $this->assertSame(
            '/home/me/project/a.php',
            $map->map('/var/www/html/a.php'),
        );
        $this->assertSame(
            '/Users/me/app/b.php',
            $map->map('/opt/app/b.php'),
        );
    }

    public function testParseOptionAllowsEmptyReplacement(): void
    {
        $map = PathMap::parseOption(['/var/www/html=']);
        $this->assertSame('/a.php', $map->map('/var/www/html/a.php'));
    }

    public function testParseOptionRejectsEntryWithoutEquals(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PathMap::parseOption(['bogus']);
    }

    public function testParseOptionRejectsEmptyFrom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PathMap::parseOption(['=/home/me']);
    }

    public function testToArrayPreservesInsertionOrder(): void
    {
        $entries = [
            '/a' => '/x',
            '/b' => '/y',
        ];
        $map = new PathMap($entries);
        $this->assertSame($entries, $map->toArray());
    }

    public function testReplacementCanContainEquals(): void
    {
        $map = PathMap::parseOption(['/a=https://example.com/blob?ref=main']);
        $this->assertSame(
            'https://example.com/blob?ref=main/foo.php',
            $map->map('/a/foo.php'),
        );
    }
}
