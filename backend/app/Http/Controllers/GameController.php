<?php

namespace App\Http\Controllers;

use App\Game\Difficulty;
use App\Game\GameTelemetry;
use App\Game\JudgeResult;
use App\Game\JudgeService;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    private const HANDS = ['rock', 'scissors', 'paper'];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'difficulty' => ['required', Rule::enum(Difficulty::class)],
        ]);
        $difficulty = Difficulty::from($validated['difficulty']);

        // CPU の手は開始時に抽選して確定保存する(docs/game-spec.md の「CPU の手の決め方」)
        $game = Game::create([
            'id' => 'g_'.strtolower((string) Str::ulid()),
            'difficulty' => $difficulty->value,
            'time_limit_ms' => $difficulty->timeLimitMs(),
            'cpu_hand' => self::HANDS[random_int(0, 2)],
        ]);

        return response()->json([
            'game_id' => $game->id,
            'cpu_hand' => $game->cpu_hand,
            'time_limit_ms' => $game->time_limit_ms,
        ], 201);
    }

    public function judge(Request $request, Game $game, JudgeService $judgeService, GameTelemetry $telemetry): JsonResponse
    {
        $judgeStartMs = microtime(true) * 1000; // janken.judge スパンの開始時刻

        $validated = $request->validate([
            'countdown_started_at' => ['required', 'numeric', 'gt:0'],
            'ken_at' => ['required', 'numeric', 'gte:countdown_started_at'],
            'signal_at' => ['required', 'numeric', 'gte:ken_at'],
            'hand' => ['present', 'nullable', 'array'],
            'hand.hand' => ['required_with:hand', 'string', 'in:rock,scissors,paper'],
            'hand.pressed_at' => ['required_with:hand', 'numeric', 'gt:0'],
        ]);

        // 判定済みへの再実行は保存済みの結果を返す(冪等)
        if ($game->isJudged()) {
            return response()->json($this->responseFromStoredGame($game));
        }

        $result = $judgeService->judge(
            $validated['signal_at'],
            $game->cpu_hand,
            $game->time_limit_ms,
            $validated['hand'],
        );

        // 並行リクエストが同時に上の判定済みチェックを通過しても、行ロック下の再確認で
        // 最初に確定した結果だけが保存される(後続はここで冪等応答に落ちる)
        $judgedByAnotherRequest = DB::transaction(function () use ($game, $result, $validated): bool {
            $locked = Game::whereKey($game->id)->lockForUpdate()->first();
            if ($locked->isJudged()) {
                return true;
            }

            // judge ペイロードが判定の正。hands API の記録(received_at)があれば保持される
            if ($validated['hand'] !== null) {
                $game->hand()->updateOrCreate([], [
                    'hand' => $validated['hand']['hand'],
                    'pressed_at' => $validated['hand']['pressed_at'],
                ]);
                $game->hand()->update(['reaction_ms' => $result->reactionMs]);
            }
            $game->fill([
                'result' => $result->result,
                'result_reason' => $result->reason,
                'signal_delay_ms' => $validated['signal_at'] - $validated['ken_at'],
                'countdown_started_at' => $validated['countdown_started_at'],
                'signal_at' => $validated['signal_at'],
                'judged_at' => now(),
            ])->save();

            return false;
        });

        if ($judgedByAnotherRequest) {
            return response()->json($this->responseFromStoredGame($game->refresh()));
        }

        // テレメトリの失敗でゲーム進行を止めない
        $traceId = null;
        try {
            $row = $game->hand()->first();
            $handForTelemetry = $row === null ? null : [
                'hand' => $row->hand,
                'pressed_at' => $row->pressed_at,
                'received_at' => $row->received_at,
            ];
            $traceId = $telemetry->emitGame($game, $result, [
                'countdown_started_at' => $validated['countdown_started_at'],
                'ken_at' => $validated['ken_at'],
                'signal_at' => $validated['signal_at'],
            ], $handForTelemetry, $judgeStartMs);
            $game->update(['trace_id' => $traceId]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($this->buildResponse($game, $result, $traceId));
    }

    private function buildResponse(Game $game, JudgeResult $result, ?string $traceId): array
    {
        return [
            'result' => $result->result,
            'reason' => $result->reason,
            'cpu_hand' => $game->cpu_hand,
            'hand' => $result->hand,
            'reaction_ms' => $result->reactionMs === null ? null : round($result->reactionMs, 1),
            'margin_ms' => $result->marginMs === null ? null : round($result->marginMs, 1),
            'time_limit_ms' => $game->time_limit_ms,
            'trace_id' => $traceId,
        ];
    }

    private function responseFromStoredGame(Game $game): array
    {
        $row = $game->hand()->first();

        return [
            'result' => $game->result,
            'reason' => $game->result_reason,
            'cpu_hand' => $game->cpu_hand,
            'hand' => $row?->hand,
            'reaction_ms' => $row?->reaction_ms === null ? null : round($row->reaction_ms, 1),
            'margin_ms' => $row?->reaction_ms === null ? null : round($game->time_limit_ms - $row->reaction_ms, 1),
            'time_limit_ms' => $game->time_limit_ms,
            'trace_id' => $game->trace_id,
        ];
    }
}
