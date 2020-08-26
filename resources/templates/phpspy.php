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

/**
 * @var string[] $traces
 */
?>
<?php foreach ($traces as $depth => $trace): ?>
<?php if (isset($trace['class'])): ?>
<?= $depth ?> <?= $trace['class'] . '::' ?><?= $trace['function'] ?> <?= $trace['file'] ?>:<?= $trace['line'] ?>
<?php else: ?>
<?= $depth ?> <?= $trace['function'] ?> <?= $trace['file'] ?>:<?= $trace['line'] ?>
<?php endif ?>
<?php endforeach ?>

