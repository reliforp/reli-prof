# Troubleshooting

## I get an error message "php module not found" and can't get a trace!

If your PHP binary uses a non-standard binary name that does not end with `/php`, use the `--php-regex` option to specify the name of the executable (or shared object) that contains the PHP interpreter.

For FrankenPHP specifically, PHP is loaded as `libphp.so` and pthread lives in `libc.so`; see [tracing/frankenphp.md](tracing/frankenphp.md) for the full set of flags (`--php-regex`, `--libpthread-regex`, `--target-thread-regex`).

## I don't think the trace is accurate.

The `-S` option will give you better results. Using this option stops the execution of the target process for a moment at every sampling, but the trace obtained will be more accurate. If you don't stop the VMs from running when profiling CPU-heavy programs such as benchmarking programs, you may misjudge the bottleneck, because you will miss more VM states that transition very quickly and are not detected well.

## I can't get traces on Amazon Linux 2.

First, try `cat /proc/<pid>/maps` to check the memory map of the target PHP process. If the first module does not indicate the location of the PHP binary and looks like an anonymous region, try to specify `--php-regex="^$"` as an option.

## Something seems stale after a PHP upgrade or container rebuild.

reli caches expensive binary-analysis results (ELF symbol resolution, ZTS TLS offsets, PHP version detection) under `~/.cache/reli/binary-analysis/`. If you suspect a stale entry is being served — after upgrading the target PHP, rebuilding a container image, or any other "shouldn't that have worked?" moment — drop or bypass the cache:

```bash
./reli cache:clear                              # drop everything
./reli inspector:trace --no-cache -p <pid>      # bypass for one invocation
```

For what is cached and how keys are computed, see [internals/binary-analysis-cache.md](internals/binary-analysis-cache.md).
