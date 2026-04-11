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

namespace Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\Job;

use Reli\Lib\PhpInternals\Types\Php\PhpBasicGlobals;
use Reli\Lib\PhpInternals\Types\Php\PhpShutdownFunctionEntry;
use Reli\Lib\PhpInternals\ZendTypeReader;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\CollectorJob;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\Collector\JobQueue;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\ModulesContext;
use Reli\Lib\PhpProcessReader\PhpMemoryReader\ReferenceContext\StandardModuleContext;
use Reli\Lib\Process\Pointer\Pointer;

/**
 * Emit the modules root branch.
 * Shutdown function callbacks can reference closures/objects.
 */
final class EmitModulesJob implements CollectorJob
{
    public function __construct(
        private ?int $bg_address,
    ) {
    }

    #[\Override]
    public function execute(CollectorContext $ctx, JobQueue $queue): void
    {
        try {
            $modules_context = new ModulesContext();

            if ($this->bg_address === null) {
                $ctx->emitNode($modules_context, null, 'modules');
                return;
            }

            $standard_context = new StandardModuleContext();
            $modules_context->add('standard', $standard_context);

            // Emit root first
            $root_node_id = $ctx->emitNode($modules_context, null, 'modules');

            // Collect shutdown functions
            $bg_pointer = new Pointer(
                PhpBasicGlobals::class,
                $this->bg_address,
                $ctx->zend_type_reader->sizeOf('php_basic_globals'),
            );
            $bg = $ctx->dereferencer->deref($bg_pointer);

            if ($bg->user_shutdown_function_names === null) {
                return;
            }

            // See EmitObjectJob for why this reads from getMemoNodeId().
            $raw_standard = $standard_context->getMemoNodeId();
            $standard_node_id = $raw_standard === null
                ? null
                : ($raw_standard < 0 ? -$raw_standard - 1 : $raw_standard);

            $shutdown_table = $ctx->dereferencer->deref($bg->user_shutdown_function_names);
            $entry_size = $ctx->zend_type_reader->sizeOf('php_shutdown_function_entry');

            foreach ($shutdown_table->getItemIterator($ctx->dereferencer) as $key => $zval) {
                if ($zval->getType() !== 'IS_PTR') {
                    continue;
                }
                $entry_pointer = new Pointer(
                    PhpShutdownFunctionEntry::class,
                    $zval->value->lval,
                    $entry_size,
                );
                try {
                    $entry = $ctx->dereferencer->deref($entry_pointer);
                    $this->pushShutdownFunctionJob(
                        $entry,
                        $standard_node_id,
                        (string)$key,
                        $ctx,
                        $queue,
                    );
                } catch (\Throwable) {
                    continue;
                }
            }
        } catch (\Throwable $e) {
            \Reli\Lib\Log\Log::info('failed to collect modules', ['error' => $e->getMessage()]);
        }
    }

    private function pushShutdownFunctionJob(
        PhpShutdownFunctionEntry $entry,
        ?int $parent_node_id,
        string $key,
        CollectorContext $ctx,
        JobQueue $queue,
    ): void {
        if ($ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V81)) {
            $callable_zval = $entry->getFunctionNameDirect();
        } elseif ($ctx->zend_type_reader->isPhpVersionLowerThan(ZendTypeReader::V85)) {
            $callable_zval = $entry->getFunctionNameFromFci();
        } else {
            $closure_pointer = $entry->getClosureFromFciCache();
            if ($closure_pointer === null) {
                return;
            }
            $queue->push(new EmitObjectJob(
                $closure_pointer,
                $parent_node_id,
                'shutdown_function[' . $key . ']',
            ));
            return;
        }

        if ($callable_zval->isUndef()) {
            return;
        }
        $queue->push(new ResolveZvalJob(
            $callable_zval,
            $parent_node_id,
            'shutdown_function[' . $key . ']',
        ));
    }
}
