// API クライアント。仕様は docs/endpoints.md
export type HandType = 'rock' | 'scissors' | 'paper'
export type DifficultyType = 'ok' | 'warn' | 'critical'
export type JudgeReason = 'hand' | 'copy' | 'wrong_hand' | 'late' | 'foul' | 'timeout'

export interface CreateGameResponse {
  game_id: string
  cpu_hand: HandType
  time_limit_ms: number
}

export interface HandPayload {
  hand: HandType
  pressed_at: number
}

export interface JudgePayload {
  countdown_started_at: number
  ken_at: number
  signal_at: number
  hand: HandPayload | null
}

export interface JudgeResponse {
  result: 'win' | 'lose'
  reason: JudgeReason
  cpu_hand: HandType
  hand: HandType | null
  reaction_ms: number | null
  margin_ms: number | null
  time_limit_ms: number
  trace_id: string | null
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json' },
    ...init,
  })
  if (!res.ok) {
    const body = await res.json().catch(() => null)
    throw new Error(body?.message ?? `API error: ${res.status}`)
  }
  const text = await res.text()
  return (text === '' ? null : JSON.parse(text)) as T
}

export const api = {
  createGame: (difficulty: DifficultyType) =>
    request<CreateGameResponse>('/api/games', { method: 'POST', body: JSON.stringify({ difficulty }) }),

  // fire-and-forget 前提(呼び出し側で await しない)。失敗しても判定は judge ペイロードで成立する
  sendHand: (gameId: string, hand: HandPayload) =>
    request<null>(`/api/games/${gameId}/hands`, { method: 'POST', body: JSON.stringify(hand) }),

  judge: (gameId: string, payload: JudgePayload) =>
    request<JudgeResponse>(`/api/games/${gameId}/judge`, { method: 'POST', body: JSON.stringify(payload) }),
}
