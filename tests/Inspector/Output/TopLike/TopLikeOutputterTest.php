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

use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Formatter\WrappableOutputFormatterInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Terminal;

class TopLikeOutputterTest extends TestCase
{
    public function testDisplay()
    {
        $output = Mockery::mock(ConsoleOutputInterface::class);
        $terminal = Mockery::mock(Terminal::class);
        $outputter = new TopLikeOutputter(
            $output,
            $terminal
        );

        $output->expects()->write("\e[H\e[2J");
        $output->expects()->writeln('target_regex');
        $output->expects()->writeln('samp_count=0  func_count=0  total_count=0');
        $output->expects()->writeln('');
        $terminal->expects()->getWidth()->andReturns(80);
        $terminal->expects()->getHeight()->andReturns(20);
        $output->expects()
            ->section()
            ->andReturns(
                $section = Mockery::mock(ConsoleSectionOutput::class)
            )
        ;
        $section->expects()
            ->getFormatter()
            ->andReturns(
                $formatter = new OutputFormatter()
            )
        ;
        $section
            ->expects()
            ->writeln(
                \Mockery::on(function ($argument) {
                    $this->assertStringContainsString('total_incl', $argument);
                    return true;
                })
            )
        ;
        $section
            ->expects()
            ->getContent()
            ->andReturns('content_rendered')
        ;
        $section
            ->expects()
            ->overwrite('content_rendered')
        ;

        $outputter->display('target_regex', new Stat());
    }
}
