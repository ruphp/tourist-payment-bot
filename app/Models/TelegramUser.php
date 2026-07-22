<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TelegramUser extends Model
{
    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'state',
        'state_data',
    ];

    protected $casts = [
        'state_data' => 'array',
    ];

    public function uonBinding(): HasOne
    {
        return $this->hasOne(UonBinding::class);
    }

    public function tochkaPayments(): HasMany
    {
        return $this->hasMany(TochkaPayment::class);
    }
}
