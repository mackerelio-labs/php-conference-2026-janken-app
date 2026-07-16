<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Game extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'time_limit_ms' => 'float',
            'signal_delay_ms' => 'float',
            'countdown_started_at' => 'float',
            'signal_at' => 'float',
            'judged_at' => 'datetime',
        ];
    }

    public function hand(): HasOne
    {
        return $this->hasOne(Hand::class);
    }

    public function isJudged(): bool
    {
        return $this->result !== null;
    }
}
