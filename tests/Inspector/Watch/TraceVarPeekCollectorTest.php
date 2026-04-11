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

namespace Reli\Inspector\Watch;

use Mockery;
use Reli\BaseTestCase;
use Reli\Inspector\Settings\TargetPhpSettings\TargetPhpSettings;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallFrame;
use Reli\Lib\PhpProcessReader\CallTraceReader\CallTrace;
use Reli\Lib\Process\ProcessSpecifier;
use RuntimeException;

class TraceVarPeekCollectorTest extends BaseTestCase
{
    /**
     * @return TargetPhpSettings<'v84'>
     */
    private function targetPhpSettings(): TargetPhpSettings
    {
        /** @var TargetPhpSettings<'v84'> */
        return new TargetPhpSettings('v84');
    }

    private function processSpecifier(): ProcessSpecifier
    {
        return new ProcessSpecifier(1234);
    }

    private function trace(string $innermost_fqn = 'App\\Service::run'): CallTrace
    {
        // Split "A\B::c" into class + method to match CallFrame constructor shape.
        $parts = explode('::', $innermost_fqn, 2);
        if (count($parts) === 2) {
            $frame = new CallFrame($parts[0], $parts[1], '/app.php', null);
        } else {
            $frame = new CallFrame('', $innermost_fqn, '/app.php', null);
        }
        return new CallTrace(
            $frame,
            new CallFrame('', '<main>', '/index.php', null),
        );
    }

    public function testCollectReturnsFormattedAnnotations(): void
    {
        $spec = VariableSpec::parse('global::$counter');
        $reader = Mockery::mock(VariableReaderInterface::class);
        $reader
            ->expects()
            ->readVariables(
                $this->matchSpecList(['global::$counter']),
                Mockery::type(ProcessSpecifier::class),
                Mockery::type(TargetPhpSettings::class),
                0x1000,
                0,
            )
            ->andReturns([
                'global::$counter' => new VariableValue(VariableValue::TYPE_LONG, 42, null),
            ]);

        $collector = new TraceVarPeekCollector($reader, [$spec]);
        $result = $collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        );

