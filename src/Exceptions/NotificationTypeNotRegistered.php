<?php

namespace Peppermint\Reminders\Exceptions;

use RuntimeException;

/**
 * The bridge is on, but the product never registered the notification type.
 *
 * This is a loud failure on purpose, and it is the whole reason this class
 * exists. `peppermint/notifications` treats an unregistered type as one
 * nobody wants to see — so the delivery would run, report success, mark every
 * reminder as sent, and reach nobody.
 *
 * That is precisely the bug this package was extracted to fix (CRM Bug #880:
 * reminders stored, channels offered, nothing delivered, no error anywhere).
 * Reproducing it one layer up would be a poor joke.
 */
class NotificationTypeNotRegistered extends RuntimeException
{
    public static function fuer(string $type): self
    {
        return new self(
            "Die Meldungsart [{$type}] ist in config('notifications.types') nicht registriert. "
            ."Ohne Registrierung gilt sie als unsichtbar: Erinnerungen wuerden als zugestellt "
            ."vermerkt und erreichten niemanden. Art registrieren oder "
            ."config('reminders.delivery.bridge_notifications') abschalten."
        );
    }
}
