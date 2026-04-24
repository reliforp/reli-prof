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

namespace Reli\Command\PhpSpy;

use Reli\Lib\PhpSpy\PhpSpyFinder;
use Reli\Lib\PhpSpy\PhpSpyNotFoundException;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PhpSpyInstallCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Full;
    }

    public function __construct(
        private PhpSpyFinder $phpspy_finder,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('phpspy:install')
            ->setDescription('install or check phpspy binary')
        ;
        $this->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'only check if phpspy is installed, do not install'
        );
        $this->addOption(
            'install-path',
            null,
            InputOption::VALUE_REQUIRED,
            'install location (default: ~/.reli/bin/phpspy)'
        );
        $this->addOption(
            'phpspy-path',
            null,
            InputOption::VALUE_REQUIRED,
            'path to an existing phpspy binary to check'
        );
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $check_only = (bool)$input->getOption('check');
        /** @var string|null $phpspy_path_option */
        $phpspy_path_option = $input->getOption('phpspy-path');

        // Check if phpspy is already available
        try {
            $path = $this->phpspy_finder->find(
                is_string($phpspy_path_option) ? $phpspy_path_option : null
            );
            $version = $this->getPhpSpyVersion($path);
            $output->writeln('<info>phpspy found: ' . $path . '</info>');
            if ($version !== null) {
                $output->writeln('<info>version: ' . $version . '</info>');
            }
            return 0;
        } catch (PhpSpyNotFoundException) {
            if ($check_only) {
                $output->writeln('<error>phpspy not found</error>');
                return 1;
            }
        }

        // Install phpspy
        /** @var string|null $install_path_option */
        $install_path_option = $input->getOption('install-path');
        $install_path = is_string($install_path_option)
            ? $install_path_option
            : PhpSpyFinder::getDefaultInstallPath();
        $install_dir = dirname($install_path);

        $output->writeln('<info>installing phpspy to ' . $install_path . '...</info>');

        // Ensure install directory exists
        if (!is_dir($install_dir)) {
            if (!mkdir($install_dir, 0755, true)) {
                $output->writeln('<error>failed to create directory: ' . $install_dir . '</error>');
                return 1;
            }
        }

        // Clone and build
        $my_pid = getmypid();
        if ($my_pid === false) {
            throw new \RuntimeException('Failed to get current process ID');
        }
        $tmp_dir = sys_get_temp_dir() . '/phpspy-build-' . $my_pid;
        $output->writeln('cloning phpspy repository...');
        $clone_result = $this->runCommand(
            'git clone --depth 1 https://github.com/adsr/phpspy ' . escapeshellarg($tmp_dir),
            $output,
        );
        if ($clone_result !== 0) {
            $output->writeln('<error>failed to clone phpspy repository</error>');
            return 1;
        }

        $output->writeln('building phpspy...');
        $build_result = $this->runCommand(
            'cd ' . escapeshellarg($tmp_dir) . ' && make',
            $output,
        );
        if ($build_result !== 0) {
            $this->cleanup($tmp_dir);
            $output->writeln('<error>failed to build phpspy (make sure gcc and make are installed)</error>');
            return 1;
        }

        // Copy binary
        $source = $tmp_dir . '/phpspy';
        if (!is_file($source)) {
            $this->cleanup($tmp_dir);
            $output->writeln('<error>phpspy binary not found after build</error>');
            return 1;
        }

        if (!copy($source, $install_path)) {
            $this->cleanup($tmp_dir);
            $output->writeln('<error>failed to copy phpspy binary to ' . $install_path . '</error>');
            return 1;
        }
        chmod($install_path, 0755);

        // Cleanup
        $this->cleanup($tmp_dir);

        // Verify
        $version = $this->getPhpSpyVersion($install_path);
        $output->writeln('<info>phpspy installed successfully: ' . $install_path . '</info>');
        if ($version !== null) {
            $output->writeln('<info>version: ' . $version . '</info>');
        }

        return 0;
    }

    private function getPhpSpyVersion(string $path): ?string
    {
        $result = exec(escapeshellarg($path) . ' --version 2>&1', $lines, $result_code);
        if ($result === false || $result_code !== 0) {
            return null;
        }
        return trim($result);
    }

    private function runCommand(string $command, OutputInterface $output): int
    {
        $result_code = 0;
        /** @var list<string> $command_output */
        $command_output = [];
        exec($command . ' 2>&1', $command_output, $result_code);
        if ($output->isVerbose()) {
            /** @var string $line */
            foreach ($command_output as $line) {
                $output->writeln('  ' . $line);
            }
        }
        return $result_code;
    }

    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
