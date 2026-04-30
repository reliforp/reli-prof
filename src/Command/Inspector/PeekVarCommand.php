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

use Reli\Inspector\RetryingLoopProvider;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettingsFromConsoleInput;
use Reli\Inspector\Settings\TargetProcessSettings\TargetProcessSettingsFromConsoleInput;
use Reli\Inspector\TargetProcess\TargetProcessResolver;
use Reli\Inspector\Watch\VariableReader;
use Reli\Inspector\Watch\VariableSpec;
use Reli\Inspector\Watch\VariableValue;
use Reli\Lib\Elf\Process\BinaryAnalysisCache;
use Reli\Lib\PhpProcessReader\PhpGlobalsFinder;
use Reli\Lib\PhpProcessReader\PhpVersionDetector;
use Reli\Command\DockerProfile;
use Reli\Command\ReliCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PeekVarCommand extends ReliCommand
{
    #[\Override]
    public static function getDockerProfile(): DockerProfile
    {
        return DockerProfile::Full;
    }

    public function __construct(
        private PhpGlobalsFinder $php_globals_finder,
        private PhpVersionDetector $php_version_detector,
        private VariableReader $variable_reader,
        private TargetProcessResolver $target_process_resolver,
        private RetryingLoopProvider $retrying_loop_provider,
        private TargetPhpSettingsFromConsoleInput $target_php_settings_from_console_input,
        private TargetProcessSettingsFromConsoleInput $target_process_settings_from_console_input,
        private BinaryAnalysisCache $binary_analysis_cache,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->setName('inspector:peek-var')
            ->setDescription(
                'read PHP variable values from a running process'
            )
        ;
        $this->target_process_settings_from_console_input->setOptions($this);
        $this->target_php_settings_from_console_input->setOptions($this);
        $this->addOption(
            'var',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'variable to read (e.g., global::$var,'
                . ' local::func()$var,'
                . ' static::Class::$prop,'
                . ' func_static::func()$var,'
                . ' memory::memory_get_usage)',
        );
        $this->addOption(
            'repeat',
            null,
            InputOption::VALUE_REQUIRED,
            'repeat interval in milliseconds (omit for one-shot)',
        );
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'output format: text or json (default: text)',
            'text',
        );
        $this->addOption(
            'no-cache',
            null,
            InputOption::VALUE_NONE,
            'disable the binary analysis cache',
        );
        $this->addMemoryLimitOption();
    }

    #[\Override]
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->applyMemoryLimit($input, $output);
        if ($input->getOption('no-cache')) {
            $this->binary_analysis_cache->disable();
        }

        $var_expressions = $input->getOption('var');
        assert(is_array($var_expressions));
        if (count($var_expressions) === 0) {
            $output->writeln(
                '<error>No variables specified.'
                . ' Use --var (e.g., --var=\'global::$myvar\')</error>'
            );
            return 1;
        }

        $specs = [];
        foreach ($var_expressions as $expr) {
            assert(is_string($expr));
            try {
                $specs[] = VariableSpec::parse($expr);
            } catch (\InvalidArgumentException $e) {
                $output->writeln(
                    '<error>' . $e->getMessage() . '</error>'
                );
                return 1;
            }
        }

        $needs_cg = false;
        foreach ($specs as $spec) {
            if ($spec->scope === 'static' || $spec->scope === 'func_static') {
                $needs_cg = true;
                break;
            }
        }

        $target_php_settings = $this->target_php_settings_from_console_input
            ->createSettings($input);
        $target_process_settings = $this->target_process_settings_from_console_input
            ->createSettings($input);
        $process_specifier = $this->target_process_resolver
            ->resolve($target_process_settings);

        $target_php_settings = $this->php_version_detector->decidePhpVersion(
            $process_specifier,
            $target_php_settings,
        );

        $eg_address = $this->retrying_loop_provider->do(
            try: fn () => $this->php_globals_finder->findExecutorGlobals(
                $process_specifier,
                $target_php_settings,
            ),
            retry_on: [\Throwable::class],
            max_retry: 10,
            interval_on_retry_ns: 1000 * 1000 * 10,
        );

        $cg_address = 0;
        if ($needs_cg) {
            $cg_address = $this->php_globals_finder->findCompilerGlobals(
                $process_specifier,
                $target_php_settings,
            );
        }

        $format = $input->getOption('format');
        assert(is_string($format));
        $repeat_ms = $input->getOption('repeat');
        assert(is_string($repeat_ms) || is_null($repeat_ms));
        $repeat_us = $repeat_ms !== null
            ? (int)$repeat_ms * 1000
            : null;

        $results = [];
        do {
            try {
                $results = $this->variable_reader->readVariables(
                    $specs,
                    $process_specifier,
                    $target_php_settings,
                    $eg_address,
                    $cg_address,
                );
            } catch (\Throwable $e) {
                $output->writeln(
                    '<comment>read failed: '
                    . $e->getMessage() . '</comment>'
                );
                if ($repeat_us === null) {
                    return 1;
                }
                usleep($repeat_us);
                continue;
            }

            $this->outputResults($output, $specs, $results, $format);

            if ($repeat_us !== null) {
                usleep($repeat_us);
            }
        } while ($repeat_us !== null);

        return 0;
    }

    /**
     * @param list<VariableSpec> $specs
     * @param array<string, VariableValue> $results
     */
    private function outputResults(
        OutputInterface $output,
        array $specs,
        array $results,
        string $format,
    ): void {
        if ($format === 'json') {
            $this->outputJson($output, $specs, $results);
            return;
        }
        $this->outputText($output, $specs, $results);
    }

    /**
     * @param list<VariableSpec> $specs
     * @param array<string, VariableValue> $results
     */
    private function outputText(
        OutputInterface $output,
        array $specs,
        array $results,
    ): void {
        foreach ($specs as $spec) {
            $var = $results[$spec->lookup_key] ?? null;
            if ($var === null) {
                $output->writeln($spec->lookup_key . ' = <not found>');
                continue;
            }
            $output->writeln(
                $spec->lookup_key . ' = ' . $this->formatValue($var),
            );
        }
    }

    /**
     * @param list<VariableSpec> $specs
     * @param array<string, VariableValue> $results
     */
    private function outputJson(
        OutputInterface $output,
        array $specs,
        array $results,
    ): void {
        $data = [];
        foreach ($specs as $spec) {
            $var = $results[$spec->lookup_key] ?? null;
            $data[$spec->lookup_key] = $var !== null
                ? [
                    'type' => $var->type,
                    'value' => $var->scalar_value,
                    'array_count' => $var->array_count,
                ]
                : null;
        }
        $output->writeln((string)json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function formatValue(VariableValue $var): string
    {
        return match ($var->type) {
            VariableValue::TYPE_LONG => '(int) ' . (string)$var->scalar_value,
            VariableValue::TYPE_DOUBLE => '(float) ' . (string)$var->scalar_value,
            VariableValue::TYPE_STRING => '(string) "'
                . $this->truncate((string)$var->scalar_value, 200)
                . '"',
            VariableValue::TYPE_BOOL => '(bool) '
                . ($var->scalar_value === true ? 'true' : 'false'),
            VariableValue::TYPE_ARRAY => '(array) count='
                . ($var->array_count ?? '?'),
            VariableValue::TYPE_NULL => 'null',
            default => '(unknown)',
        };
    }

    private function truncate(string $str, int $max): string
    {
        if (mb_strlen($str) <= $max) {
            return $str;
        }
        return mb_substr($str, 0, $max) . '...';
    }
}
