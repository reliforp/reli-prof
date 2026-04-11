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

namespace Reli\Inspector\Settings\GetTraceSettings;

use Mockery;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class GetTraceSettingsFromConsoleInputTest extends BaseTestCase
{
    /**
     * Build an input mock with per-option overrides. Every test calls
     * createSettings() which reads every option, so we need to answer all
     * of them. Mockery's `allows()` has first-match-wins semantics, so
     * overrides must be applied before defaults.
     *
     * @param array<string, mixed> $overrides option-name => return value
     * @param bool $bulk_stack_copy_flag value for hasParameterOption(['--bulk-stack-copy'])
     */
    private function buildInput(
        array $overrides = [],
        bool $bulk_stack_copy_flag = false,
    ): InputInterface {
        $defaults = [
            'depth' => null,
            'with-native-trace' => false,
            'native-trace-anytime' => false,
            'bulk-stack-copy' => null,
            'trace-var' => [],
            'trace-var-every' => null,
            'trace-var-on-function' => null,
        ];
        $merged = $overrides + $defaults;

        $input = Mockery::mock(InputInterface::class);
        foreach ($merged as $name => $value) {
            $input->allows()->getOption($name)->andReturns($value);
        }
        $input->allows()->hasParameterOption(['--bulk-stack-copy'])->andReturns($bulk_stack_copy_flag);
        return $input;
    }

    public function testFromConsoleInput(): void
    {
        $input = $this->buildInput(['depth' => 10]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(10, $settings->depth);
        $this->assertFalse($settings->with_native_trace);
        $this->assertNull($settings->bulk_stack_copy_max_size);
        $this->assertSame([], $settings->var_specs);
        $this->assertSame(1, $settings->var_every);
        $this->assertNull($settings->var_on_function);
    }

    public function testFromConsoleInputDefault(): void
    {
        $input = $this->buildInput();

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(PHP_INT_MAX, $settings->depth);
        $this->assertFalse($settings->with_native_trace);
        $this->assertNull($settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputDepthNotInteger(): void
    {
        $input = $this->buildInput(['depth' => 'abc']);
        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputWithNativeTrace(): void
    {
        $input = $this->buildInput(['with-native-trace' => true]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertTrue($settings->with_native_trace);
        $this->assertFalse($settings->native_trace_anytime);
        $this->assertNull($settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputNativeTraceAnytime(): void
    {
        $input = $this->buildInput(['native-trace-anytime' => true]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertTrue($settings->with_native_trace); // implied by anytime
        $this->assertTrue($settings->native_trace_anytime);
        $this->assertNull($settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputBulkStackCopyFlag(): void
    {
        $input = $this->buildInput([], bulk_stack_copy_flag: true);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(GetTraceSettings::BULK_STACK_COPY_DEFAULT_MAX_SIZE, $settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputBulkStackCopyWithSize(): void
    {
        $input = $this->buildInput(['bulk-stack-copy' => '128K']);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(128 * 1024, $settings->bulk_stack_copy_max_size);
    }

    public function testFromConsoleInputBulkStackCopyInvalidSize(): void
    {
        $input = $this->buildInput(['bulk-stack-copy' => 'abc']);

        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputTraceVarSingle(): void
    {
        $input = $this->buildInput(['trace-var' => ['global::$counter']]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertCount(1, $settings->var_specs);
        $this->assertSame('global', $settings->var_specs[0]->scope);
        $this->assertSame('$counter', $settings->var_specs[0]->var_name);
        $this->assertSame('global::$counter', $settings->var_specs[0]->lookup_key);
    }

    public function testFromConsoleInputTraceVarMultiple(): void
    {
        $input = $this->buildInput([
            'trace-var' => [
                'global::$a',
                'static::App\\Cache::$entries',
                'func_static::App\\retry()$n',
            ],
        ]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertCount(3, $settings->var_specs);
        $this->assertSame('global', $settings->var_specs[0]->scope);
        $this->assertSame('static', $settings->var_specs[1]->scope);
        $this->assertSame('func_static', $settings->var_specs[2]->scope);
    }

    public function testFromConsoleInputTraceVarSkipsEmptyExpressions(): void
    {
        $input = $this->buildInput(['trace-var' => ['', 'global::$a']]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertCount(1, $settings->var_specs);
    }

    public function testFromConsoleInputTraceVarInvalidExpressionRaises(): void
    {
        $input = $this->buildInput(['trace-var' => ['nope']]);

        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputTraceVarEveryDefault(): void
    {
        $input = $this->buildInput();

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(1, $settings->var_every);
    }

    public function testFromConsoleInputTraceVarEveryExplicit(): void
    {
        $input = $this->buildInput(['trace-var-every' => '10']);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(10, $settings->var_every);
    }

    public function testFromConsoleInputTraceVarEveryZeroRaises(): void
    {
        $input = $this->buildInput(['trace-var-every' => '0']);

        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputTraceVarEveryNegativeRaises(): void
    {
        $input = $this->buildInput(['trace-var-every' => '-1']);

        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputTraceVarEveryGarbageRaises(): void
    {
        $input = $this->buildInput(['trace-var-every' => 'abc']);

        $this->expectException(GetTraceSettingsException::class);
        (new GetTraceSettingsFromConsoleInput())->createSettings($input);
    }

    public function testFromConsoleInputTraceVarOnFunction(): void
    {
        $input = $this->buildInput(['trace-var-on-function' => 'App\\Svc::run']);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('App\\Svc::run', $settings->var_on_function);
    }

    public function testFromConsoleInputTraceVarOnFunctionEmptyTreatedAsNull(): void
    {
        $input = $this->buildInput(['trace-var-on-function' => '']);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertNull($settings->var_on_function);
    }

    public function testNeedsCompilerGlobalsFalseForGlobalOnly(): void
    {
        $input = $this->buildInput([
            'trace-var' => ['global::$a', 'local::main()$b'],
        ]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertFalse($settings->needsCompilerGlobals());
    }

    public function testNeedsCompilerGlobalsTrueForStatic(): void
    {
        $input = $this->buildInput([
            'trace-var' => ['global::$a', 'static::App\\Cache::$entries'],
        ]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertTrue($settings->needsCompilerGlobals());
    }

    public function testNeedsCompilerGlobalsTrueForFuncStatic(): void
    {
        $input = $this->buildInput([
            'trace-var' => ['func_static::App\\retry()$n'],
        ]);

        $settings = (new GetTraceSettingsFromConsoleInput())->createSettings($input);

        $this->assertTrue($settings->needsCompilerGlobals());
    }
}
