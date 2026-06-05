<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useAccount } from '@/composables/useAccount'
import { useToasts } from '@/composables/useToasts'
import { useConfirm } from '@/composables/useConfirm'
import { formatDateTime } from '@/utils/datetime'
import BaseModal from '@/components/BaseModal.vue'
import MarkdownEditor from '@/components/MarkdownEditor.vue'
import type { AccountReading } from '@/types'

const { listReadings, updateReading, deleteReading } = useAccount()
const toasts = useToasts()
const { confirm } = useConfirm()
const deletingId = ref<string | null>(null)

const readings = ref<AccountReading[]>([])
const loading = ref(true)

const showEdit = ref(false)
const saving = ref(false)
const editId = ref<string | null>(null)
const errorMsg = ref('')

const form = reactive({
    reading_name: '',
    reading_notes: '',
    hide_user: false,
    password: '',
    remove_password: false,
    has_password: false,
})

function summarize(r: AccountReading): string {
    if (r.reading_name) return r.reading_name
    try {
        const info = r.reading_info
        if (info.spread?.name) return info.spread.name
        const n = info.draw?.length ?? 0
        return n + (n === 1 ? ' card' : ' cards')
    } catch {
        return 'Reading'
    }
}

async function load() {
    loading.value = true
    readings.value = await listReadings()
    loading.value = false
}

function openEdit(r: AccountReading) {
    editId.value = r.reading_id
    form.reading_name = r.reading_name ?? ''
    form.reading_notes = r.reading_notes ?? ''
    form.hide_user = r.hide_user
    form.password = ''
    form.remove_password = false
    form.has_password = r.password_protected
    errorMsg.value = ''
    showEdit.value = true
}

async function save() {
    if (editId.value === null) return
    saving.value = true
    errorMsg.value = ''
    try {
        const payload: Record<string, unknown> = {
            reading_name: form.reading_name.trim(),
            reading_notes: form.reading_notes,
            hide_user: form.hide_user,
        }
        if (form.remove_password) {
            payload.remove_password = true
        } else if (form.password) {
            payload.password = form.password
        }

        const res = await updateReading(editId.value, payload)
        if (res.ok) {
            toasts.success('Reading updated.')
            showEdit.value = false
            await load()
        } else {
            errorMsg.value = res.error ?? 'Update failed.'
        }
    } finally {
        saving.value = false
    }
}

async function removeReading(r: AccountReading) {
    const ok = await confirm({
        title: 'Delete reading',
        message: `Permanently delete "${summarize(r)}"? Anyone with its link will no longer be able to view it. This cannot be undone.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return

    deletingId.value = r.reading_id
    try {
        const res = await deleteReading(r.reading_id)
        if (res.ok) {
            toasts.success('Reading deleted.')
            readings.value = readings.value.filter(x => x.reading_id !== r.reading_id)
        } else {
            toasts.error(res.error ?? 'Could not delete the reading.')
        }
    } finally {
        deletingId.value = null
    }
}

onMounted(load)
</script>

<template>
    <section class="section">
        <div class="container">
            <router-link :to="{ name: 'dashboard' }" class="button is-small is-ghost mb-4">
                <span class="icon"><i class="fa-solid fa-arrow-left"></i></span>
                <span>Back to Dashboard</span>
            </router-link>

            <h1 class="title is-3">My Readings</h1>
            <p class="subtitle is-5">View your readings and manage their title, visibility, and password.</p>

            <p v-if="loading" class="has-text-grey">
                <span class="icon"><i class="fa-solid fa-spinner fa-spin"></i></span>
                <span>Loading your readings…</span>
            </p>

            <p v-else-if="readings.length === 0" class="has-text-grey">
                You haven't saved any readings yet. New draws and recreations you make while signed in appear here.
            </p>

            <div v-else class="table-container">
                <table class="table is-fullwidth is-hoverable is-striped">
                    <thead>
                        <tr>
                            <th>Reading</th>
                            <th>Date</th>
                            <th>Visibility</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in readings" :key="r.reading_id">
                            <td>{{ summarize(r) }}</td>
                            <td>{{ formatDateTime(r.reading_time) }}</td>
                            <td>
                                <span v-if="r.password_protected" class="tag is-warning is-light mr-1">
                                    <span class="icon is-small"><i class="fa-solid fa-lock"></i></span>
                                    <span>Password</span>
                                </span>
                                <span v-if="r.hide_user" class="tag is-info is-light">Name hidden</span>
                                <span v-if="!r.password_protected && !r.hide_user" class="has-text-grey">Public</span>
                            </td>
                            <td>
                                <div class="buttons are-small">
                                    <router-link class="button is-info" :to="{ name: 'reading', params: { id: r.reading_id } }">
                                        <span class="icon"><i class="fa-solid fa-eye"></i></span>
                                        <span>View</span>
                                    </router-link>
                                    <button class="button" @click="openEdit(r)">
                                        <span class="icon"><i class="fa-solid fa-pen"></i></span>
                                        <span>Edit</span>
                                    </button>
                                    <button
                                        class="button is-danger"
                                        :class="{ 'is-loading': deletingId === r.reading_id }"
                                        @click="removeReading(r)"
                                    >
                                        <span class="icon"><i class="fa-solid fa-trash"></i></span>
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <BaseModal :active="showEdit" title="Edit Reading" max-width="680px" @close="showEdit = false">
        <div class="notification is-danger is-light" v-if="errorMsg">{{ errorMsg }}</div>

        <div class="field">
            <label class="label" for="er-name">Reading Title</label>
            <input class="input" id="er-name" v-model="form.reading_name" maxlength="100" placeholder="Leave blank for the default title" />
        </div>

        <div class="field">
            <label class="label">Reading Notes <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span></label>
            <MarkdownEditor v-model="form.reading_notes" placeholder="Write a detailed interpretation… Markdown is supported." />
        </div>

        <div class="field">
            <label class="toggle-switch">
                <input type="checkbox" v-model="form.hide_user" />
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-state">Hide my display name on this reading</span>
            </label>
        </div>

        <div class="field" v-if="form.has_password">
            <label class="toggle-switch">
                <input type="checkbox" v-model="form.remove_password" />
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
                <span class="toggle-state">Remove the current password</span>
            </label>
        </div>

        <div class="field" v-if="!form.remove_password">
            <label class="label" for="er-password">
                {{ form.has_password ? 'Change Password' : 'Set Password' }}
                <span class="has-text-grey is-size-7 has-text-weight-normal">(optional)</span>
            </label>
            <input
                class="input"
                id="er-password"
                type="password"
                v-model="form.password"
                autocomplete="new-password"
                :placeholder="form.has_password ? 'Enter a new password' : 'Require a password to view'"
            />
            <p class="help">At least 4 characters. Leave blank to keep it unchanged.</p>
        </div>

        <template #footer>
            <button class="button" @click="showEdit = false">Cancel</button>
            <button class="button is-success" :class="{ 'is-loading': saving }" @click="save">
                <span class="icon"><i class="fa-solid fa-floppy-disk"></i></span>
                <span>Save</span>
            </button>
        </template>
    </BaseModal>
</template>
