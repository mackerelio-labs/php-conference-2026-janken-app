<?php

namespace Tests\Unit;

use App\Game\JudgeService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JudgeServiceTest extends TestCase
{
    private const SIGNAL = 1_000_000.0; // 合図のエポック ms(値自体に意味はない)

    private const TIME_LIMIT = 800.0; // WARN 級相当

    private function judge(string $cpuHand, ?array $hand): \App\Game\JudgeResult
    {
        return (new JudgeService)->judge(self::SIGNAL, $cpuHand, self::TIME_LIMIT, $hand);
    }

    private function hand(string $hand, float $reactionMs): array
    {
        return ['hand' => $hand, 'pressed_at' => self::SIGNAL + $reactionMs];
    }

    #[Test]
    public function 制限時間内に勝つ手を出せば勝ち(): void
    {
        $r = $this->judge('rock', $this->hand('paper', 300));
        $this->assertSame(['win', 'hand', 'paper'], [$r->result, $r->reason, $r->hand]);
        $this->assertEqualsWithDelta(300.0, $r->reactionMs, 0.001);
        $this->assertEqualsWithDelta(500.0, $r->marginMs, 0.001);
    }

    #[Test]
    public function 制限時間ちょうどはセーフ(): void
    {
        $r = $this->judge('scissors', $this->hand('rock', self::TIME_LIMIT));
        $this->assertSame(['win', 'hand'], [$r->result, $r->reason]);
        $this->assertEqualsWithDelta(0.0, $r->marginMs, 0.001);
    }

    #[Test]
    public function 制限時間を超えたら正しい手でも時間切れ負け(): void
    {
        $r = $this->judge('rock', $this->hand('paper', 900));
        $this->assertSame(['lose', 'late'], [$r->result, $r->reason]);
        $this->assertEqualsWithDelta(-100.0, $r->marginMs, 0.001);
    }

    #[Test]
    public function 同じ手を出したらつられ負け(): void
    {
        $r = $this->judge('scissors', $this->hand('scissors', 300));
        $this->assertSame(['lose', 'copy'], [$r->result, $r->reason]);
    }

    #[Test]
    public function 負ける手を出したら負け(): void
    {
        $r = $this->judge('rock', $this->hand('scissors', 300));
        $this->assertSame(['lose', 'wrong_hand'], [$r->result, $r->reason]);
    }

    #[Test]
    public function 時間切れはつられより優先される(): void
    {
        // 制限時間超過なら手の内容は見ない(同じ手でも copy ではなく late)
        $r = $this->judge('rock', $this->hand('rock', 900));
        $this->assertSame(['lose', 'late'], [$r->result, $r->reason]);
    }

    #[Test]
    public function 合図前の押下はフライング負け(): void
    {
        // 勝つ手を出していてもフライングが優先される
        $r = $this->judge('rock', $this->hand('paper', -50));
        $this->assertSame(['lose', 'foul'], [$r->result, $r->reason]);
        $this->assertEqualsWithDelta(-50.0, $r->reactionMs, 0.001);
    }

    #[Test]
    public function 未入力はタイムアウト負け(): void
    {
        $r = $this->judge('rock', null);
        $this->assertSame(['lose', 'timeout', null, null, null], [$r->result, $r->reason, $r->hand, $r->reactionMs, $r->marginMs]);
    }

    #[Test]
    public function 入力締切を超えた押下は未入力と同じタイムアウト負け(): void
    {
        // 勝つ手でも締切超過なら timeout。レスポンス値も未入力と同一(hand/reaction/margin とも null)
        $r = $this->judge('rock', $this->hand('paper', JudgeService::INPUT_DEADLINE_MS + 0.1));
        $this->assertSame(['lose', 'timeout', null, null, null], [$r->result, $r->reason, $r->hand, $r->reactionMs, $r->marginMs]);
    }

    #[Test]
    public function 入力締切ちょうどは受付内で時間切れ負け(): void
    {
        // 境界含まず(制限時間の規則と同じ)。締切ちょうどの押下は timeout ではなく late
        $r = $this->judge('rock', $this->hand('paper', JudgeService::INPUT_DEADLINE_MS));
        $this->assertSame(['lose', 'late', 'paper'], [$r->result, $r->reason, $r->hand]);
        $this->assertEqualsWithDelta(2000.0, $r->reactionMs, 0.001);
    }
}
