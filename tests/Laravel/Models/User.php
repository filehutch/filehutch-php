<?php

declare(strict_types=1);

namespace FileHutch\Tests\Laravel\Models;

use FileHutch\Laravel\HasHutch;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasHutch;

    protected $table = 'users';
    protected $guarded = [];

    protected function hutches(): array
    {
        return [
            'avatar' => 'avatars',
            'contract' => ['policy' => 'documents', 'dependent' => false],
        ];
    }
}
