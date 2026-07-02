/// <reference types="vite/client" />

declare module '*.vue' {
  import type { DefineComponent } from 'vue'
  const component: DefineComponent<object, object, unknown>
  export default component
}

// Globally-registered FontAwesome components (see main.ts) so templates can use
// <FontAwesomeIcon> / <FontAwesomeLayers> with full type-checking.
declare module 'vue' {
  import type { FontAwesomeIcon, FontAwesomeLayers } from '@fortawesome/vue-fontawesome'
  export interface GlobalComponents {
    FontAwesomeIcon: typeof FontAwesomeIcon
    FontAwesomeLayers: typeof FontAwesomeLayers
  }
}
