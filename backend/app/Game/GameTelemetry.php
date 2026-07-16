<?php

namespace App\Game;

use App\Models\Game;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * ゲームトレース(service.name=janken-game)の遡及生成・実況ログ・メトリック送信。
 */
class GameTelemetry
{
    private const HAND_LABELS = ['rock' => 'グー', 'scissors' => 'チョキ', 'paper' => 'パー'];

    private const SEVERITY_INFO = 9;

    private const SEVERITY_WARN = 13;

    private ?TracerProvider $tracerProvider = null;

    private ?LoggerProvider $loggerProvider = null;

    private ?MeterProvider $meterProvider = null;

    /**
     * 判定確定時に 1 ゲーム分のテレメトリ(トレース・ログ・メトリック)を送出する。
     *
     * @param array{countdown_started_at: float, ken_at: float, signal_at: float} $times クライアント計測のエポック ms
     * @param array{hand: string, pressed_at: float, received_at: float|null}|null $hand プレイヤーの押下。未入力は null
     * @param float $judgeStartMs judge リクエスト処理開始(サーバー時刻、エポック ms)
     * @return string|null ゲームトレースの trace_id(テレメトリ無効時は null)
     */
    public function emitGame(Game $game, JudgeResult $res, array $times, ?array $hand, float $judgeStartMs): ?string
    {
        if (filter_var(getenv('JANKEN_TELEMETRY_DISABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            return null;
        }

        $nowMs = microtime(true) * 1000;
        $signalAt = $times['signal_at'];
        $signalDelayMs = $times['signal_at'] - $times['ken_at'];
        $timeLimitMs = $game->time_limit_ms;
        $tracer = $this->tracerProvider()->getTracer('janken');

        $gameAttrs = array_filter([
            'janken.game.id' => $game->id,
            'janken.difficulty' => $game->difficulty,
            'janken.time_limit_ms' => $timeLimitMs,
            'janken.cpu.hand' => $game->cpu_hand,
            'janken.signal_delay_ms' => $signalDelayMs,
            'janken.reaction_ms' => $res->reactionMs,
            'janken.margin_ms' => $res->marginMs,
            'janken.result' => $res->result,
            'janken.result.reason' => $res->reason,
        ], fn ($v) => $v !== null);

        // setParent(false) を省くと judge リクエストのアプリトレースが暗黙の親になる
        $root = $tracer->spanBuilder('janken.game')
            ->setParent(false)
            ->setStartTimestamp(self::ns($times['countdown_started_at']))
            ->setAttributes($gameAttrs)
            ->startSpan();
        $rootCtx = $root->storeInContext(Context::getCurrent());

        $countdown = $tracer->spanBuilder('janken.countdown')
            ->setParent($rootCtx)
            ->setStartTimestamp(self::ns($times['countdown_started_at']))
            ->setAttribute('janken.game.id', $game->id)
            ->startSpan();
        $countdownCtx = $countdown->storeInContext($rootCtx);

        $jan = $tracer->spanBuilder('janken.countdown.jan')
            ->setParent($countdownCtx)
            ->setStartTimestamp(self::ns($times['countdown_started_at']))
            ->setAttribute('janken.game.id', $game->id)
            ->startSpan();
        $jan->end(self::ns($times['ken_at']));

        $ken = $tracer->spanBuilder('janken.countdown.ken')
            ->setParent($countdownCtx)
            ->setStartTimestamp(self::ns($times['ken_at']))
            ->setAttribute('janken.game.id', $game->id)
            ->setAttribute('janken.signal_delay_ms', $signalDelayMs)
            ->startSpan();
        $ken->end(self::ns($signalAt));
        $countdown->end(self::ns($signalAt));

        // 制限時間の受付ウィンドウ。長さ = 制限時間で、難易度がウォーターフォールの形として見える。
        // hand スパンがこの右端の内側で終われば勝ち、はみ出せば時間切れ、と包含関係で読む。
        $timeLimitEnd = $signalAt + $timeLimitMs;
        $timeLimit = $tracer->spanBuilder('janken.time_limit')
            ->setParent($rootCtx)
            ->setStartTimestamp(self::ns($signalAt))
            ->setAttribute('janken.game.id', $game->id)
            ->setAttribute('janken.difficulty', $game->difficulty)
            ->setAttribute('janken.time_limit_ms', $timeLimitMs)
            ->setAttribute('janken.cpu.hand', $game->cpu_hand)
            ->startSpan();
        $timeLimit->addEvent('janken.cpu_hand', ['janken.cpu.hand' => $game->cpu_hand], self::ns($signalAt));
        $timeLimit->end(self::ns($timeLimitEnd));

        // フライングは実押下時刻(合図前)から描き、未入力は入力締切いっぱいの長さで描く
        $handStart = $hand === null ? $signalAt : min($signalAt, $hand['pressed_at']);
        $handEnd = $hand === null
            ? $signalAt + JudgeService::INPUT_DEADLINE_MS
            : max($signalAt, $hand['pressed_at']);

        $builder = $tracer->spanBuilder('janken.hand')
            ->setParent($rootCtx)
            ->setStartTimestamp(self::ns($handStart))
            ->setAttribute('janken.game.id', $game->id);
        if ($hand !== null) {
            $builder->setAttribute('janken.hand', $hand['hand'])
                ->setAttribute('janken.reaction_ms', $res->reactionMs)
                ->setAttribute('janken.margin_ms', $res->marginMs)
                ->setAttribute('janken.client_pressed_at', self::iso($hand['pressed_at']));
            if ($hand['received_at'] !== null) {
                $builder->setAttribute('janken.server_received_at', self::iso($hand['received_at']));
            }
        }
        $handSpan = $builder->startSpan();

        // 時間系の違反のみ ERROR(手の間違い copy / wrong_hand は正常なドメイン結果)
        if (in_array($res->reason, ['late', 'foul', 'timeout'], true)) {
            $description = match ($res->reason) {
                'late' => sprintf('時間切れ: 制限 %.1f 秒に対し %.3f 秒超過', $timeLimitMs / 1000, -($res->marginMs ?? 0) / 1000),
                'foul' => sprintf('フライング: 合図の %.3f 秒前に押下', abs($res->reactionMs ?? 0) / 1000),
                'timeout' => sprintf('未入力: 締切 %.1f 秒まで手が出なかった', JudgeService::INPUT_DEADLINE_MS / 1000),
            };
            $handSpan->setStatus(StatusCode::STATUS_ERROR, $description);

            $handSpan->addEvent('exception', [
                'exception.type' => 'Janken'.str_replace('_', '', ucwords($res->reason, '_')),
                'exception.message' => $description,
            ], self::ns($handEnd));
        }
        $handSpan->end(self::ns($handEnd));

        $judge = $tracer->spanBuilder('janken.judge')
            ->setParent($rootCtx)
            ->setStartTimestamp(self::ns($judgeStartMs))
            ->addLink(Span::getCurrent()->getContext())
            ->setAttribute('janken.game.id', $game->id)
            ->setAttributes($gameAttrs)
            ->startSpan();
        $judge->end(self::ns($nowMs));

        // 早い決着(フライング等)でもルートは全子スパンを包含する max で終える
        $root->end(self::ns(max($nowMs, $timeLimitEnd, $handEnd)));

        $traceId = $root->getContext()->getTraceId();
        $this->emitLogs($game, $res, $times, $hand, $root);
        $this->emitMetrics($game, $res);

        return $traceId;
    }

    /** 実況ログ。時刻は出来事の実時刻に遡って付ける */
    private function emitLogs(Game $game, JudgeResult $res, array $times, ?array $hand, SpanInterface $root): void
    {
        $logger = $this->loggerProvider()->getLogger('janken');
        $difficulty = Difficulty::from($game->difficulty);
        $cpuLabel = self::HAND_LABELS[$game->cpu_hand];
        // ゲームトレースと相関させるため、ルートスパンを current にして emit する
        $scope = $root->activate();
        try {
            $emit = function (int $severity, string $severityText, float $atMs, array $body) use ($logger): void {
                $record = (new LogRecord(json_encode($body, JSON_UNESCAPED_UNICODE)))
                    ->setTimestamp(self::ns($atMs))
                    ->setObservedTimestamp(self::ns(microtime(true) * 1000))
                    ->setSeverityNumber($severity)
                    ->setSeverityText($severityText);
                $logger->emit($record);
            };

            $emit(self::SEVERITY_INFO, 'INFO', $times['countdown_started_at'], [
                'msg' => sprintf('ゲーム開始(%s、CPU は%s)', $difficulty->label(), $cpuLabel),
                'game_id' => $game->id,
                'difficulty' => $game->difficulty,
                'cpu_hand' => $game->cpu_hand,
            ]);

            if ($hand !== null) {
                $emit(self::SEVERITY_INFO, 'INFO', $hand['pressed_at'], [
                    'msg' => sprintf('プレイヤーが%sを出しました(反応 %.3f 秒)', self::HAND_LABELS[$hand['hand']], ($res->reactionMs ?? 0) / 1000),
                    'game_id' => $game->id,
                    'hand' => $hand['hand'],
                    'reaction_ms' => round($res->reactionMs ?? 0, 1),
                ]);
            }

            // 判定ログ: 1 ゲームに必ず 1 行。事件系(つられ・時間切れ・フライング・未入力)は WARN
            [$severity, $severityText, $msg] = match ($res->reason) {
                'hand' => [self::SEVERITY_INFO, 'INFO', sprintf('勝ち! %s > %s(反応 %.3f 秒、残り %.3f 秒)',
                    self::HAND_LABELS[$res->hand], $cpuLabel, ($res->reactionMs ?? 0) / 1000, ($res->marginMs ?? 0) / 1000)],
                'copy' => [self::SEVERITY_WARN, 'WARN', sprintf('%sにつられて%sを出して負け(反応 %.3f 秒)',
                    $cpuLabel, self::HAND_LABELS[$res->hand], ($res->reactionMs ?? 0) / 1000)],
                'wrong_hand' => [self::SEVERITY_INFO, 'INFO', sprintf('%sに%sを出して負け(反応 %.3f 秒)',
                    $cpuLabel, self::HAND_LABELS[$res->hand], ($res->reactionMs ?? 0) / 1000)],
                'late' => [self::SEVERITY_WARN, 'WARN', sprintf('時間切れ: 制限 %.1f 秒に対し %.3f 秒超過',
                    $game->time_limit_ms / 1000, -($res->marginMs ?? 0) / 1000)],
                'foul' => [self::SEVERITY_WARN, 'WARN', sprintf('フライング(合図の %.3f 秒前)',
                    abs($res->reactionMs ?? 0) / 1000)],
                'timeout' => [self::SEVERITY_WARN, 'WARN', '時間内に手が出ませんでした'],
            };
            $body = [
                'msg' => $msg,
                'game_id' => $game->id,
                'result' => $res->result,
                'reason' => $res->reason,
                'difficulty' => $game->difficulty,
                'cpu_hand' => $game->cpu_hand,
            ];
            if ($res->hand !== null) {
                $body['hand'] = $res->hand;
            }
            if ($res->reactionMs !== null) {
                $body['reaction_ms'] = round($res->reactionMs, 1);
                $body['margin_ms'] = round($res->marginMs, 1);
            }
            if ($res->reason === 'foul') {
                $body['early_ms'] = round(abs($res->reactionMs ?? 0), 1);
            }
            $emit($severity, $severityText, microtime(true) * 1000, $body);
        } finally {
            $scope->detach();
        }
    }

    private function emitMetrics(Game $game, JudgeResult $res): void
    {
        $meter = $this->meterProvider()->getMeter('janken');

        $meter->createCounter('janken.games')->add(1, [
            'result' => $res->result,
            'result.reason' => $res->reason,
            'difficulty' => $game->difficulty,
        ]);

        // CPU の手 × 出した手のマトリクス。未入力(timeout)は手が存在しないため記録しない
        if ($res->hand !== null) {
            $meter->createCounter('janken.hands')->add(1, [
                'hand' => $res->hand,
                'cpu_hand' => $game->cpu_hand,
                'result.reason' => $res->reason,
                'difficulty' => $game->difficulty,
            ]);
        }

        if ($res->reactionMs !== null && $res->reactionMs >= 0) {
            $meter->createHistogram('janken.reaction_time_ms', 'ms')->record($res->reactionMs, [
                'difficulty' => $game->difficulty,
                'result' => $res->result,
            ]);
        }

        $this->meterProvider()->forceFlush();
    }

    private function resource(): ResourceInfo
    {
        // defaultResource は process.pid などワーカーごとに変わる属性を含み、Collector の
        // deltatocumulative がメトリックを集約できなくなるため、安定した属性のみで構成する
        $attrs = ['service.name' => 'janken-game'];
        foreach (explode(',', (string) getenv('OTEL_RESOURCE_ATTRIBUTES')) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($key !== '' && $value !== null) {
                $attrs[$key] = $value;
            }
        }

        return ResourceInfo::create(Attributes::create($attrs));
    }

    private function endpoint(string $path): string
    {
        $base = rtrim(getenv('OTEL_EXPORTER_OTLP_ENDPOINT') ?: 'http://otel-collector:4318', '/');

        return $base.$path;
    }

    /** Collector 停止時でも judge の応答が固まらないよう、タイムアウト短め・リトライなし */
    private function transport(string $path): \OpenTelemetry\SDK\Common\Export\TransportInterface
    {
        return (new OtlpHttpTransportFactory)->create(
            $this->endpoint($path),
            'application/x-protobuf',
            [],       // headers
            null,     // compression
            timeout: 2.0,
            maxRetries: 1,
        );
    }

    private function tracerProvider(): TracerProvider
    {
        return $this->tracerProvider ??= TracerProvider::builder()
            ->addSpanProcessor(new SimpleSpanProcessor(new SpanExporter($this->transport('/v1/traces'))))
            ->setResource($this->resource())
            ->build();
    }

    private function loggerProvider(): LoggerProvider
    {
        return $this->loggerProvider ??= LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new LogsExporter($this->transport('/v1/logs'))))
            ->setResource($this->resource())
            ->build();
    }

    private function meterProvider(): MeterProvider
    {
        // delta temporality で送る。1 ゲーム = 1 イベントのデータを期間集計(sum_over_time)で
        // 素直に数えるため。cumulative + increase は系列誕生の最初の 1 回を数えられない
        return $this->meterProvider ??= MeterProvider::builder()
            ->addReader(new ExportingReader(new MetricExporter($this->transport('/v1/metrics'), Temporality::DELTA)))
            ->setResource($this->resource())
            ->build();
    }

    private static function ns(float $epochMs): int
    {
        return (int) round($epochMs * 1_000_000);
    }

    private static function iso(float $epochMs): string
    {
        return \DateTimeImmutable::createFromFormat('U.v', sprintf('%.3F', $epochMs / 1000))
            ->setTimezone(new \DateTimeZone(config('app.timezone', 'UTC')))
            ->format('Y-m-d\TH:i:s.vP');
    }
}
