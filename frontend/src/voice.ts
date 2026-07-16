// カウントダウンの掛け声(macOS の say -v Kyoko で生成した public/sounds/*.wav)。
// 「ぽん!」は反応計測の基準(signalAt)と同時に鳴らすため、
// 遅延が読めない speechSynthesis ではなくプリロード済み AudioBuffer を使う
const FILES = {
  jan: '/sounds/jan.wav',
  ken: '/sounds/ken.wav',
  pon: '/sounds/pon.wav',
} as const

export type VoiceName = keyof typeof FILES

let ctx: AudioContext | null = null
const buffers: Partial<Record<VoiceName, AudioBuffer>> = {}

export async function preloadVoices(): Promise<void> {
  ctx ??= new AudioContext()
  await Promise.all(
    (Object.keys(FILES) as VoiceName[]).map(async (name) => {
      if (buffers[name] !== undefined) return
      const res = await fetch(FILES[name])
      buffers[name] = await ctx!.decodeAudioData(await res.arrayBuffer())
    }),
  )
}

export function playVoice(name: VoiceName): void {
  const buffer = buffers[name]
  if (ctx === null || buffer === undefined) return
  // 初回はユーザー操作前に作った AudioContext が suspended のままのことがある
  if (ctx.state === 'suspended') void ctx.resume()
  const source = ctx.createBufferSource()
  source.buffer = buffer
  source.connect(ctx.destination)
  source.start()
}
