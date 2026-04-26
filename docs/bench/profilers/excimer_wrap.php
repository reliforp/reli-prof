<?php
// auto_prepend wrapper: start a 1ms-period wall-time Excimer profiler
// that runs for the duration of the script.
$prof = new ExcimerProfiler();
$prof->setPeriod(0.001);
$prof->setEventType(EXCIMER_REAL);
$prof->start();
register_shutdown_function(function () use ($prof) {
    $prof->stop();
    file_put_contents('/tmp/bench-excimer.txt',
        $prof->getLog()->formatCollapsed());
});
