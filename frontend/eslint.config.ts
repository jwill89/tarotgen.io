import shared from '@jwill89/eslint-config'

// Shared ruleset (Vue 3 + TypeScript strictTypeChecked + Prettier) lives in the
// @jwill89/eslint-config submodule (frontend/eslint-config). Only project-specific
// ignores live here. Build/tooling config files are not linted.
export default [
  {
    name: 'tarot/ignores',
    ignores: [
      'dist/**',
      'coverage/**',
      'public/**',
      'eslint-config/**', // the shared-config submodule itself
      'src/types/api.generated.ts', // generated from the OpenAPI spec
      '*.config.ts',
      '*.config.js',
      'vitest.setup.ts',
    ],
  },
  ...shared,
  {
    // Every API call goes through composables/apiClient.ts, so a failure always
    // arrives as the server's message, a status and a network flag — not as a
    // raw Response each call site parses its own way (AUDIT §4.2).
    name: 'tarot/api-client-only',
    files: ['src/**/*.{ts,vue}'],
    ignores: ['src/composables/apiClient.ts', 'src/__tests__/**'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: "CallExpression[callee.name='fetch']",
          message: 'Call the API through apiRequest/apiFetch (composables/apiClient.ts).',
        },
        {
          selector:
            "MemberExpression[object.name=/^(window|globalThis|self)$/][property.name='fetch']",
          message: 'Call the API through apiRequest/apiFetch (composables/apiClient.ts).',
        },
      ],
    },
  },
]
