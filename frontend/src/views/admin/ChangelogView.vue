<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, reactive, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import BaseModal from '@/components/BaseModal.vue'
import PageHeader from '@/components/PageHeader.vue'
import IconButton from '@/components/IconButton.vue'
import type { ChangelogEntry } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()

const entries = ref<ChangelogEntry[]>([])
const showEdit = ref(false)
const editingId = ref<number | null>(null)
const saving = ref(false)
const errorMsg = ref('')

const form = reactive({
  title: '',
  entry_date: '',
  body: '',
})

function todayIso(): string {
  return new Date().toISOString().slice(0, 10)
}

async function fetchChangelog() {
  const data = await api.get<ChangelogEntry[]>(endpoints.admin.changelog.list)
  if (data) entries.value = data
}

function openAdd() {
  editingId.value = null
  form.title = ''
  form.entry_date = todayIso()
  form.body = ''
  errorMsg.value = ''
  showEdit.value = true
}

function openEdit(entry: ChangelogEntry) {
  editingId.value = entry.entry_id
  form.title = entry.title
  form.entry_date = entry.entry_date
  form.body = entry.body
  errorMsg.value = ''
  showEdit.value = true
}

function closeEdit() {
  showEdit.value = false
  editingId.value = null
}

async function saveEntry() {
  if (!form.title.trim()) {
    errorMsg.value = 'A title is required.'
    return
  }

  const payload = { title: form.title, entry_date: form.entry_date, body: form.body }

  saving.value = true
  try {
    const result =
      editingId.value !== null
        ? await api.put<ChangelogEntry>(
            endpoints.admin.changelog.byId(editingId.value),
            payload,
            'Entry updated.',
          )
        : await api.post<ChangelogEntry>(endpoints.admin.changelog.list, payload, 'Entry created.')

    if (result) {
      await fetchChangelog()
      closeEdit()
    } else {
      errorMsg.value = 'Failed to save the entry. Please try again.'
    }
  } finally {
    saving.value = false
  }
}

async function deleteEntry(entry: ChangelogEntry) {
  const ok = await confirm({
    title: 'Delete entry',
    message: `Delete "${entry.title}"? This cannot be undone.`,
    confirmLabel: 'Delete',
    danger: true,
  })
  if (!ok) return
  const result = await api.del(endpoints.admin.changelog.byId(entry.entry_id), 'Entry deleted.')
  if (result) await fetchChangelog()
}

onMounted(fetchChangelog)
</script>

<template>
  <section class="section">
    <div class="container">
      <div class="columns is-centered">
        <div class="column is-11-desktop is-12-tablet">
          <router-link :to="{ name: 'admin-dashboard' }" class="button is-small is-ghost mb-4">
            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
            <span>Back to Dashboard</span>
          </router-link>

          <PageHeader
            title="Manage Changelog"
            subtitle="Post news and updates for the changelog page."
          >
            <button class="button is-primary" @click="openAdd">
              <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
              <span>Add Entry</span>
            </button>
          </PageHeader>

          <div class="settings-panel">
            <div class="table-container">
              <table class="table is-fullwidth is-hoverable is-striped">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Title</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="entry in entries" :key="entry.entry_id">
                    <td>{{ entry.entry_date }}</td>
                    <td>{{ entry.title }}</td>
                    <td>
                      <div class="row-actions">
                        <IconButton
                          :icon="byPrefixAndName.fas['pen-to-square']"
                          label="Edit"
                          @click="openEdit(entry)"
                        />
                        <IconButton
                          :icon="byPrefixAndName.fas['trash']"
                          label="Delete"
                          intent="danger"
                          @click="deleteEntry(entry)"
                        />
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <p v-if="entries.length === 0" class="has-text-grey">
            No entries yet. Click "Add Entry" to create one.
          </p>
        </div>
      </div>
    </div>
  </section>

  <!-- Add/Edit Modal -->
  <BaseModal
    :active="showEdit"
    :title="editingId !== null ? 'Edit Entry' : 'Add Entry'"
    max-width="640px"
    @close="closeEdit"
  >
    <div v-if="errorMsg" class="notification is-danger">{{ errorMsg }}</div>

    <div class="field">
      <label class="label" for="cl-title">Title</label>
      <input id="cl-title" v-model="form.title" class="input" maxlength="200" autocomplete="off" />
    </div>

    <div class="field">
      <label class="label" for="cl-date">Date</label>
      <input id="cl-date" v-model="form.entry_date" class="input" type="date" />
    </div>

    <div class="field">
      <label class="label" for="cl-body">Body</label>
      <textarea id="cl-body" v-model="form.body" class="textarea" rows="8"></textarea>
      <p class="help">Markdown is supported (e.g. **bold**, _italic_, links).</p>
    </div>

    <template #footer>
      <button class="button" @click="closeEdit">Cancel</button>
      <button class="button is-success" :class="{ 'is-loading': saving }" @click="saveEntry">
        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
        <span>{{ editingId !== null ? 'Save' : 'Create' }}</span>
      </button>
    </template>
  </BaseModal>
</template>
