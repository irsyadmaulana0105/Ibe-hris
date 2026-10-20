import { defineConfig, loadEnv, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'path'

const trustedBrowserUrlFallback: Plugin = {
  name: 'ibe-trusted-browser-url-fallback',
  generateBundle(_options, bundle) {
    const unsafeFallback = 'http://localhost'
    const trustedFallback = 'https://portal.ibereality.com'
    let replacements = 0

    for (const output of Object.values(bundle)) {
      if (output.type !== 'chunk' || !output.code) continue

      const matches = output.code.split(unsafeFallback).length - 1
      if (matches > 0) {
        output.code = output.code.replaceAll(unsafeFallback, trustedFallback)
        replacements += matches
      }
    }

    if (replacements !== 2) {
      throw new Error(`Expected exactly two third-party browser fallbacks, replaced ${replacements}`)
    }
  },
}

export default defineConfig(({ mode }) => {
  // loadEnv reads .env, .env.[mode], .env.local, etc. into a plain object
  // so VITE_BUILD_OUT_DIR in .env.production is properly picked up at build time
  const env = loadEnv(mode, process.cwd(), '')

  return {
  plugins: [react(), trustedBrowserUrlFallback],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: env.VITE_API_BASE_URL ?? 'http://localhost',
        changeOrigin: true,
        rewrite: (path) => path,
      },
    },
  },
  build: {
    outDir: env.VITE_BUILD_OUT_DIR || 'dist',
    emptyOutDir: true,
    chunkSizeWarningLimit: 1500,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) {
            return
          }

          if (id.includes('@vladmandic/face-api')) {
            return 'face-api'
          }

          if (id.includes('react-router-dom')) {
            return 'router'
          }

          if (id.includes('@tanstack/react-query')) {
            return 'react-query'
          }

          if (id.includes('bootstrap') || id.includes('@popperjs/core')) {
            return 'bootstrap'
          }

          if (
            id.includes('/react/')
            || id.includes('\\react\\')
            || id.includes('/react-dom/')
            || id.includes('\\react-dom\\')
            || id.includes('/scheduler/')
            || id.includes('\\scheduler\\')
          ) {
            return 'react-vendor'
          }
        },
      },
    },
  },
  }
})
