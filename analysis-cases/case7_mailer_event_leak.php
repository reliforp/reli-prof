<?php
/**
 * Case 7: Symfony Mailer Event Dispatcher Memory Leak
 * Ref: symfony/symfony#59702
 * Root cause: Event dispatcher clones message+envelope, retaining references.
 */
class EmailMessage {
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public array $headers = [],
    ) {}
}

class Envelope {
    public function __construct(
        public string $sender,
        public array $recipients = [],
        public array $metadata = [],
    ) {}
}

class MessageEvent {
    public function __construct(
        public EmailMessage $message,
        public Envelope $envelope,
        public string $transport = 'smtp',
    ) {}
}

class EventDispatcher {
    /** @var array<string, list<callable>> */
    private array $listeners = [];
    private array $dispatchedEvents = []; // leak: retains all events

    public function addListener(string $event, callable $listener): void {
        $this->listeners[$event][] = $listener;
    }
    public function dispatch(object $event): void {
        $this->dispatchedEvents[] = $event; // Reference retained
        foreach ($this->listeners[get_class($event)] ?? [] as $listener) {
            $listener($event);
        }
    }
}

class Mailer {
    public function __construct(
        private EventDispatcher $dispatcher,
    ) {}

    public function send(EmailMessage $message, ?Envelope $envelope = null): void {
        $envelope ??= new Envelope('noreply@example.com', [$message->to]);
        // Bug: clones message for event, dispatcher retains it
        $clonedMessage = clone $message;
        $event = new MessageEvent($clonedMessage, clone $envelope);
        $this->dispatcher->dispatch($event);
    }
}

$dispatcher = new EventDispatcher();
$dispatcher->addListener(MessageEvent::class, function (MessageEvent $e) {});
$mailer = new Mailer($dispatcher);

posix_kill(posix_getpid(), SIGSTOP);

for ($i = 0; $i < 3000; $i++) {
    $mailer->send(
        new EmailMessage("user{$i}@example.com", "Subject {$i}", str_repeat("Body content ", 100)),
        new Envelope('sender@example.com', ["user{$i}@example.com"], ['id' => $i]),
    );
}
echo "Memory: " . number_format(memory_get_usage(true)) . "\n";
sleep(300);
