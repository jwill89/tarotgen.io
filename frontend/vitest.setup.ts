import { afterEach } from 'vitest'
import { takeUnexpectedApiCalls } from './src/__tests__/helpers/api'

// Pin the timezone so date-formatting assertions are deterministic regardless
// of the machine running the suite.
process.env.TZ = 'UTC'

// A request stubApi had no reply for reaches the code as a network error, which
// it may handle without a trace: fail the test that made it.
afterEach(() => {
  const calls = takeUnexpectedApiCalls()
  if (calls.length > 0) {
    throw new Error(`API calls the test didn't stub: ${calls.join(', ')}`)
  }
})
