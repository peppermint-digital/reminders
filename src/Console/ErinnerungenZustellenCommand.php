<?php

namespace Peppermint\Reminders\Console;

use Illuminate\Console\Command;
use Peppermint\Reminders\Delivery\DueReminders;
use Peppermint\Reminders\Delivery\NotificationsBridge;

/**
 * Stellt faellige Erinnerungen zu — in jedem Produkt derselbe Befehl.
 *
 * ## Warum das hier liegt und nicht in jedem Produkt
 *
 * Weil es sonst viermal hier liegt. Am 26.10.2026 gemessen: Von vier
 * Produkten hatten ZWEI einen Zustell-Lauf (CRM, AI Brain) und zwei keinen.
 * Im Peppermint Manager konnte man eine Erinnerung ueber die Maske und ueber
 * ein MCP-Werkzeug anlegen, und niemand bekam sie je — Bug #880 unveraendert,
 * nur in einem anderen Fenster. Aufgefallen ist es nicht an 7781 gruenen
 * Tests, sondern daran, dass jemand gefragt hat, ob ueberall dasselbe
 * passiert.
 *
 * Das Paket trug die Abfrage und die Bruecke schon. Es fehlte die Huelle.
 *
 * ## Was NICHT hier liegt
 *
 * Der Zeitplan. Wie oft zugestellt wird, ist eine Produktentscheidung — alle
 * fuenf Minuten im AI Brain, viertelstuendlich im CRM —, und ein Paket, das
 * sich selbst in den Scheduler eintraegt, nimmt sie weg.
 */
class ErinnerungenZustellenCommand extends Command
{
    protected $signature = 'erinnerungen:zustellen {--limit=500 : Hoechstens so viele in einem Lauf}';

    protected $description = 'Stellt faellige Erinnerungen als Benachrichtigung zu und vermerkt, dass sie heraus sind.';

    public function handle(NotificationsBridge $bruecke, DueReminders $faellige): int
    {
        $wartend = $faellige->anzahl();

        if ($wartend === 0) {
            $this->components->info('Keine faelligen Erinnerungen.');

            return self::SUCCESS;
        }

        $zugestellt = $bruecke->zustellen((int) $this->option('limit'));

        $this->components->twoColumnDetail('faellig', (string) $wartend);
        $this->components->twoColumnDetail('zugestellt', (string) $zugestellt);

        // Der Rest gehoert GENANNT. Ein Deckel, der still kuerzt, liest sich
        // wie „alles erledigt" — und beim naechsten Lauf wieder.
        if ($zugestellt < $wartend) {
            $this->components->warn(
                ($wartend - $zugestellt).' warten noch — Deckel erreicht. Haeufiger laufen lassen oder --limit erhoehen.'
            );
        }

        return self::SUCCESS;
    }
}
