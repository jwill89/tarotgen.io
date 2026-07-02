<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { useToasts, type ToastType } from '@/composables/useToasts'

const { toasts, dismiss } = useToasts()

const ICONS: Record<ToastType, string> = {
  success: 'fa-circle-check',
  error: 'fa-circle-exclamation',
  warning: 'fa-triangle-exclamation',
  info: 'fa-circle-info',
}
</script>

<template>
  <div class="toast-stack" aria-live="polite" aria-atomic="false">
    <TransitionGroup name="toast">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        class="toast-item"
        :class="'toast-item--' + toast.type"
        role="status"
      >
        <span class="toast-icon icon">
          <FontAwesomeIcon :icon="ICONS[toast.type]" />
        </span>
        <span class="toast-message">{{ toast.message }}</span>
        <button class="toast-close" aria-label="Dismiss" @click="dismiss(toast.id)">
          <FontAwesomeIcon :icon="byPrefixAndName.fas['xmark']" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-stack {
  position: fixed;
  top: 4.25rem; /* clear the fixed navbar */
  right: 1rem;
  z-index: 11000;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  width: min(24rem, calc(100vw - 2rem));
  pointer-events: none;
}

.toast-item {
  pointer-events: auto;
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  padding: 0.85rem 1rem;
  border-radius: 12px;
  color: #f5f3ff;
  background: #2a2342;
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-left-width: 4px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.45);
}

.toast-item--success {
  border-left-color: #4ade80;
}
.toast-item--error {
  border-left-color: #f87171;
}
.toast-item--warning {
  border-left-color: #fbbf24;
}
.toast-item--info {
  border-left-color: #a78bfa;
}

.toast-icon {
  flex: none;
  margin-top: 0.1rem;
}
.toast-item--success .toast-icon {
  color: #4ade80;
}
.toast-item--error .toast-icon {
  color: #f87171;
}
.toast-item--warning .toast-icon {
  color: #fbbf24;
}
.toast-item--info .toast-icon {
  color: #a78bfa;
}

.toast-message {
  flex: 1 1 auto;
  line-height: 1.35;
  font-size: 0.95rem;
  word-break: break-word;
}

.toast-close {
  flex: none;
  cursor: pointer;
  background: none;
  border: none;
  color: inherit;
  opacity: 0.6;
  padding: 0 0.15rem;
  font-size: 1rem;
  line-height: 1;
  transition: opacity 0.15s ease;
}

.toast-close:hover {
  opacity: 1;
}

/* Enter/leave animation */
.toast-enter-active,
.toast-leave-active {
  transition:
    transform 0.25s ease,
    opacity 0.25s ease;
}

.toast-enter-from {
  transform: translateX(120%);
  opacity: 0;
}

.toast-leave-to {
  transform: translateX(120%);
  opacity: 0;
}

.toast-leave-active {
  position: absolute;
  right: 0;
  width: 100%;
}
</style>
