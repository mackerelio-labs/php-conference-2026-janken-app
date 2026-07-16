<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->string('id')->primary(); // ULID(例: g_01jz...)
            $table->string('difficulty'); // ok / warn / critical
            $table->double('time_limit_ms'); // 開始時点の制限時間を確定保存(後から難易度調整しても判定根拠が残る)
            $table->string('cpu_hand'); // rock / scissors / paper。ゲーム開始時に抽選
            $table->string('result')->nullable(); // win / lose。判定前は NULL
            $table->string('result_reason')->nullable(); // hand / copy / wrong_hand / late / foul / timeout
            $table->double('signal_delay_ms')->nullable(); // 「けん」→「ぽん!」の抽選値
            $table->double('countdown_started_at')->nullable(); // エポック ms(クライアント計測)
            $table->double('signal_at')->nullable();
            $table->string('trace_id')->nullable(); // ゲームトレースの trace_id
            $table->timestamp('judged_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
