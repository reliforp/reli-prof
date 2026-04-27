<?php

$forwarded = json_decode((string) getenv('RELI_THREAD_ARGV'), true);
$_SERVER['argv'] = array_merge([__DIR__ . '/../reli'], $forwarded ?: []);
$argc = count($_SERVER['argv']);

require __DIR__ . '/../vendor/autoload.php';

use DI\ContainerBuilder;
use Reli\Command\CommandEnumerator;
use Reli\Lib\Log\Log;
use Reli\ReliProfiler;
use Psr\Log\LoggerInterface;
use Reli\Lib\Log\StateCollector\StateCollector;
use Symfony\Component\Console\Application;

$application = new Application();
$container = (new ContainerBuilder())->addDefinitions(__DIR__ . '/../config/di.php')->build();
$application->setName(ReliProfiler::TOOL_NAME);
$application->setVersion(ReliProfiler::getVersion());
Log::initializeLogger($container->make(LoggerInterface::class), $container->make(StateCollector::class));

$command_enumerator = new CommandEnumerator(new GlobIterator(__DIR__ . '/../src/Command/*/*Command.php'));
foreach ($command_enumerator as $command_class) {
    $command = $container->make($command_class);
    $application->addCommand($command);
}

$application->run();
