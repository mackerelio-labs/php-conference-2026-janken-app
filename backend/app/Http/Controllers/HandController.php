<?php

namespace App\Http\Controllers;

use App\Models\Game;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HandController extends Controller
{
    public function store(Request $request, Game $game): Response
    {
        $receivedAt = microtime(true) * 1000;

        $validated = $request->validate([
            'hand' => ['required', 'string', 'in:rock,scissors,paper'],
            'pressed_at' => ['required', 'numeric', 'gt:0'],
        ]);

        if ($game->isJudged()) {
            abort(409, 'このゲームは判定済みです');
        }

        try {
            $game->hand()->create([...$validated, 'received_at' => $receivedAt]);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'このゲームの手は既に記録されています');
        }

        return response('', 201);
    }
}
