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

namespace Reli\Inspector\Output\TopLike;

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Terminal;

final class TopLikeOutputter implements Outputter
{
    public function __construct(
        private ConsoleOutputInterface $output,
        private Terminal $terminal,
    ) {
    }

    public function display(string $trace_target, Stat $stat): void
    {
        $stat->updateStat();
        $this->output($trace_target, $stat);
        $stat->clearCurrentSamples();
    }

    private function output(string $trace_target, Stat $stat): void
    {
        $this->output->write("\e[H\e[2J");
        $this->output->writeln($trace_target);
        $this->output->writeln(
            sprintf(
                'samp_count=%d  func_count=%d  total_count=%d',
                $stat->sample_count,
                count($stat->function_entries),
                $stat->total_count
            )
        );
        $this->output->writeln('');
        $count = 7;
        $width = $this->terminal->getWidth();
        $height = $this->terminal->getHeight();
        $padding_name = max(0, $width - 41);

        $rows = [];
        $align_right = new TableCellStyle(['align' => 'right']);
        $styled = fn (int|string $content, TableCellStyle $style): TableCell =>
            new TableCell(
                (string)$content,
                ['style' => $style]
            )
        ;
        foreach ($stat->function_entries as $function_entry) {
            $name = $function_entry->name;
            $percent = number_format($function_entry->percent_exclusive, 2);
            $rows[] = [
                $styled($function_entry->total_count_inclusive, $align_right),
                $styled($function_entry->total_count_exclusive, $align_right),
                $styled($function_entry->count_inclusive, $align_right),
                $styled($function_entry->count_exclusive, $align_right),
                $styled($percent, $align_right),
                $name,
            ];
            if (++$count > $height) {
                break;
            }
        }

        $output = $this->output->section();
        $table = new Table($output);
        $table->setColumnMaxWidth(5, max(4, $width - 41));
        $table->setHeaders([
            $styled('total_incl', $align_right),
            $styled('total_excl', $align_right),
            $styled('incl', $align_right),
            $styled('excl', $align_right),
            $styled('%', $align_right),
            str_pad('name', $padding_name),
        ]);
        $table->setRows($rows);
        $table->setStyle('compact');
        $table->getStyle()->setCellHeaderFormat('%s');
        $table->render();
        $output->overwrite(
            preg_replace(
                '/( *total_incl.*)/',
                '<bg=bright-white;fg=black>$1</>',
                preg_replace(
                    '/\e[[][A-Za-z0-9]*;?[0-9]*m?/',
                    '',
                    $output->getContent()
                )
            )
        );
    }
}
