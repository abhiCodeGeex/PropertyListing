<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'property_id',
        'file_path',
        'file_url',
        'file_type',
        'mime_type',
        'uploaded_by',
    ];

    public function property()
    {
        return $this->belongsTo(Property::class);
    }
}
