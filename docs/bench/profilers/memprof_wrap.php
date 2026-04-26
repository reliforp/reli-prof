<?php
// auto_prepend wrapper: enable memprof for full alloc/free tracking.
memprof_enable();
register_shutdown_function(function () {
    $data = memprof_dump_array();
    file_put_contents('/tmp/bench-memprof.out', serialize($data));
});
