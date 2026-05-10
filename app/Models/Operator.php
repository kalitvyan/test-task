<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    protected $fillable = [
        'available',
        'last_call_at'
    ];

    protected $casts = [
        'available' => 'boolean',
        'last_call_at' => 'datetime',
    ];

    public function markBusy(): void
    {
        $this->available = false;
        $this->last_call_at = now();

        $this->save();
    }
}
