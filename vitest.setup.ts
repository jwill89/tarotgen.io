// Pin the timezone so date-formatting assertions are deterministic regardless
// of the machine running the suite.
process.env.TZ = 'UTC'
