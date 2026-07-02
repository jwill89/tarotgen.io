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
]
