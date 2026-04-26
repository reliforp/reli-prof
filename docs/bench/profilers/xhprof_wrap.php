<?php
// auto_prepend wrapper that turns xhprof on for the entire script run
// and dumps the result to /tmp on shutdown. We use TIDEWAYS_XHPROF_FLAGS
// constants if available, falling back to upstream.
$flags = (defined('XHPROF_FLAGS_CPU') ? XHPROF_FLAGS_CPU : 0)
       | (defined('XHPROF_FLAGS_MEMORY') ? XHPROF_FLAGS_MEMORY : 0);
xhprof_enable($flags);
register_shutdown_function(function () {
    $data = xhprof_disable();
    file_put_contents('/tmp/bench-xhprof.out', serialize($data));
});
