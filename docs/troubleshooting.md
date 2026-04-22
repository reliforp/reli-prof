# Troubleshooting

## I get an error message "php module not found" and can't get a trace!

If your PHP binary uses a non-standard binary name that does not end with `/php`, use the `--php-regex` option to specify the name of the executable (or shared object) that contains the PHP interpreter.

## I don't think the trace is accurate.

The `-S` option will give you better results. Using this option stops the execution of the target process for a moment at every sampling, but the trace obtained will be more accurate. If you don't stop the VMs from running when profiling CPU-heavy programs such as benchmarking programs, you may misjudge the bottleneck, because you will miss more VM states that transition very quickly and are not detected well.

## I can't get traces on Amazon Linux 2.

First, try `cat /proc/<pid>/maps` to check the memory map of the target PHP process. If the first module does not indicate the location of the PHP binary and looks like an anonymous region, try to specify `--php-regex="^$"` as an option.
