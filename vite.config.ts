import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': resolve(__dirname, 'src'),
        },
    },
    test: {
        environment: 'happy-dom',
        setupFiles: ['./vitest.setup.ts'],
        include: ['src/**/*.spec.ts'],
        restoreMocks: true,
        unstubGlobals: true,
    },
    build: {
        outDir: 'dist',
        assetsDir: '_app',
        emptyOutDir: true,
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor-vue': ['vue', 'vue-router'],
                    'vendor-tiptap': [
                        '@tiptap/vue-3',
                        '@tiptap/starter-kit',
                        '@tiptap/extension-link',
                        '@tiptap/extension-placeholder',
                    ],
                    'vendor-markdown': ['marked', 'dompurify', 'turndown'],
                },
            },
        },
    },
    server: {
        proxy: {
            '/api': 'http://localhost:80',
            '/assets': 'http://localhost:80',
        },
    },
})
