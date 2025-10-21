<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Import extends Model
{
    protected $table = 'imports';

    protected $fillable = [
        'importable_type',
        'file_signature',
        'file_path',
        'status',
        'total_rows',
        'metadata',
        'data',
    ];

    protected $casts = [
        'metadata' => 'array',
        'data' => 'array',
    ];

    public static function fileExists(string $signature): bool
    {
        return self::where('file_signature', $signature)->exists();
    }

    public static function getBySignature(string $signature)
    {
        return self::where('file_signature', $signature)->first();
    }
}
