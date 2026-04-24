<?php

declare(strict_types=1);

namespace Reli\Inspector\Settings\SidecarSettings;

use Mockery;
use Reli\BaseTestCase;
use Symfony\Component\Console\Input\InputInterface;

class SidecarSettingsFromConsoleInputTest extends BaseTestCase
{
    private ?string $xdgBackup;
    private string $xdgScratch;

    protected function setUp(): void
    {
        parent::setUp();
        $xdg = getenv('XDG_RUNTIME_DIR');
        $this->xdgBackup = is_string($xdg) ? $xdg : null;
        $this->xdgScratch = sys_get_temp_dir() . '/reli-sidecar-default-' . getmypid() . '-' . mt_rand();
        mkdir($this->xdgScratch, 0o700);
        putenv('XDG_RUNTIME_DIR=' . $this->xdgScratch);
    }

    protected function tearDown(): void
    {
        if ($this->xdgBackup !== null) {
            putenv('XDG_RUNTIME_DIR=' . $this->xdgBackup);
        } else {
            putenv('XDG_RUNTIME_DIR');
        }
        @rmdir($this->xdgScratch);
        parent::tearDown();
    }

    public function testCreateSettingsDefaultsResolveSocketFromXdgRuntimeDir(): void
    {
        $input = Mockery::mock(InputInterface::class);
        // --socket not specified → null → resolver kicks in.
        $input->expects()->getOption('socket')->andReturns(null);
        $input->expects()->getOption('output-dir')->andReturns('.');
        $input->expects()->getOption('disk-usage-limit')->andReturns('1G');
        $input->expects()->getOption('include-binary')->andReturns(false);
        $input->expects()->getOption('memory-limit')->andReturns(null);
        $input->expects()->getOption('tag')->andReturns([]);

        $settings = (new SidecarSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame(
            $this->xdgScratch . '/reli/sidecar.sock',
            $settings->socket_path,
        );
        $this->assertSame('.', $settings->output_dir);
        $this->assertSame(1073741824, $settings->disk_usage_limit_bytes);
        $this->assertFalse($settings->include_binary);
        $this->assertNull($settings->memory_limit);
        $this->assertSame([], $settings->tags);
    }

    public function testCreateSettingsCustomValues(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('socket')->andReturns('/tmp/custom.sock');
        $input->expects()->getOption('output-dir')->andReturns('/tmp/dumps');
        $input->expects()->getOption('disk-usage-limit')->andReturns('512M');
        $input->expects()->getOption('include-binary')->andReturns(true);
        $input->expects()->getOption('memory-limit')->andReturns('2G');
        $input->expects()->getOption('tag')->andReturns(['product=my-app', 'version=2.4.0']);

        $settings = (new SidecarSettingsFromConsoleInput())->createSettings($input);

        $this->assertSame('/tmp/custom.sock', $settings->socket_path);
        $this->assertSame('/tmp/dumps', $settings->output_dir);
        $this->assertSame(512 * 1024 * 1024, $settings->disk_usage_limit_bytes);
        $this->assertTrue($settings->include_binary);
        $this->assertSame('2G', $settings->memory_limit);
        $this->assertSame(['product' => 'my-app', 'version' => '2.4.0'], $settings->tags);
    }

    public function testTagParsingIgnoresInvalidFormat(): void
    {
        $input = Mockery::mock(InputInterface::class);
        $input->expects()->getOption('socket')->andReturns('/s');
        $input->expects()->getOption('output-dir')->andReturns('.');
        $input->expects()->getOption('disk-usage-limit')->andReturns('1G');
        $input->expects()->getOption('include-binary')->andReturns(false);
        $input->expects()->getOption('memory-limit')->andReturns(null);
        $input->expects()->getOption('tag')->andReturns(['good=value', 'no-equals-sign']);

        $settings = (new SidecarSettingsFromConsoleInput())->createSettings($input);

        // Only valid key=value pairs are included
        $this->assertSame(['good' => 'value'], $settings->tags);
    }
}
