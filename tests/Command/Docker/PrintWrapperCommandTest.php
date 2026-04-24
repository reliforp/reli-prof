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

namespace Reli\Command\Docker;

use PHPUnit\Framework\TestCase;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PrintWrapperCommandTest extends TestCase
{
    private function runWrapper(array $options = []): string
    {
        $app = new Application();
        $command = new PrintWrapperCommand();
        $app->addCommand($command);
        // Two fake commands to make the allowlist non-trivial.
        $minimalFake = new class ('fake:viewer') extends ReliCommand {
            #[\Override]
            public static function getDockerProfile(): DockerProfile
            {
                return DockerProfile::Minimal;
            }
        };
        $fullFake = new class ('fake:attach') extends ReliCommand {
            #[\Override]
            public static function getDockerProfile(): DockerProfile
            {
                return DockerProfile::Full;
            }
        };
        $app->addCommand($minimalFake);
        $app->addCommand($fullFake);

        $output = new BufferedOutput();
        $input = new ArrayInput($options + ['command' => 'docker:print-wrapper']);
        $input->setInteractive(false);
        $result = $command->run($input, $output);
        $this->assertSame(0, $result);
        return $output->fetch();
    }

    public function testFullProfileEmitsReliFunction(): void
    {
        $out = $this->runWrapper(['--profile' => 'full']);
        $this->assertStringContainsString('full profile', $out);
        $this->assertStringContainsString("reli() {", $out);
        $this->assertStringContainsString('--cap-add=SYS_PTRACE', $out);
        $this->assertStringContainsString('--pid=host', $out);
        $this->assertStringContainsString('--network=host', $out);
        $this->assertStringContainsString('reli_assert_scratch_safe()', $out);
        $this->assertStringContainsString('reliforp/reli-prof:', $out);
    }

    public function testFullProfileIsDefault(): void
    {
        $out = $this->runWrapper([]);
        $this->assertStringContainsString("reli() {", $out);
        $this->assertStringContainsString('--cap-add=SYS_PTRACE', $out);
    }

    public function testMinimalProfileEmitsReliViewFunction(): void
    {
        $out = $this->runWrapper(['--profile' => 'minimal']);
        $this->assertStringContainsString('minimal profile', $out);
        $this->assertStringContainsString("reli-view() {", $out);
        $this->assertStringContainsString('--read-only', $out);
        $this->assertStringContainsString('--tmpfs /tmp', $out);
        $this->assertStringNotContainsString('--cap-add=SYS_PTRACE', $out);
        $this->assertStringNotContainsString('--pid=host', $out);
        $this->assertStringNotContainsString('reli_assert_scratch_safe', $out);
    }

    public function testMinimalProfileIncludesAllowlistFromReliCommands(): void
    {
        $out = $this->runWrapper(['--profile' => 'minimal']);
        // Minimal-classified fake should be listed, Full-classified should not.
        $this->assertStringContainsString('#   fake:viewer', $out);
        $this->assertStringContainsString('#   docker:print-wrapper', $out);
        $this->assertStringNotContainsString('#   fake:attach', $out);
    }

    public function testNameOverrideChangesFunctionName(): void
    {
        $out = $this->runWrapper(['--profile' => 'full', '--name' => 'myreli']);
        $this->assertStringContainsString("myreli() {", $out);
        $this->assertStringNotContainsString("\nreli() {", $out);
    }

    public function testImageOverrideChangesImageReference(): void
    {
        $out = $this->runWrapper(['--image' => 'myreg/reli:custom']);
        $this->assertStringContainsString('myreg/reli:custom "$@"', $out);
    }

    public function testUnknownProfileReturnsNonZero(): void
    {
        $app = new Application();
        $command = new PrintWrapperCommand();
        $app->addCommand($command);

        $output = new BufferedOutput();
        $input = new ArrayInput(['--profile' => 'bogus', 'command' => 'docker:print-wrapper']);
        $input->setInteractive(false);
        $result = $command->run($input, $output);

        $this->assertSame(2, $result);
        $this->assertStringContainsString('unknown --profile: bogus', $output->fetch());
    }

    public function testEmittedShellParsesUnderBash(): void
    {
        $out = $this->runWrapper(['--profile' => 'full']);
        $tmp = tempnam(sys_get_temp_dir(), 'reli-wrapper-');
        try {
            file_put_contents($tmp, $out);
            $exitCode = 0;
            $output = [];
            exec('bash -n ' . escapeshellarg($tmp) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, "emitted shell failed bash -n: " . implode("\n", $output));
        } finally {
            @unlink($tmp);
        }
    }
}

