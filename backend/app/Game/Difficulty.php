<?php

namespace App\Game;

/**
 * 難易度。名称・制限時間は docs/game-spec.md の難易度の節を参照
 */
enum Difficulty: string
{
    case Ok = 'ok';
    case Warn = 'warn';
    case Critical = 'critical';

    public function timeLimitMs(): float
    {
        return match ($this) {
            self::Ok => 1200.0,
            self::Warn => 800.0,
            self::Critical => 500.0,
        };
    }

    public function label(): string
    {
        return strtoupper($this->value).' 級';
    }
}
