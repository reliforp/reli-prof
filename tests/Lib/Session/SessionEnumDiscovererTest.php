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

namespace Reli\Lib\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use Reli\BaseTestCase;
use Reli\Inspector\Daemon\Reader\Protocol\PhpReaderSession;
use Reli\Inspector\Daemon\Searcher\Protocol\PhpSearcherSession;

#[CoversClass(SessionEnumDiscoverer::class)]
final class SessionEnumDiscovererTest extends BaseTestCase
{
    public function testDiscoverFindsSessionProtocolEnums(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $result = SessionEnumDiscoverer::discover($srcDir);

        $this->assertContains(PhpReaderSession::class, $result);
        $this->assertContains(PhpSearcherSession::class, $result);
    }

    public function testDiscoverReturnsSortedResults(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $result = SessionEnumDiscoverer::discover($srcDir);

        $sorted = $result;
        sort($sorted);
        $this->assertSame($sorted, $result);
    }

    public function testDiscoverReturnsEmptyForDirectoryWithoutProtocols(): void
    {
        // Use a directory that has PHP files but no SessionProtocol enums
        $dir = dirname(__DIR__, 3) . '/src/Lib/Integer';
        $result = SessionEnumDiscoverer::discover($dir);

        $this->assertSame([], $result);
    }

    public function testDiscoverReturnsEmptyForEmptyDirectory(): void
    {
        $tmpDir = sys_get_temp_dir() . '/reli-test-empty-' . uniqid();
        mkdir($tmpDir);

        try {
            $result = SessionEnumDiscoverer::discover($tmpDir);
            $this->assertSame([], $result);
        } finally {
            rmdir($tmpDir);
        }
    }

    public function testDiscoverIgnoresNonPhpFiles(): void
    {
        $tmpDir = sys_get_temp_dir() . '/reli-test-nonphp-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/readme.txt', 'SessionProtocol is mentioned here');

        try {
            $result = SessionEnumDiscoverer::discover($tmpDir);
            $this->assertSame([], $result);
        } finally {
            unlink($tmpDir . '/readme.txt');
            rmdir($tmpDir);
        }
    }

    public function testDiscoverIgnoresPhpFileWithoutEnumDeclaration(): void
    {
        $tmpDir = sys_get_temp_dir() . '/reli-test-noenum-' . uniqid();
        mkdir($tmpDir);
        file_put_contents($tmpDir . '/NotAnEnum.php', <<<'PHP'
            <?php
            // This file mentions SessionProtocol but is not an enum
            $x = 'SessionProtocol';
            PHP);

        try {
            $result = SessionEnumDiscoverer::discover($tmpDir);
            $this->assertSame([], $result);
        } finally {
            unlink($tmpDir . '/NotAnEnum.php');
            rmdir($tmpDir);
        }
    }
}
