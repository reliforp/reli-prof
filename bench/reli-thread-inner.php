<?php

declare(strict_types=1);

/**
 * Inner script: runs inside the ffi-zts ZTS embed where ext-parallel is
 * loaded, so amphp/parallel's DefaultContextFactory auto-selects
 * ThreadContext. Reconstructs argv from RELI_THREAD_ARGV (set by the
 * launcher) and runs reli's Symfony app directly.
 *
 * Notes:
 *   - The embed's SAPI is "ffi-zts", not CLI, so STDIN/STDOUT/STDERR are
 *     not auto-defined. Composer's bootstrap polyfills STDOUT and STDERR
 *     for us; STDIN is provided here because reli's DaemonCommand uses it
 *     to listen for a 'q' keypress to cancel.
 *   - Cannot `require __DIR__ . '/../reli'` because that bin script has
 *     a `#!/usr/bin/env php` shebang which is only stripped when used as
 *     the script argument, not via require.
 */

defined('STDIN')  or define('STDIN',  fopen('php://stdin',  'r'));
defined('STDOUT') or define('STDOUT', fopen('php://stdout', 'w'));
defined('STDERR') or define('STDERR', fopen('php://stderr', 'w'));

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
Log::initializeLogger(
    $container->make(LoggerInterface::class),
    $container->make(StateCollector::class),
);

$command_enumerator = new CommandEnumerator(new GlobIterator(__DIR__ . '/../src/Command/*/*Command.php'));
foreach ($command_enumerator as $command_class) {
    $command = $container->make($command_class);
    $application->addCommand($command);
}

$application->run();
