<?php
/**
 * Case 15: Closure Circular Reference in Event System
 * Ref: PHP bug #69639, dev.to/gromnan article
 * Root cause: Closures capturing $this create cycles that GC may not collect promptly.
 */
class EventEmitter {
    /** @var array<string, list<\Closure>> */
    private array $listeners = [];
    private array $eventLog = [];

    public function on(string $event, \Closure $callback): void {
        $this->listeners[$event][] = $callback;
    }

    public function emit(string $event, array $data = []): void {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($data);
        }
        $this->eventLog[] = ['event' => $event, 'time' => microtime(true)];
    }

    public function getLogCount(): int { return count($this->eventLog); }
}

class Widget {
    private EventEmitter $emitter;
    private array $state = [];
    private string $largePayload;

    public function __construct(EventEmitter $emitter, int $id) {
        $this->emitter = $emitter;
        $this->largePayload = str_repeat("widget_{$id}_data_", 500);

        // Closure captures $this -> cycle: Widget -> EventEmitter -> Closure -> Widget
        $this->emitter->on('update', function (array $data) {
            $this->state[] = $data; // $this captured
        });
        $this->emitter->on('render', function (array $data) {
            return $this->largePayload; // $this captured
        });
    }
}

$emitter = new EventEmitter();
$widgets = [];

posix_kill(posix_getpid(), SIGSTOP);

for ($i = 0; $i < 2000; $i++) {
    $widget = new Widget($emitter, $i);
    $widgets[] = $widget;
    $emitter->emit('update', ['tick' => $i]);
}
echo "Widgets: " . count($widgets) . "\n";
echo "Events logged: " . $emitter->getLogCount() . "\n";
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
