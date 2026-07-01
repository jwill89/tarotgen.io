<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, onMounted } from 'vue'
import { useAdminApi } from '@/composables/useApi'
import { endpoints } from '@/api/endpoints'
import { useConfirm } from '@/composables/useConfirm'
import { useToasts } from '@/composables/useToasts'
import { useDataTable } from '@/composables/useDataTable'
import SortableTh from '@/components/admin/SortableTh.vue'
import { formatDateTime } from '@/utils/datetime'
import type { User } from '@/types'

const api = useAdminApi()
const { confirm } = useConfirm()
const toasts = useToasts()

const users = ref<User[]>([])
const busyId = ref<number | null>(null)

const { search, sortKey, sortDir, rows: visibleUsers, toggleSort } = useDataTable(users, {
    searchText: u => `${u.email} ${u.display_name} ${u.user_id}`,
    sortAccessors: {
        user_id: u => u.user_id,
        email: u => u.email,
        display_name: u => u.display_name,
        is_active: u => (u.is_active ? 1 : 0),
        registered_at: u => u.registered_at,
        last_login_at: u => u.last_login_at ?? '',
    },
    initialSort: 'registered_at',
    initialDir: 'desc',
})

async function fetchUsers() {
    const data = await api.get<User[]>(endpoints.admin.users.list)
    if (data) users.value = data
}

async function activate(user: User) {
    busyId.value = user.user_id
    const result = await api.post(endpoints.admin.users.activate(user.user_id), {}, `${user.display_name} activated.`)
    busyId.value = null
    if (result) await fetchUsers()
}

async function resend(user: User) {
    busyId.value = user.user_id
    const result = await api.post<{ success: boolean; emailed: boolean; activation_link?: string }>(
        endpoints.admin.users.resendActivation(user.user_id),
        {},
    )
    busyId.value = null

    if (!result) return

    if (result.emailed) {
        toasts.success(`Activation email sent to ${user.email}.`)
    } else if (result.activation_link) {
        // Dev/no-SMTP: surface the link so it can be used directly.
        toasts.warning(`Email not configured. Activation link: ${result.activation_link}`, { duration: 0 })
    } else {
        toasts.success('Activation link regenerated.')
    }
}

async function toggleAdmin(user: User) {
    const makingAdmin = !user.is_admin
    const ok = await confirm({
        title: makingAdmin ? 'Grant admin' : 'Revoke admin',
        message: makingAdmin
            ? `Give ${user.display_name} (${user.email}) the admin flag?`
            : `Remove the admin flag from ${user.display_name} (${user.email})?`,
        confirmLabel: makingAdmin ? 'Make admin' : 'Revoke admin',
        danger: !makingAdmin,
    })
    if (!ok) return

    busyId.value = user.user_id
    const result = await api.patch<User>(
        endpoints.admin.users.byId(user.user_id),
        { is_admin: makingAdmin },
        makingAdmin ? `${user.display_name} is now an admin.` : `Admin removed from ${user.display_name}.`,
    )
    busyId.value = null
    if (result) await fetchUsers()
}

async function remove(user: User) {
    const ok = await confirm({
        title: 'Delete account',
        message: `Permanently delete ${user.display_name} (${user.email})? This also deletes ALL of their saved readings and cannot be undone.`,
        confirmLabel: 'Delete',
        danger: true,
    })
    if (!ok) return
    const result = await api.del(endpoints.admin.users.byId(user.user_id), 'Account deleted.')
    if (result) await fetchUsers()
}

onMounted(fetchUsers)
</script>

<template>
    <section class="section">
        <div class="container">
            <router-link :to="{ name: 'admin-dashboard' }" class="button is-small is-ghost mb-4">
                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
                <span>Back to Dashboard</span>
            </router-link>

            <div class="level">
                <div class="level-left">
                    <div>
                        <h1 class="title is-3">Manage Users</h1>
                        <p class="subtitle is-5">Activate, resend activation emails, or remove accounts.</p>
                    </div>
                </div>
            </div>

            <div class="field">
                <div class="control has-icons-left">
                    <input class="input" type="text" v-model="search" placeholder="Search by email, name, or ID..." />
                    <span class="icon is-small is-left"><FontAwesomeIcon :icon="byPrefixAndName.fas['magnifying-glass']" /></span>
                </div>
            </div>

            <div class="table-container">
                <table class="table is-fullwidth is-hoverable is-striped">
                    <thead>
                        <tr>
                            <SortableTh label="ID" sort-key="user_id" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Email" sort-key="email" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Display Name" sort-key="display_name" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Status" sort-key="is_active" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Registered" sort-key="registered_at" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <SortableTh label="Last Login" sort-key="last_login_at" :active-key="sortKey" :dir="sortDir" @sort="toggleSort" />
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in visibleUsers" :key="user.user_id">
                            <td>{{ user.user_id }}</td>
                            <td>{{ user.email }}</td>
                            <td>
                                {{ user.display_name }}
                                <span v-if="user.is_admin" class="icon has-text-link ml-1" title="Admin"><FontAwesomeIcon :icon="byPrefixAndName.fas['user-shield']" /></span>
                                <span v-if="user.google_linked" class="icon has-text-info ml-1" title="Google linked"><FontAwesomeIcon :icon="byPrefixAndName.fab['google']" /></span>
                                <span v-if="user.has_passkeys" class="icon has-text-success ml-1" title="Has passkey(s)"><FontAwesomeIcon :icon="byPrefixAndName.fas['key']" /></span>
                            </td>
                            <td>
                                <span v-if="user.is_active" class="tag is-success is-light">Active</span>
                                <span v-else class="tag is-warning is-light">Pending</span>
                            </td>
                            <td>{{ formatDateTime(user.registered_at) }}</td>
                            <td>{{ user.last_login_at ? formatDateTime(user.last_login_at) : '—' }}</td>
                            <td>
                                <div class="buttons are-small">
                                    <button
                                        v-if="!user.is_active"
                                        class="button is-success"
                                        :class="{ 'is-loading': busyId === user.user_id }"
                                        @click="activate(user)"
                                    >
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-check']" /></span>
                                        <span>Activate</span>
                                    </button>
                                    <button
                                        v-if="!user.is_active"
                                        class="button is-link"
                                        :class="{ 'is-loading': busyId === user.user_id }"
                                        @click="resend(user)"
                                    >
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['paper-plane']" /></span>
                                        <span>Resend Email</span>
                                    </button>
                                    <button
                                        class="button"
                                        :class="user.is_admin ? 'is-warning' : 'is-info'"
                                        :disabled="busyId === user.user_id"
                                        @click="toggleAdmin(user)"
                                    >
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['user-shield']" /></span>
                                        <span>{{ user.is_admin ? 'Revoke Admin' : 'Make Admin' }}</span>
                                    </button>
                                    <button class="button is-danger" @click="remove(user)">
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']" /></span>
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="has-text-grey" v-if="users.length === 0">No accounts yet.</p>
        </div>
    </section>
</template>
