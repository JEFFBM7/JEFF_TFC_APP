import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

// Proxy partagé entre le serveur de dev et le serveur de preview :
// permet de tester l'app complète (API incluse) via un tunnel HTTPS unique
// pointant sur le port de preview (même origine -> pas de CORS).
const apiProxy = {
  '/api': {
    target: 'http://localhost:8000',
    changeOrigin: true,
  },
  '/broadcasting': {
    target: 'http://localhost:8000',
    changeOrigin: true,
  },
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      includeAssets: ['favicon.svg', 'pwa/apple-touch-icon.png'],
      manifest: false,
      workbox: {
        navigateFallback: '/index.html',
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        // Les splash iOS sont chargés via <link apple-touch-startup-image>,
        // inutile de les précacher (sinon +13 Mo dans le service worker).
        globIgnores: ['**/pwa/splash/**'],
        runtimeCaching: [],
      },
      devOptions: { enabled: false },
    }),
  ],
  server: {
    proxy: apiProxy,
  },
  preview: {
    proxy: apiProxy,
  },
})
