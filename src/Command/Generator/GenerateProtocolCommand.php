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

namespace Reli\Command\Generator;

use Reli\Lib\Session\Attribute\SessionProtocol;
use Reli\Lib\Session\Generator\ProtocolGenerator;
use Reli\Lib\Session\SessionGraph;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateProtocolCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->setName('generator:protocol')
            ->setDescription('Generate protocol interfaces and implementations from session type definitions');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $generator = new ProtocolGenerator();
        $enumClasses = $this->discoverSessionEnums();

        if ($enumClasses === []) {
            $output->writeln('<comment>No session protocol enums found.</comment>');
            return 0;
        }

        foreach ($enumClasses as $enumClass) {
            $output->writeln("Processing {$enumClass}...");

            $graph = SessionGraph::fromEnum($enumClass);

            $errors = $graph->verify();
            if ($errors !== []) {
                $output->writeln('<error>Protocol verification failed:</error>');
                foreach ($errors as $error) {
                    $output->writeln("  - {$error}");
                }
                return 1;
            }
            $output->writeln('  Protocol verification: <info>OK</info>');

            $files = $generator->generate($graph);
            foreach ($files as $path => $content) {
                $fullPath = dirname(__DIR__, 3) . '/' . $path;
                $dir = dirname($fullPath);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($fullPath, $content);
                $output->writeln("  Generated: {$path}");
            }
        }

        $output->writeln('<info>Done.</info>');
        return 0;
    }

    /**
     * Discover all enums annotated with #[SessionProtocol] under src/.
     *
     * @return list<class-string<\UnitEnum>>
     */
    private function discoverSessionEnums(): array
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        $enums = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            assert($file instanceof \SplFileInfo);
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            // Quick pre-filter: skip files that don't mention SessionProtocol
            if (!str_contains($content, 'SessionProtocol')) {
                continue;
            }

            $className = $this->extractFullyQualifiedName($content);
            if ($className === null) {
                continue;
            }

            if (!enum_exists($className)) {
                continue;
            }

            $ref = new \ReflectionEnum($className);
            if ($ref->getAttributes(SessionProtocol::class) !== []) {
                $enums[] = $className;
            }
        }

        sort($enums);
        return $enums;
    }

    /**
     * Extract FQCN from a PHP file's namespace + enum/class declaration.
     *
     * @return class-string|null
     */
    private function extractFullyQualifiedName(string $content): ?string
    {
        $namespace = null;
        if (preg_match('/^namespace\s+([^\s;]+)/m', $content, $m)) {
            $namespace = $m[1];
        }

        if (preg_match('/^(?:enum|class|interface)\s+(\w+)/m', $content, $m)) {
            $name = $m[1];
            /** @var class-string */
            return $namespace !== null ? "{$namespace}\\{$name}" : $name;
        }

        return null;
    }
}
