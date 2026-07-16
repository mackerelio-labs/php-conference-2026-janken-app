import { useEffect, useState, type ReactNode } from 'react'
import { type DifficultyType, type HandType, type JudgeReason, type JudgeResponse } from './api'
import { useJanken, type CountdownStep } from './useJanken'

const HAND_EMOJI: Record<HandType, string> = { rock: '✊', scissors: '✌️', paper: '✋' }
const HAND_LABEL: Record<HandType, string> = { rock: 'グー', scissors: 'チョキ', paper: 'パー' }
const COUNTDOWN_TEXT: Record<CountdownStep, string> = { jan: 'じゃん', ken: 'けん', pon: 'ぽん!!' }

const DIFFICULTIES: { value: DifficultyType; label: string; timeLimitMs: number }[] = [
  { value: 'ok', label: 'OK 級', timeLimitMs: 1200 },
  { value: 'warn', label: 'WARN 級', timeLimitMs: 800 },
  { value: 'critical', label: 'CRITICAL 級', timeLimitMs: 500 },
]
const DIFFICULTY_LABEL: Record<DifficultyType, string> = { ok: 'OK 級', warn: 'WARN 級', critical: 'CRITICAL 級' }

export default function App() {
  const { phase, step, difficulty, cpuHand, timeLimitMs, pressed, result, error, starting, start, backToTitle } = useJanken()

  return (
    <div className="app">
      {phase === 'title' && <TitleScreen onStart={(d) => void start(d)} starting={starting} error={error} />}
      {(phase === 'countdown' || phase === 'input' || phase === 'judging') && (
        <PlayScreen
          step={phase === 'countdown' ? step : 'pon'}
          difficulty={difficulty}
          cpuHand={cpuHand}
          timeLimitMs={timeLimitMs}
          pressed={pressed}
          judging={phase === 'judging'}
        />
      )}
      {phase === 'result' && <ResultScreen result={result} error={error} onBack={backToTitle} />}
    </div>
  )
}

const DIFFICULTY_STORAGE_KEY = 'janken.difficulty'

function loadSavedDifficulty(): DifficultyType {
  const saved = localStorage.getItem(DIFFICULTY_STORAGE_KEY)
  return saved === 'ok' || saved === 'warn' || saved === 'critical' ? saved : 'ok'
}

function TitleScreen({ onStart, starting, error }: { onStart: (d: DifficultyType) => void; starting: boolean; error: string | null }) {
  const [selected, setSelected] = useState<DifficultyType>(loadSavedDifficulty)
  useEffect(() => {
    localStorage.setItem(DIFFICULTY_STORAGE_KEY, selected)
  }, [selected])

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.repeat || starting) return
      if (e.key === '1') setSelected('ok')
      if (e.key === '2') setSelected('warn')
      if (e.key === '3') setSelected('critical')
      if (e.code === 'Space') onStart(selected)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [onStart, selected, starting])

  return (
    <div className="screen title-screen">
      <h1>後出しジャンケン<br/>タイムアタック!</h1>
      <p className="rules">
        <strong>制限時間内</strong>に<strong>勝つ手</strong>を出せば勝ち
      </p>
      <div className="difficulties" role="radiogroup" aria-label="難易度">
        {DIFFICULTIES.map((d, i) => (
          <button
            key={d.value}
            role="radio"
            aria-checked={selected === d.value}
            className={`difficulty difficulty-${d.value} ${selected === d.value ? 'selected' : ''}`}
            onClick={() => setSelected(d.value)}
          >
            <span className="difficulty-key">
              <kbd>{i + 1}</kbd>
            </span>
            <span className="difficulty-name">{d.label}</span>
            <span className="difficulty-limit">{(d.timeLimitMs / 1000).toFixed(1)}秒</span>
          </button>
        ))}
      </div>
      <div className="keys">
        <p>
          <kbd>J</kbd> ✊ グー / <kbd>K</kbd> ✌️ チョキ / <kbd>L</kbd> ✋ パー
        </p>
      </div>
      <button className="start-button" disabled={starting} onClick={() => onStart(selected)}>
        スタート <kbd className="hint">Space</kbd>
      </button>
      {error !== null && <p className="error">{error}</p>}
    </div>
  )
}

