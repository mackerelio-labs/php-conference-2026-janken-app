import { createRoot } from 'react-dom/client'
import App from './App'
import { setupOtel } from './otel'
import './styles.css'

setupOtel()

createRoot(document.getElementById('root')!).render(<App />)
