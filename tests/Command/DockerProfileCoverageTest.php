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

namespace Reli\Command;

use DI\ContainerBuilder;
use GlobIterator;
use PHPUnit\Framework\TestCase;

/**
 * Enforces that every registered Reli command extends ReliCommand and
 * classifies itself via getDockerProfile(). The abstract method on
 * ReliCommand already produces a class-load fatal for concrete
 * subclasses that forget to implement it; this test catches the other
 * mode — a new command that extends Symfony's Command directly and
 * therefore sidesteps classification entirely.
 */
class DockerProfileCoverageTest extends TestCase
{
    /**
     * @return iterable<array{class-string}>
     */
    public static function allCommandClasses(): iterable
    {
        $enumerator = new CommandEnumerator(
            new GlobIterator(__DIR__ . '/../../src/Command/*/*Command.php')
        );
        foreach ($enumerator as $commandClass) {
            yield $commandClass => [$commandClass];
        }
    }

    /**
     * @param class-string $commandClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('allCommandClasses')]
    public function testCommandExtendsReliCommand(string $commandClass): void
    {
        $this->assertTrue(
            is_subclass_of($commandClass, ReliCommand::class),
            "{$commandClass} must extend Reli\\Command\\ReliCommand"
            . " so that getDockerProfile() classification is enforced."
        );
    }

    /**
     * @param class-string $commandClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('allCommandClasses')]
    public function testCommandClassificationIsAKnownDockerProfile(string $commandClass): void
    {
        $this->assertTrue(
            is_subclass_of($commandClass, ReliCommand::class),
            "{$commandClass} must extend ReliCommand (see other test)."
        );
        /** @var class-string<ReliCommand> $commandClass */
        $profile = $commandClass::getDockerProfile();
        $this->assertContains(
            $profile,
            DockerProfile::cases(),
            "{$commandClass}::getDockerProfile() must return a DockerProfile enum case."
        );
    }

    public function testDiContainerCanInstantiateEveryCommand(): void
    {
        $container = (new ContainerBuilder())
            ->addDefinitions(__DIR__ . '/../../config/di.php')
            ->build();

        $failures = [];
        foreach (self::allCommandClasses() as [$commandClass]) {
            try {
                $instance = $container->make($commandClass);
                if (!$instance instanceof ReliCommand) {
                    $failures[] = "{$commandClass} is not a ReliCommand";
                }
            } catch (\Throwable $e) {
                $failures[] = "{$commandClass}: " . $e->getMessage();
            }
        }
        $this->assertSame([], $failures);
    }
}
