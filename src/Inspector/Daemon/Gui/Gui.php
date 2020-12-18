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

namespace PhpProfiler\Inspector\Daemon\Gui;

use Gtk\Atk;
use Gtk\FFI;
use Gtk\Pango;
use Gtk\PHPGtk;
use Gtk\Pixbuf;

final class Gui
{
    private PHPGtk $gtk;
    private Pango $pango;
    private Atk $atk;
    private Pixbuf $pixbuf;
    private $image_widget;

    public static function load(): self
    {
        $lib = '/usr/lib/x86_64-linux-gnu';
        return new self(
            PHPGtk::gtk($lib),
            PHPGtk::pango($lib),
            PHPGtk::atk($lib),
            PHPGtk::pixbuf($lib)
        );
    }

    public function __construct(
        PHPGtk $gtk,
        Pango $pango,
        Atk $atk,
        Pixbuf $pixbuf
    ) {
        $this->pixbuf = $pixbuf;
        $this->atk = $atk;
        $this->pango = $pango;
        $this->gtk = $gtk;
    }

    public function print_hello()
    {
        echo 'hello';
    }

    public function build()
    {
        global $argc;
        global $argv;
        $gtk = $this->gtk;
        $pixbuf = $this->pixbuf;

        $error = $gtk->ffi->new('GError*', false);
        $gtk->gdk_threads_init();
        $gtk->gtk_init($argc, $argv);
        $builder = $gtk->gtk_builder_new();

        if ($gtk->gtk_builder_add_from_file($builder, __DIR__ ."/buildinterface.ui", FFI::addr($error)) == 0) {
            $gtk->g_printerr("Error loading file: %s\n", $error->message);
            $gtk->g_clear_error(FFI::addr($error));
            return 1;
        }
        /* Connect signal handlers to the constructed widgets. */
        $image1 = $pixbuf->gdk_pixbuf_new_from_file('out.svg', FFI::addr($error));
        $this->image_widget = $image_widget = $gtk->gtk_image_new_from_pixbuf($image1);
        $window = $gtk->gtk_builder_get_object($builder, "window");
        $gtk->g_signal_connect($window, "destroy", $gtk->G_CALLBACK([$gtk, 'gtk_main_quit', true]), NULL);
        $button = $gtk->gtk_builder_get_object($builder, "button1");
        $gtk->g_signal_connect($button, "clicked", $gtk->G_CALLBACK([$this, 'print_hello']), NULL);
        $button = $gtk->gtk_builder_get_object($builder, "button2");
        $gtk->g_signal_connect($button, "clicked", $gtk->G_CALLBACK([$this, 'print_hello']), NULL);
        $image_box = $gtk->GTK_CONTAINER($gtk->gtk_builder_get_object($builder, "image"));
        $gtk->gtk_container_add($image_box, $image_widget);
        $button = $gtk->gtk_builder_get_object($builder, "quit");
        $gtk->g_signal_connect($button, "clicked", $gtk->G_CALLBACK(fn () => $this->quit()), NULL);

        $gtk->gtk_widget_show_all($gtk->GTK_WIDGET($window));
    }

    public function refreshImage()
    {
        $gtk = $this->gtk;
        $pixbuf = $this->pixbuf;
        $error = $gtk->ffi->new('GError*', false);
        $image = $pixbuf->gdk_pixbuf_new_from_file('out.svg', FFI::addr($error));
        $gtk->gtk_image_set_from_pixbuf($gtk->GTK_IMAGE($this->image_widget), $image);
    }


    public bool $isDone = false;
    public function quit()
    {
        $this->isDone = true;
        return $this->gtk->gtk_main_quit();
    }


    public function step()
    {
        $gtk = $this->gtk;
        while ($gtk->gtk_events_pending()) {
            $gtk->gtk_main_iteration_do(false);
        }
    }

    public function run()
    {
        $gtk = $this->gtk;
        $gtk->gdk_threads_enter();
        $gtk->gtk_main();
        $gtk->gdk_threads_leave();
    }
}