function PlayScreen({
  step,
  difficulty,
  cpuHand,
  timeLimitMs,
  pressed,
  judging,
}: {
  step: CountdownStep
  difficulty: DifficultyType
  cpuHand: HandType | null
  timeLimitMs: number | null
  pressed: HandType | null
  judging: boolean
}) {
  const signaled = step === 'pon'
  const prompt = judging ? '判定中…' : pressed !== null ? '出した!' : signaled ? '勝つ手を出せ!!' : 'Ready…'
  return (
    <div className="screen play-screen">
      <p className={`play-status difficulty-text-${difficulty}`}>
        {DIFFICULTY_LABEL[difficulty]}
        {timeLimitMs !== null && <> / {(timeLimitMs / 1000).toFixed(1)}秒</>}
      </p>
      <div className="stage">
        {signaled && cpuHand !== null ? (
          <>
            <p className="stage-pon">{COUNTDOWN_TEXT.pon}</p>
            <p className="cpu-hand" key={cpuHand}>
              {HAND_EMOJI[cpuHand]}
            </p>
          </>
        ) : (
          <p className="countdown">{COUNTDOWN_TEXT[step]}</p>
        )}
      </div>
      <p className={`play-prompt ${signaled && pressed === null && !judging ? 'urgent' : ''}`}>{prompt}</p>
      <div className="hand-keys">
        {(Object.keys(HAND_EMOJI) as HandType[]).map((hand, i) => (
          <div key={hand} className={`hand-key ${pressed === hand ? 'pressed' : ''} ${pressed !== null && pressed !== hand ? 'unused' : ''}`}>
            <kbd>{['J', 'K', 'L'][i]}</kbd>
            <span className="hand-key-emoji">{HAND_EMOJI[hand]}</span>
            <span className="hand-key-label">{HAND_LABEL[hand]}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

const LOSE_REASON_TEXT: Record<Exclude<JudgeReason, 'hand'>, string> = {
  copy: '🌀 つられた! CPU と同じ手',
  wrong_hand: '💥 負ける手を出してしまった',
  late: '⏰ 時間切れ',
  foul: '🚨 フライング',
  timeout: '😴 手が出なかった',
}

// 反応・残りなどの秒数を目立たせるハイライト表示
function Sec({ ms, prefix = '' }: { ms: number; prefix?: string }) {
  return (
    <strong className="delta-num">
      {prefix}
      {(Math.abs(ms) / 1000).toFixed(3)} 秒
    </strong>
  )
}

function getDetailText(r: JudgeResponse): ReactNode | null {
  if (r.reason === 'foul' && r.reaction_ms !== null) {
    return (
      <>
        合図の <Sec ms={r.reaction_ms} />前に押していた
      </>
    )
  }
  if (r.reason === 'late' && r.margin_ms !== null) {
    return (
      <>
        制限 {(r.time_limit_ms / 1000).toFixed(1)} 秒に対して <Sec ms={r.margin_ms} prefix="+" />オーバー
      </>
    )
  }
  if (r.reaction_ms !== null && r.margin_ms !== null && r.margin_ms >= 0) {
    return (
      <>
        反応 <Sec ms={r.reaction_ms} /> / 残り <Sec ms={r.margin_ms} />
      </>
    )
  }
  return null
}

function ResultScreen({ result, error, onBack }: { result: JudgeResponse | null; error: string | null; onBack: () => void }) {
  if (result === null) {
    return (
      <div className="screen result-screen">
        <h1>エラー</h1>
        <p className="error">{error ?? '判定結果を取得できませんでした'}</p>
        <button className="start-button" onClick={onBack}>
          タイトルへ <kbd className="hint">Space</kbd>
        </button>
      </div>
    )
  }

  const won = result.result === 'win'
  const reasonText = won ? '🏁 制限時間内に勝つ手を出した!' : LOSE_REASON_TEXT[result.reason as Exclude<JudgeReason, 'hand'>]
  const detailText = getDetailText(result)
  const mackerelBase = (import.meta.env.VITE_MACKEREL_TRACE_URL_BASE as string | undefined) ?? 'https://mackerel.io/my/traces/'

  return (
    <div className="screen result-screen">
      <p className="win-reason">{reasonText}</p>
      <h1 className={`headline ${won ? 'headline-win' : 'headline-lose'}`}>{won ? '勝ち!' : '負け…'}</h1>
      <div className="result-players">
        <div className={`result-player ${won ? '' : 'winner'}`}>
          {!won && <span className="win-badge">WIN</span>}
          <h3>CPU</h3>
          <p className="result-hand">{HAND_EMOJI[result.cpu_hand]}</p>
          <p>{HAND_LABEL[result.cpu_hand]}</p>
        </div>
        <div className={`result-player ${won ? 'winner' : ''}`}>
          {won && <span className="win-badge">WIN</span>}
          <h3>あなた</h3>
          <p className="result-hand">{result.hand !== null ? HAND_EMOJI[result.hand] : '—'}</p>
          <p>{result.hand !== null ? HAND_LABEL[result.hand] : '手なし'}</p>
        </div>
      </div>
      {detailText !== null && <p className="delta">{detailText}</p>}
      {result.trace_id !== null && (
        <p className="trace">
          トレースID:{' '}
          <a href={`${mackerelBase}${result.trace_id}`} target="_blank" rel="noreferrer">
            <code>{result.trace_id}</code>
          </a>
        </p>
      )}
      <button className="start-button" onClick={onBack}>
        もう一回 <kbd className="hint">Space</kbd>
      </button>
    </div>
  )
}
