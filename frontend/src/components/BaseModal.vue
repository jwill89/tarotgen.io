<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { watch, onBeforeUnmount } from 'vue'

const props = withDefaults(
  defineProps<{
    active: boolean
    title?: string
    /** Max width of the panel (any CSS length). Defaults to a compact dialog. */
    maxWidth?: string
    /** Allow closing by clicking the backdrop (default true). */
    closeOnBackdrop?: boolean
  }>(),
  {
    title: '',
    maxWidth: '30rem',
    closeOnBackdrop: true,
  },
)

const emit = defineEmits<{ close: [] }>()

function onKeydown(e: KeyboardEvent): void {
  if (e.key === 'Escape') emit('close')
}

// Bind Escape + lock body scroll only while open.
watch(
  () => props.active,
  (open) => {
    if (open) {
      document.addEventListener('keydown', onKeydown)
      document.body.style.overflow = 'hidden'
    } else {
      document.removeEventListener('keydown', onKeydown)
      document.body.style.overflow = ''
    }
  },
)

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div v-if="active" class="modal-overlay" @click.self="closeOnBackdrop && emit('close')">
        <div class="modal-panel" role="dialog" aria-modal="true" :style="{ maxWidth }">
          <header class="modal-panel-head">
            <h2 class="modal-panel-title">{{ title }}</h2>
            <button
              class="modal-panel-close"
              type="button"
              aria-label="Close"
              @click="emit('close')"
            >
              <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
            </button>
          </header>
          <div class="modal-panel-body">
            <slot />
          </div>
          <footer v-if="$slots.footer" class="modal-panel-foot">
            <slot name="footer" />
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 10500;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
  overflow-y: auto;
  background: rgba(8, 6, 16, 0.72);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
}

.modal-panel {
  width: 100%;
  margin: auto;
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 3rem);
  background: #1c1636;
  border: 1px solid rgba(233, 196, 106, 0.22);
  border-radius: 16px;
  box-shadow: 0 18px 60px rgba(0, 0, 0, 0.6);
  overflow: hidden;
}

.modal-panel-head {
  flex: none;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.1rem 1.25rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.modal-panel-title {
  font-family: var(--myst-heading-font);
  font-variant-caps: small-caps;
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--myst-gold, #e9c46a);
  margin: 0;
}

.modal-panel-close {
  flex: none;
  cursor: pointer;
  background: none;
  border: none;
  color: #cfc7e6;
  font-size: 1.25rem;
  line-height: 1;
  width: 2rem;
  height: 2rem;
  border-radius: 8px;
  transition:
    background-color 0.15s ease,
    color 0.15s ease;
}

.modal-panel-close:hover {
  background-color: rgba(255, 255, 255, 0.1);
  color: #fff;
}

.modal-panel-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
  padding: 1.25rem;
  color: #e6e1f5;
  line-height: 1.5;
}

.modal-panel-foot {
  flex: none;
  display: flex;
  justify-content: flex-end;
  gap: 0.6rem;
  padding: 1rem 1.25rem;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
}

/* Enter/leave: fade the backdrop and pop the panel. */
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-active .modal-panel,
.modal-leave-active .modal-panel {
  transition:
    transform 0.2s ease,
    opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-from .modal-panel,
.modal-leave-to .modal-panel {
  transform: translateY(12px) scale(0.97);
  opacity: 0;
}
</style>
