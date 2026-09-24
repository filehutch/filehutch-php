<?php

declare(strict_types=1);

namespace FileHutch\Tests\Laravel\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class SoftUser extends User
{
    use SoftDeletes;
}
