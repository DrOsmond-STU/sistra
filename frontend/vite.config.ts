import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  build: {
    // ".r2" membedakan nama berkas dari entri cache CDN lama yang rusak
    rollupOptions: {
      output: {
        entryFileNames: "assets/[name]-[hash].r2.js",
        chunkFileNames: "assets/[name]-[hash].r2.js",
        assetFileNames: "assets/[name]-[hash].r2[extname]",
      },
    },
  },
  server: {
    port: 5173,
    proxy: { "/api": { target: process.env.SISTRA_API ?? "http://localhost:8000", changeOrigin: false } },
  },
});
