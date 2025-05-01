<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Batch extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'created_by'];

    public function students()
    {
        return $this->belongsToMany(User::class, 'batch_user');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
