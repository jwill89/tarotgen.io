import pluginVue from 'eslint-plugin-vue'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'

// Flat config (ESLint 9+). Lints the SPA source (TS + Vue SFCs); the PHP
// backend, build output, deps, and PWA assets are out of scope.
export default defineConfigWithVueTs(
    {
        name: 'app/files-to-lint',
        files: ['**/*.{ts,mts,tsx,vue}'],
    },
    {
        name: 'app/files-to-ignore',
        ignores: [
            'dist/**',
            'node_modules/**',
            'vendor/**',
            'public/**',
            'coverage/**',
        ],
    },
    pluginVue.configs['flat/essential'],
    vueTsConfigs.recommended,
)
