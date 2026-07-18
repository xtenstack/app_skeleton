import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';
import checker from 'vite-plugin-checker';
import tsconfigPaths from 'vite-tsconfig-paths';

export default ({ mode }) => {
  process.env = { ...process.env, ...loadEnv(mode, process.cwd()) };

  return defineConfig({
    plugins: [
      tsconfigPaths(),
      react(),
      checker({
        typescript: true,
        eslint: {
          useFlatConfig: true,
          lintCommand: 'eslint "./src/**/*.{ts,tsx}"',
        },
        overlay: {
          initialIsOpen: false,
        },
      }),
    ],
    preview: {
      port: Number(process.env.VITE_APP_PORT || 5005),
    },
    server: {
      host: '0.0.0.0',
      port: Number(process.env.VITE_APP_PORT || 5005),
      proxy: {
        '/api': 'http://localhost:8098',
      },
    },
    base: process.env.NODE_ENV === 'production' ? process.env.VITE_BASENAME : '/',
    build: {
      // Built into the PHP app's public/ dir so `npm run build` output ships
      // with the whole app — isolated under app/ so it can be safely wiped
      // and rebuilt without touching public/index.php or public/assets/adminlte.
      outDir: '../public/app',
      emptyOutDir: true,
    },
  });
};
