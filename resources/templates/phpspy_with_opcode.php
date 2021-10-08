<?php

/**
 * This file is part of the sj-i/php-profiler package.
 *
 * (c) sji <sji@sj-i.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

use PhpProfiler\Lib\PhpProcessReader\CallTrace;
/** @var CallTrace $call_trace */
$depth_offset = 0;
?>
<?php if ($call_trace->call_frames[0]->getOpcodeName() !== ''): ?>
<?= $depth_offset++ ?> <VM>::<?= $call_trace->call_frames[0]->getOpcodeName() ?> <VM>:-1<?= "\n" ?>
<?php endif ?>
<?php foreach ($call_trace->call_frames as $depth => $frame): ?>
<?= $depth + $depth_offset ?> <?= $frame->getFullyQualifiedFunctionName() ?> <?= $frame->file_name ?>:<?= $frame->getLineno() ?><?php if ($frame->getOpcodeName() !== ''): ?>:<?= $frame->getOpcodeName() ?><?php endif ?><?= "\n" ?>
<?php endforeach ?>
<?= "\n" ?>
