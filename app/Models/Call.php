<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    protected $fillable = [
        'phone',
        'status',
        'client_id',
        'operator_id'
    ];

    protected $casts = [
        'status' => CallStatus::class,
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    public function assignTo(Operator $operator): void
    {
        $this->operator_id = $operator->id;
        $this->status = CallStatus::Assigned;

        $this->save();
    }
}
