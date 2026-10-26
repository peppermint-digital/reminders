<?php

namespace Peppermint\Reminders\Status;

use Peppermint\Reminders\Exceptions\UnknownReminderStatus;

/**
 * The reminder states this application knows.
 *
 * Empty is a valid answer, and it is what every product starts with: before a
 * vocabulary is registered the package falls back to the plain `is_dismissed`
 * boolean it shipped with. That fallback is not politeness — an empty registry
 * that was treated as "no state waits" would make `scopeActive` match nothing,
 * and the delivery would quietly stop delivering. See {@see istLeer()}.
 */
class StatusRegistry
{
    /** @var array<string, ReminderStatus> */
    private array $stati = [];

    public function register(ReminderStatus $status): void
    {
        $this->stati[$status->key()] = $status;
    }

    public function get(string $key): ReminderStatus
    {
        return $this->stati[$key] ?? throw UnknownReminderStatus::fuer($key, array_keys($this->stati));
    }

    public function has(string $key): bool
    {
        return isset($this->stati[$key]);
    }

    /**
     * Nothing registered — the product has not stated a vocabulary.
     *
     * Callers MUST ask this before reading the key lists. An empty list of
     * waiting keys is indistinguishable from "nothing waits", and acting on
     * that difference is the whole reason this method exists.
     */
    public function istLeer(): bool
    {
        return $this->stati === [];
    }

    /** @return array<string, ReminderStatus> */
    public function all(): array
    {
        return $this->stati;
    }

    /**
     * The states that still want something. The delivery's filter.
     *
     * @return array<int, string>
     */
    public function wartendeSchluessel(): array
    {
        return array_keys(array_filter($this->stati, fn (ReminderStatus $s) => $s->wartetNoch()));
    }

    /**
     * The states that have stopped waiting.
     *
     * @return array<int, string>
     */
    public function abgeschlosseneSchluessel(): array
    {
        return array_keys(array_filter($this->stati, fn (ReminderStatus $s) => ! $s->wartetNoch()));
    }
}
