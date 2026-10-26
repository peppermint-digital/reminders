<?php

namespace Peppermint\Reminders\Tests\Support;

use Peppermint\Reminders\Status\ReminderStatus;

class Wartend extends ReminderStatus
{
    public function key(): string
    {
        return 'pending';
    }

    public function label(): string
    {
        return 'Offen';
    }

    public function wartetNoch(): bool
    {
        return true;
    }
}
