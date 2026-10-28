import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { statSync } from 'node:fs'

/**
 * Append a cache-busting `?v=<mtime>` to a project-root asset's URL so social
 * crawlers (Facebook, X, Discord, …), which cache OG images hard and keyed by
 * URL, refetch whenever the file actually changes. Returns the URL unchanged if
 * the file can't be stat'd. mtime keeps this automatic — no manual version bump.
 */
function withAssetVersion(url: string, relPath: string): string {
  try {
    // Seconds (not ms) to match og.php's filemtime() token for the same file.
    return `${url}?v=${Math.floor(statSync(resolve(import.meta.dirname, relPath)).mtimeMs / 1000)}`
  } catch {
    // Warn loudly rather than failing silently: without the token, crawlers keep
    // serving whatever banner they cached first.
    console.warn(
      `[cachebust-og-image] could not stat "${relPath}" — shipping ${url} with no version token`,
    )
    return url
  }
}

export default defineConfig({
  plugins: [
    vue(),
    {
      // Cache-bust the homepage's OG/Twitter share image (served as the
      // static dist/index.html). Reading pages are handled in og.php.
      name: 'cachebust-og-image',
      transformIndexHtml(html) {
        return html.replaceAll(
          'https://tarotgen.io/assets/share_banner.png',
          withAssetVersion(
            'https://tarotgen.io/assets/share_banner.png',
            // The banner is served from the BACKEND web root at /assets/ (the
            // deploy flattens backend/ + frontend/dist/ into one root), so it is
            // NOT under frontend/. Statting the wrong path silently shipped the
            // URL unversioned.
            '../backend/assets/share_banner.png',
          ),
        )
      },
    },
  ],
  resolve: {
    alias: {
      '@': resolve(import.meta.dirname, 'src'),
    },
  },
  css: {
    preprocessorOptions: {
      scss: {
        // Bulma 1.0.4's own sources still use the deprecated `if()` call, which
        // otherwise prints a wall of warnings on every build. Nothing we can fix
        // from here — drop it when Bulma ships a version that doesn't.
        silenceDeprecations: ['if-function'],
      },
    },
  },
  test: {
    environment: 'happy-dom',
    setupFiles: ['./vitest.setup.ts'],
    include: ['src/**/*.spec.ts'],
    restoreMocks: true,
    unstubGlobals: true,
    // `npm run test:coverage`. Measured over all of src, untested files included,
    // so the numbers say how much of the app the specs reach.
    coverage: {
      provider: 'v8',
      include: ['src/**/*.{ts,vue}'],
      exclude: ['src/__tests__/**', 'src/types/**', 'src/**/*.d.ts'],
      reporter: ['text-summary', 'html'],
      // The floor, a little under what the specs reached when coverage was first
      // measured (2026-10-28: 10.5 / 6.3 / 12.1 / 10.0%). Raise it as specs land;
      // never lower it.
      thresholds: { statements: 10, branches: 6, functions: 12, lines: 10 },
    },
  },
  build: {
    outDir: 'dist',
    assetsDir: '_app',
    emptyOutDir: true,
    rolldownOptions: {
      output: {
        codeSplitting: {
          groups: [
            {
              name: 'vendor-vue',
              test: /[\\/]node_modules[\\/](vue|vue-router)[\\/]/,
            },
            {
              name: 'vendor-tiptap',
              test: /[\\/]node_modules[\\/]@tiptap[\\/]/,
            },
            {
              name: 'vendor-markdown',
              test: /[\\/]node_modules[\\/](marked|dompurify|turndown)[\\/]/,
            },
          ],
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
