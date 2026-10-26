<?php

namespace Peppermint\Reminders\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** One kind of subject — stands in for a task. */
class Vorgang extends Model
{
    protected $table = 'vorgaenge';

    protected $guarded = [];
}
