<?php
declare(strict_types=1);

namespace BaileysPHP\Event;

/**
 * Minimal Node-EventEmitter equivalent used by the Baileys API surface
 * ($sock->ev->on('messages.upsert', ...)).
 *
 * PHP note: Baileys (Node) is async; this port is a blocking CLI event loop.
 * Listeners are called synchronously in registration order.
 */
final class EventEmitter
{
    /** @var array<string, list<callable>> */
    private array $listeners = [];

    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    public function once(string $event, callable $listener): void
    {
        $wrapper = null;
        $wrapper = function (mixed ...$args) use (&$wrapper, $event, $listener) {
            $this->off($event, $wrapper);
            $listener(...$args);
        };
        $this->on($event, $wrapper);
    }

    public function off(string $event, ?callable $listener = null): void
    {
        if ($listener === null) {
            unset($this->listeners[$event]);
            return;
        }
        foreach ($this->listeners[$event] ?? [] as $i => $l) {
            if ($l === $listener) {
                unset($this->listeners[$event][$i]);
            }
        }
    }

    public function removeAllListeners(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }
    }

    /** @return bool whether any listener was triggered (like Node emit) */
    public function emit(string $event, mixed ...$args): bool
    {
        $any = false;
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $any = true;
            $listener(...$args);
        }
        return $any;
    }

    public function listenerCount(string $event): int
    {
        return count($this->listeners[$event] ?? []);
    }
}
