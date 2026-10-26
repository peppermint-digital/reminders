<?php

namespace Peppermint\Reminders\Tests\Support;

use Peppermint\Reminders\Delivery\ErinnerungsVerweis;
use Peppermint\Reminders\Models\Reminder;

/**
 * Steht fuer das, was ein Produkt bindet — im CRM der Knopf „Aufgabe oeffnen".
 */
class VerweisAufDenVorgang implements ErinnerungsVerweis
{
    public function fuer(Reminder $erinnerung): array
    {
        return [
            'link' => '/vorgaenge/'.$erinnerung->subject_id,
            'icon' => 'bell-ring',
            'title' => 'Erinnerung: '.$erinnerung->title,
        ];
    }
}
