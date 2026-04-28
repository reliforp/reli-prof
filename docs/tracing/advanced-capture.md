# Advanced capture: opcodes, native traces, JIT

Beyond the default call stacks, `inspector:trace` / `inspector:daemon`
can attach three extra layers of detail to each sample:

- **Executing opcode** — which Zend VM instruction was running
- **Native (C-level) stack** — merged PHP + interpreter frames
- **JIT-compiled function names** — resolve opcache-JIT frames to
  symbolic names

All three work with every output format (`.rbt`, `template:phpspy*`,
`json_lines`) and on top of `inspector:daemon` the same way.

## Executing opcode

Useful when you want to know not only which line is slow but which
**Zend VM opcode** is being dispatched. Different output paths expose
it differently:

- **Capturing to `.rbt`**: the opcode is recorded on every PHP frame
  unconditionally — no flag needed at capture time. Reveal it during
  analysis with `./reli rbt:analyze --with-opcode < trace.rbt`, or
  press `c` inside `rbt:explore` to toggle the opcode column.
- **phpspy text output**: opt in at capture time with
  `--output-format=template:phpspy_with_opcode`:

  ```bash
  $ sudo php ./reli i:trace --output-format=template:phpspy_with_opcode -p <pid>
  0 <VM>::ZEND_ASSIGN <VM>:-1
  1 Mandelbrot::iterate /home/sji/work/test/mandelbrot.php:33:ZEND_ASSIGN
  2 Mandelbrot::__construct /home/sji/work/test/mandelbrot.php:12:ZEND_DO_FCALL
  3 <main> /home/sji/work/test/mandelbrot.php:45:ZEND_DO_FCALL
  ```

  The currently executing opcode becomes the first frame of the
  callstack, so visualisations like flamegraph show opcode usage
  directly.

  For informational purposes, the executing opcode is also appended
  to the end of each call frame line (second position onward).
  Expect function-call opcodes like `ZEND_DO_FCALL` there.

If JIT is enabled on the target, line / opcode information may be
slightly inaccurate. For JIT-compiled function names specifically,
combine `--with-native-trace` with `opcache.jit_debug=0x10` (see
below).

## Native (C-level) stack traces

Add `--with-native-trace` to capture interpreter C frames alongside
the PHP stack. Useful for diagnosing time spent inside the engine,
extensions, or libc.

```bash
$ sudo php ./reli i:trace --with-native-trace -p <pid>
0 libc.so.6::clock_nanosleep+0x5a [native]:0
1 libc.so.6::__nanosleep+0x17 [native]:0
2 libc.so.6::usleep+0x4c [native]:0
3 php8.4::zif_usleep+0x42 [native]:0
4 usleep <internal>:-1
5 <main> /app/test.php:15
6 php8.4::execute_ex+0x4dfa [native]:0
7 php8.4::zend_execute+0x141 [native]:0
8 php8.4::zend_execute_script+0x56 [native]:0
9 php8.4::php_execute_script_ex+0x278 [native]:0
10 libc.so.6::__libc_start_main+0x8b [native]:0
11 php8.4::_start+0x25 [native]:0
```

Native frames are labeled with `[native]:0` and show
`module::symbol+offset`. PHP frames are placed on the callee side of
`execute_ex`, reflecting that all PHP execution happens inside the
VM's opcode dispatcher.

> **Stop-process is required.** Native unwinding needs the target's
> register state (`ptrace(PTRACE_GETREGS)`), which is only available
> while the target is `SIGSTOP`-ped. `--with-native-trace` /
> `--native-trace-anytime` therefore implicitly enable
> `--stop-process` (`-S`) and reli prints
> `Implicitly enabling --stop-process (-S).` on startup. There is no
> non-stop variant; if you cannot afford the per-sample stop, leave
> the flag off and capture PHP-only frames.

Capture to `.rbt` and analyse interactively:

```bash
$ sudo php ./reli i:trace --with-native-trace -p <pid> -o trace.rbt
$ ./reli rbt:explore trace.rbt
# ...or convert to a flamegraph:
$ ./reli converter:flamegraph <trace.rbt >flame_native.svg
```

### Symbol resolution specifics

- **Stripped binaries** are supported — reli uses exported symbols
  from `.dynsym`.
- **Separate debug symbol packages** (`-dbgsym` / `-debuginfo`) are
  loaded when available for full symbol coverage.
- **JIT-compiled function names** resolve via `/tmp/perf-<pid>.map`
  when the target has `opcache.jit_debug=0x10` (see the JIT section
  below).

> Distro-built PHP binaries (e.g. Debian / Ubuntu `php8.X-cli`) only
> export a small set of symbols in `.dynsym`. The internal call
> implementations of standard library functions (`zif_usleep`,
> `zif_strlen`, …) and most engine internals are **not** exported,
> so they show up as `php8.X::0x<address> [native]:0` rather than
> the symbolic names in the example above. Install the matching
> `php8.X-dbgsym` / `-debuginfo` package to get full coverage; the
> example above was captured against a debug-symbol-equipped PHP.

### Native traces during interpreter init / shutdown

By default, reli only collects native frames at samples where a PHP
frame is also being sampled. Use `--native-trace-anytime` to collect
native traces even when no PHP code is executing — e.g. during module
initialisation or interpreter shutdown:

```bash
$ sudo php ./reli i:trace --native-trace-anytime -p <pid>
```

Useful for investigating startup performance or extension loading
behaviour.

### Alpine / musl libc

Native C-level stack traces are **not supported on musl libc** due
to its minimal `.eh_frame` unwinding metadata (only ~4 FDE entries
versus glibc's ~3,700). The sampling profiler and memory pipeline
still work on Alpine; only `--with-native-trace` / `--native-trace-anytime`
are affected. See [../internals/alpine-investigation.md](../internals/alpine-investigation.md)
for the full investigation.

## JIT-compiled code in native traces

When the target PHP process has opcache JIT enabled with
`opcache.jit_debug=0x10`, JIT-compiled function names are resolved
through `/tmp/perf-<pid>.map`:

```bash
$ php -d opcache.jit_debug=0x10 script.php &
$ sudo php ./reli i:trace --with-native-trace -p $!
0 [jit]::TRACE-2$fibonacci$4+0x141 [native]:0
1 php8.4::zend_execute+0x141 [native]:0
2 <main> /app/test.php:14
```

For DWARF-based unwinding *through* JIT frames (i.e. correct stack
reconstruction when a JIT trampoline calls back into the engine),
set `opcache.jit_debug=0x100` instead — this enables the GDB JIT
interface.

## See also

- [rbt-analyze-and-explore.md](rbt-analyze-and-explore.md) — TUI /
  analyser for the captured traces (including the `--with-opcode`
  flag and the `c` toggle).
- [binary-trace-format.md](binary-trace-format.md) — `.rbt` format
  specification, including how opcode / native / annotation data is
  encoded.
- [../inspection/trace-var-command.md](../inspection/trace-var-command.md)
  — a different kind of per-sample annotation (runtime variable
  values).
