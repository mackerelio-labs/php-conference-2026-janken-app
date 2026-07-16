<?php

namespace App\Game;

/**
 * 後出しじゃんけんの判定。優先順位は docs/game-spec.md の判定仕様に従う:
 * 1. フライング > 2. 未入力 > 3. 時間切れ > 4. つられ(同じ手) > 5. 負ける手 > 6. 勝つ手
 */
final class JudgeService
{
    /** 入力締切。「押したが遅い(late)」と「押さなかった(timeout)」を区別するための受付終了時刻 */
    public const INPUT_DEADLINE_MS = 2000.0;

    /** key の手が value の手に勝つ */
    private const BEATS = ['rock' => 'scissors', 'scissors' => 'paper', 'paper' => 'rock'];

    /**
     * @param float $signalAt 合図(ぽん! = CPU の手の表示)のエポック ms
     * @param array{hand: string, pressed_at: float}|null $hand プレイヤーの押下。未入力は null
     */
    public function judge(float $signalAt, string $cpuHand, float $timeLimitMs, ?array $hand): JudgeResult
    {
        // 優先 2: 未入力(入力締切まで押下なし)
        if ($hand === null) {
            return new JudgeResult('lose', 'timeout', null, null, null);
        }

        $reactionMs = $hand['pressed_at'] - $signalAt;
        $marginMs = $timeLimitMs - $reactionMs;

        // 優先 1: フライング(合図前の押下)
        if ($reactionMs < 0) {
            return new JudgeResult('lose', 'foul', $hand['hand'], $reactionMs, $marginMs);
        }

        // 優先 2: 入力締切を超えた押下は「締切まで手が出なかった」と同じ扱い(境界含まず:
        // ちょうど締切は受付内で late 側)。クライアントは締切で自動判定するため通常は届かず、
        // 改ざん・遅延ペイロードをサーバー側で仕様どおりに倒すためのガード
        if ($reactionMs > self::INPUT_DEADLINE_MS) {
            return new JudgeResult('lose', 'timeout', null, null, null);
        }

        // 優先 3: 時間切れ(境界含まず: ちょうど制限時間はセーフ)。手の内容は見ない
        if ($reactionMs > $timeLimitMs) {
            return new JudgeResult('lose', 'late', $hand['hand'], $reactionMs, $marginMs);
        }

        // 優先 4: つられ(CPU と同じ手)。wrong_hand と区別して記録する(docs/game-spec.md)
        if ($hand['hand'] === $cpuHand) {
            return new JudgeResult('lose', 'copy', $hand['hand'], $reactionMs, $marginMs);
        }

        // 優先 5/6: 手の勝敗
        if (self::BEATS[$hand['hand']] === $cpuHand) {
            return new JudgeResult('win', 'hand', $hand['hand'], $reactionMs, $marginMs);
        }

        return new JudgeResult('lose', 'wrong_hand', $hand['hand'], $reactionMs, $marginMs);
    }
}
