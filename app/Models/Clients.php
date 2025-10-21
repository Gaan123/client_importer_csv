<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clients extends Model
{
    protected $table = 'clients';

    protected $fillable = [
        'company',
        'email',
        'phone',
        'has_duplicates',
        'extras',
    ];

    protected $casts = [
        'has_duplicates' => 'boolean',
        'extras' => 'array',
    ];
}
