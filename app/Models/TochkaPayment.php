<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TochkaPayment extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'uon_binding_id',
        'uon_request_id',
        'contract_number',
        'amount',
        'currency',
        'status',
        'payment_link_id',
        'operation_id',
        'payment_url',
        'tochka_payload',
        'paid_at',
        'uon_payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tochka_payload' => 'array',
        'paid_at' => 'datetime',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function uonBinding(): BelongsTo
    {
        return $this->belongsTo(UonBinding::class);
    }
}
