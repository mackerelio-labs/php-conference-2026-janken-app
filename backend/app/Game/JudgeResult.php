<?php

namespace App\Game;

/**
 * 判定結果。値の意味は docs/game-spec.md の判定仕様を参照。
 */
final readonly class JudgeResult
{
    /**
     * @param 'win'|'lose' $result
     * @param 'hand'|'copy'|'wrong_hand'|'late'|'foul'|'timeout' $reason
     * @param string|null $hand プレイヤーの手。未入力(timeout)は null
     * @param float|null $reactionMs 合図からの反応時間 ms(フライングは負値、未入力は null)
     * @param float|null $marginMs 制限時間までの余裕 ms(負値 = 超過、未入力は null)
     */
    public function __construct(
        public string $result,
        public string $reason,
        public ?string $hand,
        public ?float $reactionMs,
        public ?float $marginMs,
    ) {
    }
}