        $this->assertSame(['global::$counter' => '(int) 42'], $result);
    }

    public function testCollectReturnsNullWhenReaderReturnsEmpty(): void
    {
        $spec = VariableSpec::parse('global::$missing');
        $reader = Mockery::mock(VariableReaderInterface::class);
        $reader->allows()->readVariables(
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
        )
            ->andReturns([]);

        $collector = new TraceVarPeekCollector($reader, [$spec]);
        $result = $collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        );

        $this->assertNull($result);
    }

    public function testCollectReturnsNullWhenReaderThrows(): void
    {
        $spec = VariableSpec::parse('global::$a');
        $reader = Mockery::mock(VariableReaderInterface::class);
        $reader->allows()->readVariables(
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
        )
            ->andThrows(new RuntimeException('transient read failure'));

        $collector = new TraceVarPeekCollector($reader, [$spec]);
        $result = $collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        );

        $this->assertNull($result);
    }

    public function testEveryTwoReadsOnOddIndexesOnly(): void
    {
        // With var_every=2, every other sample should be skipped. The
        // sample_index advances on every call regardless of gate outcome,
        // so the first call is index=1 (skipped), second is index=2 (read).
        $spec = VariableSpec::parse('global::$counter');
        $reader = Mockery::mock(VariableReaderInterface::class);
        $reader->expects()->readVariables(
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
        )
            ->twice()
            ->andReturns([
                'global::$counter' => new VariableValue(VariableValue::TYPE_LONG, 1, null),
            ]);

        $collector = new TraceVarPeekCollector($reader, [$spec], var_every: 2);

        // Index 1 — skipped
        $this->assertNull($collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
        // Index 2 — reads
        $this->assertNotNull($collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
        // Index 3 — skipped
        $this->assertNull($collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
        // Index 4 — reads
        $this->assertNotNull($collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
    }

    public function testOnFunctionGateAllowsReadWhenFrameIsOnStack(): void
    {
        $spec = VariableSpec::parse('local::App\\Service::run()$id');
        $reader = Mockery::mock(VariableReaderInterface::class);
        $reader->expects()->readVariables(
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
        )
            ->once()
            ->andReturns([
                'local::App\\Service::run()$id' => new VariableValue(VariableValue::TYPE_LONG, 7, null),
            ]);

        $collector = new TraceVarPeekCollector(
            $reader,
            [$spec],
            var_on_function: 'App\\Service::run',
        );
        $result = $collector->collect(
            $this->trace('App\\Service::run'),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        );

        $this->assertSame(['local::App\\Service::run()$id' => '(int) 7'], $result);
    }

    public function testOnFunctionGateSuppressesReadWhenFrameIsAbsent(): void
    {
        $spec = VariableSpec::parse('local::App\\Service::run()$id');
        $reader = Mockery::mock(VariableReaderInterface::class);
        // readVariables must NOT be called
        $reader->shouldNotReceive('readVariables');

        $collector = new TraceVarPeekCollector(
            $reader,
            [$spec],
            var_on_function: 'App\\Service::run',
        );
        $result = $collector->collect(
            $this->trace('Unrelated\\Frame::handle'),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        );

        $this->assertNull($result);
    }

    public function testOnFunctionGateAndEveryInteractCorrectly(): void
    {
        // var_every=2 + gate: sample 1 skipped (every), sample 2 gated-out
        // (no target frame), sample 3 skipped (every), sample 4 would read
        // but frame present — so one read across 4 calls.
        $spec = VariableSpec::parse('local::App\\Service::run()$id');
        $reader = Mockery::mock(VariableReaderInterface::class);
        $reader->expects()->readVariables(
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
        )
            ->once()
            ->andReturns([
                'local::App\\Service::run()$id' => new VariableValue(VariableValue::TYPE_LONG, 1, null),
            ]);

        $collector = new TraceVarPeekCollector(
            $reader,
            [$spec],
            var_every: 2,
            var_on_function: 'App\\Service::run',
        );

        // 1 — skipped by every
        $this->assertNull($collector->collect(
            $this->trace('App\\Service::run'),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
        // 2 — every passes but gate fails
        $this->assertNull($collector->collect(
            $this->trace('Other::thing'),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
        // 3 — skipped by every
        $this->assertNull($collector->collect(
            $this->trace('App\\Service::run'),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
        // 4 — every passes AND gate passes
        $this->assertNotNull($collector->collect(
            $this->trace('App\\Service::run'),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        ));
    }

    public function testCollectFormatsAllValueTypes(): void
    {
        $specs = [
            VariableSpec::parse('global::$i'),
            VariableSpec::parse('global::$s'),
            VariableSpec::parse('global::$a'),
            VariableSpec::parse('global::$b'),
            VariableSpec::parse('global::$n'),
        ];
        $reader = Mockery::mock(VariableReaderInterface::class);
        $reader->expects()->readVariables(
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
            Mockery::any(),
        )
            ->andReturns([
                'global::$i' => new VariableValue(VariableValue::TYPE_LONG, 5, null),
                'global::$s' => new VariableValue(VariableValue::TYPE_STRING, 'hi', null),
                'global::$a' => new VariableValue(VariableValue::TYPE_ARRAY, null, 3),
                'global::$b' => new VariableValue(VariableValue::TYPE_BOOL, true, null),
                'global::$n' => new VariableValue(VariableValue::TYPE_NULL, null, null),
            ]);

        $collector = new TraceVarPeekCollector($reader, $specs);
        $result = $collector->collect(
            $this->trace(),
            $this->processSpecifier(),
            $this->targetPhpSettings(),
            0x1000,
            0,
        );

        $this->assertSame(
            [
                'global::$i' => '(int) 5',
                'global::$s' => '(string) "hi"',
                'global::$a' => '(array) count=3',
                'global::$b' => '(bool) true',
                'global::$n' => 'null',
            ],
            $result,
        );
    }

    /**
     * Mockery matcher that asserts the spec list argument matches the given
     * list of lookup_key strings in order.
     *
     * @param list<string> $expected_keys
     */
    private function matchSpecList(array $expected_keys): object
    {
        return Mockery::on(function ($arg) use ($expected_keys): bool {
            if (!is_array($arg)) {
                return false;
            }
            if (count($arg) !== count($expected_keys)) {
                return false;
            }
            foreach ($arg as $i => $spec) {
                if (!$spec instanceof VariableSpec) {
                    return false;
                }
                if ($spec->lookup_key !== $expected_keys[$i]) {
                    return false;
                }
            }
            return true;
        });
    }
}
