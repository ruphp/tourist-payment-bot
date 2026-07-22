<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UonBinding extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'contract_number',
        'phone',
        'uon_request_id',
        'uon_client_id',
        'last_request_snapshot',
        'last_synced_at',
    ];

    protected $casts = [
        'last_request_snapshot' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function tochkaPayments(): HasMany
    {
        return $this->hasMany(TochkaPayment::class);
    }
}
