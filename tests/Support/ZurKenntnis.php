<?php

namespace Peppermint\Reminders\Tests\Support;

use Peppermint\Reminders\Status\ReminderStatus;

/**
 * „Gesehen" — und damit NICHT mehr wartend.
 *
 * Steht hier, weil das CRM genau diese Unterscheidung macht: Es filtert
 * `whereNotIn('status', ['dismissed', 'acknowledged'])`. „Ich habe das
 * gesehen" und „wirf das weg" sind verschiedene Antworten, und beide haben
 * aufgehoert zu warten.
 */
class ZurKenntnis extends ReminderStatus
{
    public function key(): string
    {
        return 'acknowledged';
    }

    public function label(): string
    {
        return 'Zur Kenntnis genommen';
    }

    public function wartetNoch(): bool
    {
        return false;
    }
}
