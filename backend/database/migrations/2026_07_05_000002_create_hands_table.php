<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hands', function (Blueprint $table) {
            $table->id();
            $table->string('game_id');
            $table->string('hand'); // rock / scissors / paper
            $table->double('pressed_at'); // 押下時刻(エポック ms、クライアント計測)
            $table->double('received_at')->nullable(); // hands API のサーバー受信時刻。judge ペイロード由来の行は NULL
            $table->double('reaction_ms')->nullable(); // judge 時に確定。フライングは負値

            $table->foreign('game_id')->references('id')->on('games');
            $table->unique('game_id'); // 1 ゲーム 1 手。hands API の 409 の根拠
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hands');
    }
};
