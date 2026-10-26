<?php

namespace Peppermint\Reminders\Exceptions;

use InvalidArgumentException;

/**
 * A status that no application registered.
 *
 * Loud on purpose, and this one IS measured: writing an unregistered status
 * would leave a row whose state nobody can read. `scopeActive` would not
 * match it either way round, so the reminder would either nag forever or
 * reach nobody — and nothing would have gone wrong visibly.
 */
class UnknownReminderStatus extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $bekannt
     */
    public static function fuer(string $key, array $bekannt): self
    {
        return new self(sprintf(
            'Der Erinnerungs-Status [%s] ist nicht angemeldet. Bekannt: %s. '
            .'Vokabular in config(\'reminders.statuses\') eintragen.',
            $key,
            $bekannt === [] ? '(keiner)' : implode(', ', $bekannt)
        ));
    }
}
