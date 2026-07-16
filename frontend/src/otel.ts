import { ZoneContextManager } from '@opentelemetry/context-zone'
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-proto'
import { registerInstrumentations } from '@opentelemetry/instrumentation'
import { FetchInstrumentation } from '@opentelemetry/instrumentation-fetch'
import { resourceFromAttributes } from '@opentelemetry/resources'
import { SimpleSpanProcessor, WebTracerProvider } from '@opentelemetry/sdk-trace-web'

export function setupOtel(): void {
  const provider = new WebTracerProvider({
    resource: resourceFromAttributes({
      'service.name': 'janken-web',
      'service.namespace': 'janken',
      'deployment.environment.name': import.meta.env.DEV ? 'local' : 'booth',
    }),
    // 低頻度なので即時性を優先して Simple にする
    spanProcessors: [
      new SimpleSpanProcessor(
        new OTLPTraceExporter({
          url: import.meta.env.VITE_OTLP_TRACES_URL ?? 'http://localhost:4318/v1/traces',
        }),
      ),
    ],
  })
  provider.register({ contextManager: new ZoneContextManager() })
  registerInstrumentations({
    instrumentations: [new FetchInstrumentation()],
  })
}
