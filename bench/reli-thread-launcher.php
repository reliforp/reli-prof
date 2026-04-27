<?php

declare(strict_types=1);

/**
 * Wraps reli's CLI in the ffi-zts ZTS embed so amphp/parallel picks
 * ThreadContextFactory automatically. Forwards argv via env var because
 * Embed::runScript() does not propagate argv into the embedded interp.
 *
 * Setup (one-time):
 *
 *   1. Build a standalone ZTS PHP with the extensions reli needs
 *      configured as shared modules:
 *
 *        ./configure --enable-zts --enable-embed --disable-all \
 *            --enable-opcache --disable-opcache-jit --enable-ffi --with-ffi \
 *            --enable-pcntl --enable-mbstring --enable-tokenizer \
 *            --enable-phar --enable-session --enable-hash \
 *            --enable-filter=shared --with-iconv=shared
 *        make -j
 *
 *   2. Patch the resulting modules/*.so so they advertise libphp.so as a
 *      DT_NEEDED dependency (the released sj-i/ffi-zts libphp.so does not
 *      bake filter / iconv in, and unpatched extension .so's resolve to
 *      the host NTS PHP's symbols at MINIT time and segfault):
 *
 *        vendor/bin/ffi-zts patch-elf modules/filter.so \
 *            vendor/sj-i/ffi-zts/bin/extensions/ffi-zts/filter.so \
 *            --needed=libphp.so \
 *            --runpath=/abs/path/to/vendor/sj-i/ffi-zts/bin
 *        # ... same for any other shared ext you need
 *
 *   3. Run reli with this launcher:
 *
 *        php -d zend.max_allowed_stack_size=-1 \
 *            bench/reli-thread-launcher.php inspector:daemon \
 *            -P '...' -T 8 -F rbt -o /tmp/rbt-out
 *
 * The host -d flag is required because amphp/parallel's worker bootstrap
 * recurses past 8 MB of Zend stack when called from inside an FFI-driven
 * embed (the SAPI ub_write callback hops back into host PHP). Without it
 * the embed crashes with "Maximum call stack size of 8339456 bytes" while
 * still booting.
 */

require __DIR__ . '/../vendor/autoload.php';

use SjI\FfiZts\Extension\Extension;
use SjI\FfiZts\Parallel\Parallel;

$forward = $argv;
array_shift($forward); // drop launcher script path
putenv('RELI_THREAD_ARGV=' . json_encode($forward));

$extensionDir = __DIR__ . '/../vendor/sj-i/ffi-zts/bin/extensions/ffi-zts';
$embed = Parallel::boot()
    ->withIniEntry('zend.max_allowed_stack_size', '-1')
    ->withIniEntry('fiber.stack_size', '8M')
    ->withIniEntry('ffi.enable', 'true');

foreach (['filter', 'iconv'] as $extName) {
    $path = "{$extensionDir}/{$extName}.so";
    if (is_file($path)) {
        $embed = $embed->withExtension(new Extension(name: $extName, path: $path));
    }
}

$embed->runScript(__DIR__ . '/reli-thread-inner.php');
