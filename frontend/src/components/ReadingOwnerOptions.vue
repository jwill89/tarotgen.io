<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref } from 'vue'
import { useUser } from '@/composables/useUser'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import ToggleSwitch from '@/components/ToggleSwitch.vue'

const props = withDefaults(defineProps<{ notes?: boolean }>(), { notes: false })

const { isLoggedIn } = useUser()

const readingName = ref('')
const hideUser = ref(false)
const password = ref('')
const showPassword = ref(false)
const readingNotes = ref('')

/** Returns the chosen options, or null for guests (who can't set any). */
function collect(): {
  reading_name: string
  hide_user: boolean
  password: string
  reading_notes: string
} | null {
  if (!isLoggedIn.value) return null
  return {
    reading_name: readingName.value.trim(),
    hide_user: hideUser.value,
    password: password.value,
    reading_notes: props.notes ? readingNotes.value : '',
  }
}

function reset(): void {
  readingName.value = ''
  hideUser.value = false
  password.value = ''
  showPassword.value = false
  readingNotes.value = ''
}

defineExpose({ collect, reset })
</script>

<template>
  <div v-if="isLoggedIn" class="box reading-owner-options">
    <p class="title is-5 mb-3 reading-owner-heading">
      Reading Options
      <span class="has-text-grey is-size-7 has-text-weight-normal">(you're signed in)</span>
    </p>

    <div class="field">
      <label class="label" for="ro-name"
        >Reading Title
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
      >
      <input
        id="ro-name"
        v-model="readingName"
        class="input"
        maxlength="100"
        autocomplete="off"
        placeholder="e.g. Morning reflection"
      />
    </div>

    <div class="field">
      <ToggleSwitch v-model="hideUser">Hide my display name on this reading</ToggleSwitch>
    </div>

    <div class="field">
      <label class="label" for="ro-password"
        >View Password
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
      >
      <input
        id="ro-password"
        v-model="password"
        class="input"
        :type="showPassword ? 'text' : 'password'"
        autocomplete="new-password"
        placeholder="Require a password to view"
      />
      <p class="help">If set, anyone but you must enter this password to view the reading.</p>
      <ToggleSwitch v-if="password" v-model="showPassword" class="mt-2">Show password</ToggleSwitch>
    </div>

    <div v-if="notes" class="field">
      <label class="label"
        >Reading Notes
        <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label
      >
      <MarkdownEditor
        v-model="readingNotes"
        placeholder="Write a detailed interpretation… Markdown is supported."
      />
      <p class="help">
        A longer written explanation, shown in a collapsible section on the reading.
      </p>
    </div>
  </div>

  <p v-else class="help mb-4">
    <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-info']" /></span>
    <router-link :to="{ name: 'login' }">Log in</router-link>
    to name your reading, hide your display name, or set a view password.
  </p>
</template>

<style scoped>
.reading-owner-options {
  border: 1px solid var(--myst-border, rgba(255, 255, 255, 0.12));
}
</style>
