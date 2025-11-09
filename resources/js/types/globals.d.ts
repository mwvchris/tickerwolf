/**
 * --------------------------------------------------------------------------
 * Global Type Declarations — TickerWolf.ai
 * --------------------------------------------------------------------------
 *  Provides TypeScript support for:
 *   • Vite environment variables
 *   • Inertia PageProps interface merging
 *   • Vue global properties ($inertia, $page)
 *   • Global window.route() helper (Ziggy)
 * --------------------------------------------------------------------------
 */

import type { AppPageProps } from '@/types/index'
import type { Page, Router } from '@inertiajs/core'
import type { createHeadManager } from '@inertiajs/vue3'

// -----------------------------------------
// Vite environment + import.meta extensions
// -----------------------------------------
declare module 'vite/client' {
  interface ImportMetaEnv {
    readonly VITE_APP_NAME: string
    [key: string]: string | boolean | undefined
  }

  interface ImportMeta {
    readonly env: ImportMetaEnv
    readonly glob: <T = unknown>(pattern: string) => Record<string, () => Promise<T>>
  }
}

// -----------------------------------------
// Inertia global PageProps merging
// -----------------------------------------
declare module '@inertiajs/core' {
  interface PageProps extends AppPageProps {}
}

// -----------------------------------------
// Vue global property augmentation
// -----------------------------------------
declare module 'vue' {
  interface ComponentCustomProperties {
    $inertia: typeof Router
    $page: Page
    $headManager: ReturnType<typeof createHeadManager>
  }
}

// -----------------------------------------
// Global window interface
// -----------------------------------------
declare global {
  interface Window {
    route: (name?: string, params?: any, absolute?: boolean) => string
  }
}

export {}
