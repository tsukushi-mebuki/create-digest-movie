import { defineConfig } from "vitest/config";
import path from "node:path";

export default defineConfig({
  test: {
    environment: "jsdom",
    setupFiles: ["./vitest.setup.ts"],
    include: ["apps/web/tests/unit/**/*.test.ts", "apps/web/tests/unit/**/*.test.tsx"],
  },
  resolve: {
    alias: {
      "@": path.resolve(__dirname),
    },
  },
});
