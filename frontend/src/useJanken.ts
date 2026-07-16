import { useCallback, useEffect, useRef, useState } from 'react'
import { api, type DifficultyType, type HandType, type JudgeResponse } from './api'
import { playVoice, preloadVoices } from './voice'

// タイミング仕様は docs/game-spec.md
export const JAN_TO_KEN_MS = 600
export const KEN_TO_PON_MIN_MS = 700
export const KEN_TO_PON_MAX_MS = 5000
export const INPUT_DEADLINE_MS = 2000
export const FOUL_REVEAL_MS = 1500
export const RESULT_DELAY_MS = 600

// ミラー割当: プライマリは J/K/L、A/S/D は同じ手(docs/game-spec.md のキー割当)
const KEY_MAP: Record<string, HandType> = {
  j: 'rock',
  k: 'scissors',
  l: 'paper',
  a: 'rock',
  s: 'scissors',
  d: 'paper',
}

export type Phase = 'title' | 'countdown' | 'input' | 'judging' | 'result'
export type CountdownStep = 'jan' | 'ken' | 'pon'

const nowMs = () => performance.timeOrigin + performance.now()

export function useJanken() {
  const [phase, setPhase] = useState<Phase>('title')
  const [step, setStep] = useState<CountdownStep>('jan')
  const [difficulty, setDifficulty] = useState<DifficultyType>('ok')
  const [cpuHand, setCpuHand] = useState<HandType | null>(null)
  const [timeLimitMs, setTimeLimitMs] = useState<number | null>(null)
  const [pressed, setPressed] = useState<HandType | null>(null)
  const [result, setResult] = useState<JudgeResponse | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [starting, setStarting] = useState(false)

  const phaseRef = useRef<Phase>('title')
  const startingRef = useRef(false)
  const gameIdRef = useRef<string | null>(null)
  const pressRef = useRef<{ hand: HandType; pressedAt: number } | null>(null)
  const timesRef = useRef({ countdownStartedAt: 0, kenAt: 0, signalAt: 0 })
  const timersRef = useRef<number[]>([])

  const transition = (next: Phase) => {
    phaseRef.current = next
    setPhase(next)
  }

  const clearTimers = () => {
    timersRef.current.forEach((t) => window.clearTimeout(t))
    timersRef.current = []
  }

  const judge = useCallback(async () => {
    if (phaseRef.current === 'judging' || phaseRef.current === 'result') return
    clearTimers()
    transition('judging')
    const gameId = gameIdRef.current
    if (gameId === null) return
    const press = pressRef.current
    try {
      const res = await api.judge(gameId, {
        countdown_started_at: timesRef.current.countdownStartedAt,
        ken_at: timesRef.current.kenAt,
        signal_at: timesRef.current.signalAt,
        hand: press === null ? null : { hand: press.hand, pressed_at: press.pressedAt },
      })
      setResult(res)
    } catch (e) {
      setError(e instanceof Error ? e.message : '判定に失敗しました')
    }
    transition('result')
  }, [])

  const start = useCallback(
    async (selected: DifficultyType) => {
      // 多重開始ガード: createGame 応答待ちの間の再入(連打・Space とクリックの併用)と、
      // タイトル画面以外からの開始を弾く。ガード中は古い応答が新しいゲームを上書きすることもない
      if (startingRef.current || phaseRef.current !== 'title') return
      startingRef.current = true
      setStarting(true)
      setError(null)
      setResult(null)
      setPressed(null)
      setCpuHand(null)
      pressRef.current = null
      setDifficulty(selected)
      try {
        const { game_id, cpu_hand, time_limit_ms } = await api.createGame(selected)
        gameIdRef.current = game_id
        // CPU の手は事前に受け取り、合図(ぽん!)まで表示しないだけ(docs/game-spec.md)
        setCpuHand(cpu_hand)
        setTimeLimitMs(time_limit_ms)
      } catch (e) {
        setError(e instanceof Error ? e.message : 'ゲームを開始できませんでした')
        // 失敗時は再試行できる状態へ戻す
        startingRef.current = false
        setStarting(false)
        return
      }

      const kenDelay = KEN_TO_PON_MIN_MS + Math.random() * (KEN_TO_PON_MAX_MS - KEN_TO_PON_MIN_MS)
      const startedAt = nowMs()
      timesRef.current = {
        countdownStartedAt: startedAt,
        // フライングで「けん」「ぽん!」が表示されないまま終わる場合に備え、予定時刻を先に入れておく。
        // 実際に表示された時点で実測時刻に置き換える
        kenAt: startedAt + JAN_TO_KEN_MS,
        signalAt: startedAt + JAN_TO_KEN_MS + kenDelay,
      }
      setStep('jan')
      transition('countdown')
      // countdown へ遷移した後は phase チェックが再入を防ぐので、ガードを解除してよい
      startingRef.current = false
      setStarting(false)
      playVoice('jan')

      timersRef.current.push(
        window.setTimeout(() => {
          timesRef.current.kenAt = nowMs()
          timesRef.current.signalAt = timesRef.current.kenAt + kenDelay
          setStep('ken')
          playVoice('ken')
          timersRef.current.push(
            window.setTimeout(() => {
              timesRef.current.signalAt = nowMs()
              setStep('pon')
              transition('input')
              playVoice('pon')
              timersRef.current.push(window.setTimeout(() => void judge(), INPUT_DEADLINE_MS))
            }, kenDelay),
          )
        }, JAN_TO_KEN_MS),
      )
    },
    [judge],
  )

  const backToTitle = useCallback(() => {
    clearTimers()
    setResult(null)
    setError(null)
    startingRef.current = false
    setStarting(false)
    transition('title')
  }, [])

  // 初回プレイの「じゃん」に間に合うよう、タイトル表示中に音声をデコードしておく
  useEffect(() => {
    void preloadVoices().catch(() => {})
  }, [])

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.repeat) return
      const currentPhase = phaseRef.current

      if (e.code === 'Space') {
        if (currentPhase === 'result') backToTitle()
        return
      }

      if (currentPhase !== 'countdown' && currentPhase !== 'input') return
      const hand = KEY_MAP[e.key.toLowerCase()]
      if (hand === undefined || pressRef.current !== null) return

      const pressedAt = nowMs()
      pressRef.current = { hand, pressedAt }
      const gameId = gameIdRef.current
      if (gameId !== null) {
        void api.sendHand(gameId, { hand, pressed_at: pressedAt }).catch(() => {})
      }

      setPressed(hand)
      clearTimers()
      // フライングは少し間を置いて判定演出へ。通常押下は短いタメの後に判定
      timersRef.current.push(
        window.setTimeout(() => void judge(), currentPhase === 'countdown' ? FOUL_REVEAL_MS : RESULT_DELAY_MS),
      )
    }
    window.addEventListener('keydown', onKeyDown)
    return () => {
      window.removeEventListener('keydown', onKeyDown)
      clearTimers()
    }
  }, [judge, backToTitle])

  return { phase, step, difficulty, cpuHand, timeLimitMs, pressed, result, error, starting, start, backToTitle }
}
