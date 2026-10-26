<?php

namespace Peppermint\Reminders\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A second, unrelated kind of subject — stands in for a calendar event.
 *
 * Its whole purpose is that it has nothing to do with {@see Vorgang}. A
 * package that claims to hang a reminder on anything has to be shown doing it
 * on two things that share no table, no base class and no column.
 */
class Termin extends Model
{
    protected $table = 'termine';

    protected $guarded = [];
}
