<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GameApiTest extends TestCase
{
    use RefreshDatabase;

    /** key の手に勝つ手 */
    private const WINS_AGAINST = ['rock' => 'paper', 'scissors' => 'rock', 'paper' => 'scissors'];

    private const SIGNAL = 1_700_000_000_000.0;

    private function createGame(string $difficulty = 'warn'): array
    {
        $res = $this->postJson('/api/games', ['difficulty' => $difficulty])->assertCreated();

        return [$res->json('game_id'), $res->json('cpu_hand'), $res->json('time_limit_ms')];
    }

    private function judgePayload(?array $hand): array
    {
        return [
            'countdown_started_at' => self::SIGNAL - 2000,
            'ken_at' => self::SIGNAL - 1400,
            'signal_at' => self::SIGNAL,
            'hand' => $hand,
        ];
    }

    /** WARN 級(制限 800ms)で反応 300ms の勝ちゲームを 1 つ遊ぶ */
    private function playWinningGame(): array
    {
        [$gameId, $cpuHand] = $this->createGame();
        $winningHand = self::WINS_AGAINST[$cpuHand];

        $this->postJson("/api/games/{$gameId}/hands", [
            'hand' => $winningHand, 'pressed_at' => self::SIGNAL + 300,
        ])->assertCreated();

        $response = $this->postJson("/api/games/{$gameId}/judge", $this->judgePayload([
            'hand' => $winningHand, 'pressed_at' => self::SIGNAL + 300,
        ]));

        return [$gameId, $cpuHand, $response];
    }

    #[Test]
    public function ゲーム開始から判定までの一連の流れ(): void
    {
        [$gameId, $cpuHand, $response] = $this->playWinningGame();

        $response->assertOk()->assertJson([
            'result' => 'win',
            'reason' => 'hand',
            'cpu_hand' => $cpuHand,
            'hand' => self::WINS_AGAINST[$cpuHand],
            'reaction_ms' => 300.0,
            'margin_ms' => 500.0,
            'time_limit_ms' => 800.0,
        ]);

        $this->assertDatabaseHas('games', [
            'id' => $gameId, 'difficulty' => 'warn', 'result' => 'win', 'result_reason' => 'hand',
        ]);
        $this->assertDatabaseHas('hands', [
            'game_id' => $gameId, 'hand' => self::WINS_AGAINST[$cpuHand], 'reaction_ms' => 300.0,
        ]);
    }

    #[Test]
    public function ゲーム開始時に難易度に応じた制限時間とCPUの手が返る(): void
    {
        [, $cpuHand, $timeLimitMs] = $this->createGame('critical');

        $this->assertEqualsWithDelta(500.0, $timeLimitMs, 0.001);
        $this->assertContains($cpuHand, ['rock', 'scissors', 'paper']);
    }

    #[Test]
    public function 未知の難易度は422(): void
    {
        $this->postJson('/api/games', ['difficulty' => 'extreme'])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'trace_id']);
    }

    #[Test]
    public function 同じ手を出したらつられ負け(): void
    {
        [$gameId, $cpuHand] = $this->createGame();

        $this->postJson("/api/games/{$gameId}/judge", $this->judgePayload([
            'hand' => $cpuHand, 'pressed_at' => self::SIGNAL + 300,
        ]))->assertOk()->assertJson(['result' => 'lose', 'reason' => 'copy']);
    }

    #[Test]
    public function 制限時間を超えたら時間切れ負け(): void
    {
        [$gameId, $cpuHand] = $this->createGame(); // WARN 級 = 800ms

        $this->postJson("/api/games/{$gameId}/judge", $this->judgePayload([
            'hand' => self::WINS_AGAINST[$cpuHand], 'pressed_at' => self::SIGNAL + 900,
        ]))->assertOk()->assertJson(['result' => 'lose', 'reason' => 'late', 'margin_ms' => -100.0]);
    }

    #[Test]
    public function 未入力はタイムアウト負け(): void
    {
        [$gameId] = $this->createGame();

        $this->postJson("/api/games/{$gameId}/judge", $this->judgePayload(null))
            ->assertOk()
            ->assertJson(['result' => 'lose', 'reason' => 'timeout', 'hand' => null, 'reaction_ms' => null]);
    }

    #[Test]
    public function 判定は冪等で再実行しても同じ結果が返る(): void
    {
        [$gameId, , $first] = $this->playWinningGame();

        $second = $this->postJson("/api/games/{$gameId}/judge", [
            'countdown_started_at' => 1,
            'ken_at' => 2,
            'signal_at' => 3,
            'hand' => null,
        ]);

        $second->assertOk();
        $this->assertSame($first->json('result'), $second->json('result'));
        $this->assertSame($first->json('reason'), $second->json('reason'));
        $this->assertSame($first->json('reaction_ms'), $second->json('reaction_ms'));
        $this->assertSame($first->json('margin_ms'), $second->json('margin_ms'));
    }

    #[Test]
    public function 並行リクエストを模した競合でも最初に確定した結果が維持される(): void
    {
        [$gameId, $cpuHand] = $this->createGame();
        $winningHand = self::WINS_AGAINST[$cpuHand];

        // 競合の瞬間を再現する: コントローラ冒頭の判定済みチェックを「未判定」で通過した
        // 時点のスナップショットとして、判定前のモデルを先に確保しておく
        $staleGame = \App\Models\Game::findOrFail($gameId);
        $this->assertFalse($staleGame->isJudged());

        // 先行リクエストが勝ちで確定する
        $first = $this->postJson("/api/games/{$gameId}/judge", $this->judgePayload([
            'hand' => $winningHand, 'pressed_at' => self::SIGNAL + 300,
        ]))->assertOk();

        // 後続リクエスト(異なる負けペイロード)を未判定スナップショットのままコントローラに通す。
        // 行ロック下の再確認が効けば、上書きせず保存済みの結果を返す
        $request = \Illuminate\Http\Request::create(
            "/api/games/{$gameId}/judge",
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode($this->judgePayload(['hand' => $cpuHand, 'pressed_at' => self::SIGNAL + 400])),
        );
        $second = app(\App\Http\Controllers\GameController::class)
            ->judge($request, $staleGame, app(\App\Game\JudgeService::class), app(\App\Game\GameTelemetry::class))
            ->getData(true);

        $this->assertSame($first->json('result'), $second['result']);
        $this->assertSame($first->json('reason'), $second['reason']);
        $this->assertSame($first->json('trace_id'), $second['trace_id']); // テレメトリの再送出もしていない
        $this->assertDatabaseHas('games', ['id' => $gameId, 'result' => 'win', 'result_reason' => 'hand']);
        $this->assertDatabaseCount('hands', 1);
    }

    #[Test]
    public function 手の二重送信は409(): void
    {
        [$gameId] = $this->createGame();

        $payload = ['hand' => 'rock', 'pressed_at' => 1000.0];
        $this->postJson("/api/games/{$gameId}/hands", $payload)->assertCreated();
        $this->postJson("/api/games/{$gameId}/hands", $payload)
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'trace_id']);
    }

    #[Test]
    public function バリデーションエラーは422で共通エラー形式(): void
    {
        [$gameId] = $this->createGame();

        $this->postJson("/api/games/{$gameId}/hands", [
            'hand' => 'lizard', 'pressed_at' => -1,
        ])->assertStatus(422)->assertJsonStructure(['message', 'trace_id']);
    }

    #[Test]
    public function 存在しないゲームは404(): void
    {
        $this->postJson('/api/games/g_unknown/hands', [
            'hand' => 'rock', 'pressed_at' => 1000.0,
        ])->assertNotFound()->assertJsonStructure(['message', 'trace_id']);
    }

}
