<?php

namespace Peppermint\Reminders\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Stands in for the product's user model, whatever it is called there. */
class Person extends Model
{
    protected $table = 'personen';

    protected $guarded = [];
}
