<?php

namespace Peppermint\Reminders\Tests\Support;

use Peppermint\Reminders\Status\ReminderStatus;

class Weggeworfen extends ReminderStatus
{
    public function key(): string
    {
        return 'dismissed';
    }

    public function label(): string
    {
        return 'Verworfen';
    }

    public function wartetNoch(): bool
    {
        return false;
    }
}
