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

namespace Reli\Command\Inspector;

use DI\ContainerBuilder;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit-level coverage for the `inspector:memory` command's
 * refuse-to-overwrite guard on sqlite3 output.
 *
 * Round-2 SQLite-output validation
 * (`docs/internals/pr-773-sqlite-output-validation-round2.md`)
 * surfaced that re-running `inspector:memory -f sqlite3 -o existing.db`
 * crashes mid-merge in `SqliteRaw\Reader::loadSqliteMaster` with
 * "sqlite_master root is not a leaf (type 0x05); not yet supported"
 * because the SqliteRaw shard merger assumes a single-leaf
 * `sqlite_master`, which only holds for a fresh DB.
 *
 * The guard mirrors the one already in `MemoryExportSqliteCommand`:
 * if `output_format=sqlite3` and the output path exists, refuse with
 * a clear message instead of crashing. The test drives both the
 * explicit `--output-format=sqlite3` shape and the auto-detect-from-
 * extension shape, since most users hit the bug via the latter.
 *
 * Uses the real DI container to wire up the command's nine
 * collaborators: the guard is supposed to short-circuit before any
 * of them are touched, so a non-existent --pid is fine — if the
 * guard ever regresses, the command would proceed past it and fail
 * with a process-related error instead of returning 1, which the
 * test catches as a wrong exit code or wrong output text.
 */
class MemoryCommandOverwriteGuardTest extends BaseTestCase
{
    public function testRefusesToOverwriteExistingSqlite3Output(): void
    {
        $existing_path = tempnam(sys_get_temp_dir(), 'reli_overwrite_guard_');
        self::assertNotFalse($existing_path);
        try {
            self::assertFileExists($existing_path);

            $command = $this->createCommand();
            $exit_code = $command->run(
                new ArrayInput([
                    '--pid' => '1',
                    '--output' => $existing_path,
                    '--output-format' => 'sqlite3',
                ]),
                $buffered_output = new BufferedOutput(),
            );

            self::assertSame(1, $exit_code);
            self::assertStringContainsString('refusing to overwrite', $buffered_output->fetch());
        } finally {
            @unlink($existing_path);
        }
    }

    public function testRefusesWhenSqlite3FormatIsAutoDetectedFromExtension(): void
    {
        // No --output-format flag — settings detect 'sqlite3' from the
        // .sqlite3 extension. The guard must catch that path too,
        // otherwise users who rely on extension-based detection (the
        // common case) would still hit the crashing ingest.
        $base = tempnam(sys_get_temp_dir(), 'reli_overwrite_guard_');
        self::assertNotFalse($base);
        $existing_path = $base . '.sqlite3';
        rename($base, $existing_path);
        try {
            self::assertFileExists($existing_path);

            $command = $this->createCommand();
            $exit_code = $command->run(
                new ArrayInput([
                    '--pid' => '1',
                    '--output' => $existing_path,
                ]),
                $buffered_output = new BufferedOutput(),
            );

            self::assertSame(1, $exit_code);
            self::assertStringContainsString('refusing to overwrite', $buffered_output->fetch());
        } finally {
            @unlink($existing_path);
        }
    }

    private function createCommand(): MemoryCommand
    {
        $container = (new ContainerBuilder())
            ->addDefinitions(__DIR__ . '/../../../config/di.php')
            ->build();
        /** @var MemoryCommand $command */
        $command = $container->make(MemoryCommand::class);
        return $command;
    }
}
