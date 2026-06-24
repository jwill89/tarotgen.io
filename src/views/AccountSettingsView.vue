<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAccount } from '@/composables/useAccount'
import { useUser } from '@/composables/useUser'
import { usePasskeys } from '@/composables/usePasskeys'
import { useConfirm } from '@/composables/useConfirm'
import { useToasts } from '@/composables/useToasts'

const router = useRouter()
const route = useRoute()
const { changeDisplayName, changePassword, deleteAccount } = useAccount()
const { currentUser, fetchMe } = useUser()
const { confirm } = useConfirm()
const toasts = useToasts()
const {
    passkeys,
    loading: passkeysLoading,
    isSupported: passkeySupported,
    listPasskeys,
    registerPasskey,
    renamePasskey,
    deletePasskey,
    togglePasswordLogin,
} = usePasskeys()

// Display name
const displayName = ref(currentUser.value?.display_name ?? '')
const nameSaving = ref(false)
const nameError = ref('')

async function saveName() {
    nameError.value = ''
    nameSaving.value = true
    try {
        const res = await changeDisplayName(displayName.value)
        if (res.ok) toasts.success('Display name updated.')
        else nameError.value = res.error ?? 'Could not update display name.'
    } finally {
        nameSaving.value = false
    }
}

// Password
const currentPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const pwSaving = ref(false)
const pwError = ref('')

async function savePassword() {
    pwError.value = ''
    if (newPassword.value !== confirmPassword.value) {
        pwError.value = 'New passwords do not match.'
        return
    }
    pwSaving.value = true
    try {
        const res = await changePassword(currentPassword.value, newPassword.value)
        if (res.ok) {
            toasts.success('Password changed.')
            currentPassword.value = ''
            newPassword.value = ''
            confirmPassword.value = ''
        } else {
            pwError.value = res.error ?? 'Could not change password.'
        }
    } finally {
        pwSaving.value = false
    }
}

// Delete account
const deletePassword = ref('')
const deleting = ref(false)
const deleteError = ref('')

async function removeAccount() {
    deleteError.value = ''
    if (!deletePassword.value) {
        deleteError.value = 'Enter your password to confirm.'
        return
    }
    const ok = await confirm({
        title: 'Delete your account',
        message: 'This permanently deletes your account and ALL of your saved readings — anyone with a link to them will no longer be able to view them. This cannot be undone. Are you sure?',
        confirmLabel: 'Delete my account',
        danger: true,
    })
    if (!ok) return

    deleting.value = true
    try {
        const res = await deleteAccount(deletePassword.value)
        if (res.ok) {
            toasts.success('Your account has been deleted.')
            router.push({ name: 'home' })
        } else {
            deleteError.value = res.error ?? 'Could not delete account.'
        }
    } finally {
        deleting.value = false
    }
}

// Google link/unlink
const unlinking = ref(false)
const googleError = ref('')

// Passkeys
const passkeyName = ref('')
const passkeyRegistering = ref(false)
const passkeyError = ref('')
const passkeyTogglingPw = ref(false)

onMounted(() => {
    // Handle OAuth redirect messages on this page.
    const oauthError = route.query.oauth_error
    const oauthSuccess = route.query.oauth_success
    if (typeof oauthError === 'string' && oauthError) {
        googleError.value = oauthError
        router.replace({ ...route, query: {} })
    }
    if (typeof oauthSuccess === 'string' && oauthSuccess) {
        toasts.success(oauthSuccess)
        router.replace({ ...route, query: {} })
        fetchMe()
    }

    // Load passkeys
    if (passkeySupported()) {
        listPasskeys()
    }
})

function linkGoogle() {
    window.location.href = '/api/auth/google?intent=link'
}

async function unlinkGoogle() {
    googleError.value = ''
    unlinking.value = true
    try {
        const res = await fetch('/api/auth/google/unlink', { method: 'POST' })
        const data = await res.json()
        if (res.ok) {
            toasts.success('Google account unlinked.')
            await fetchMe()
        } else {
            googleError.value = data.error ?? 'Could not unlink Google account.'
        }
    } catch {
        googleError.value = 'Network error. Please try again.'
    } finally {
        unlinking.value = false
    }
}

// ── Passkey management ──────────────────────────────────────────

async function addPasskey() {
    passkeyError.value = ''
    const name = passkeyName.value.trim() || 'My Passkey'
    passkeyRegistering.value = true
    try {
        const res = await registerPasskey(name)
        if (res.ok) {
            toasts.success('Passkey registered!')
            passkeyName.value = ''
        } else {
            passkeyError.value = res.error ?? 'Could not register passkey.'
        }
    } finally {
        passkeyRegistering.value = false
    }
}

async function removePasskey(id: number, name: string) {
    const ok = await confirm({
        title: 'Remove passkey',
        message: `Remove "${name}"? You won't be able to use it to sign in anymore.`,
        confirmLabel: 'Remove',
        danger: true,
    })
    if (!ok) return

    passkeyError.value = ''
    const res = await deletePasskey(id)
    if (res.ok) {
        toasts.success('Passkey removed.')
    } else {
        passkeyError.value = res.error ?? 'Could not remove passkey.'
    }
}

async function editPasskeyName(id: number, currentName: string) {
    const newName = prompt('Rename passkey:', currentName)
    if (!newName || newName.trim() === currentName) return
    const res = await renamePasskey(id, newName.trim())
    if (res.ok) toasts.success('Passkey renamed.')
    else passkeyError.value = res.error ?? 'Could not rename passkey.'
}

async function handleTogglePasswordLogin() {
    const nowDisabled = currentUser.value?.password_login_disabled ?? false
    const newState = !nowDisabled

    if (newState) {
        const ok = await confirm({
            title: 'Disable password login',
            message: 'You will only be able to sign in with a passkey or Google. Are you sure?',
            confirmLabel: 'Disable password login',
            danger: true,
        })
        if (!ok) return
    }

    passkeyTogglingPw.value = true
    passkeyError.value = ''
    try {
        const res = await togglePasswordLogin(newState)
        if (res.ok) {
            toasts.success(newState ? 'Password login disabled.' : 'Password login re-enabled.')
        } else {
            passkeyError.value = res.error ?? 'Could not update setting.'
        }
    } finally {
        passkeyTogglingPw.value = false
    }
}
</script>

<template>
    <section class="section">
        <div class="container">
            <div class="columns is-centered">
                <div class="column is-6-desktop is-8-tablet">
                    <router-link :to="{ name: 'dashboard' }" class="button is-small is-ghost mb-4">
                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['arrow-left']" /></span>
                        <span>Back to Dashboard</span>
                    </router-link>

                    <h1 class="title is-3">Account Settings</h1>

                    <!-- Display name -->
                    <div class="box">
                        <h2 class="title is-5">Display Name</h2>
                        <form @submit.prevent="saveName">
                            <div class="field">
                                <div class="control has-icons-left">
                                    <input class="input" type="text" v-model="displayName" maxlength="30" autocomplete="nickname" />
                                    <span class="icon is-small is-left"><FontAwesomeIcon :icon="byPrefixAndName.fas['user']" /></span>
                                </div>
                            </div>
                            <div class="notification is-danger is-light" v-if="nameError">{{ nameError }}</div>
                            <button class="button is-success" type="submit" :class="{ 'is-loading': nameSaving }">
                                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['floppy-disk']" /></span>
                                <span>Save Name</span>
                            </button>
                        </form>
                    </div>

                    <!-- Password -->
                    <div class="box">
                        <h2 class="title is-5">Change Password</h2>
                        <form @submit.prevent="savePassword">
                            <div class="field">
                                <label class="label" for="cur-pw">Current Password</label>
                                <input class="input" id="cur-pw" type="password" v-model="currentPassword" autocomplete="current-password" required />
                            </div>
                            <div class="field">
                                <label class="label" for="new-pw">New Password</label>
                                <input class="input" id="new-pw" type="password" v-model="newPassword" autocomplete="new-password" required />
                                <p class="help">At least 12 characters. A memorable passphrase works well.</p>
                            </div>
                            <div class="field">
                                <label class="label" for="conf-pw">Confirm New Password</label>
                                <input class="input" id="conf-pw" type="password" v-model="confirmPassword" autocomplete="new-password" required />
                            </div>
                            <div class="notification is-danger is-light" v-if="pwError">{{ pwError }}</div>
                            <button class="button is-success" type="submit" :class="{ 'is-loading': pwSaving }">
                                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['key']" /></span>
                                <span>Change Password</span>
                            </button>
                        </form>
                    </div>

                    <!-- Google Account -->
                    <div class="box">
                        <h2 class="title is-5">Google Account</h2>
                        <div class="notification is-danger is-light" v-if="googleError">{{ googleError }}</div>
                        <template v-if="currentUser?.google_linked">
                            <p class="mb-3">
                                <span class="icon has-text-success"><FontAwesomeIcon :icon="byPrefixAndName.fas['circle-check']" /></span>
                                Your Google account is linked. You can sign in with Google.
                            </p>
                            <button class="button is-warning" :class="{ 'is-loading': unlinking }" @click="unlinkGoogle">
                                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fab['google']" /></span>
                                <span>Unlink Google</span>
                            </button>
                        </template>
                        <template v-else>
                            <p class="mb-3">Link your Google account to enable one-click sign in.</p>
                            <button class="button is-info" @click="linkGoogle">
                                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fab['google']" /></span>
                                <span>Link Google Account</span>
                            </button>
                        </template>
                    </div>

                    <!-- Passkeys -->
                    <div class="box" v-if="passkeySupported()">
                        <h2 class="title is-5">Passkeys</h2>
                        <p class="mb-3">
                            Passkeys let you sign in with biometrics (fingerprint, face) or your device's screen lock instead of a password.
                        </p>

                        <div class="notification is-danger is-light" v-if="passkeyError">{{ passkeyError }}</div>

                        <!-- Existing passkeys -->
                        <div v-if="passkeys.length > 0" class="mb-4">
                            <div v-for="pk in passkeys" :key="pk.passkey_id" class="passkey-item">
                                <div class="passkey-info">
                                    <span class="icon has-text-info"><FontAwesomeIcon :icon="byPrefixAndName.fas['key']" /></span>
                                    <span class="passkey-name">{{ pk.name }}</span>
                                    <span class="is-size-7 has-text-grey ml-2">
                                        Added {{ new Date(pk.created_at).toLocaleDateString() }}
                                        <template v-if="pk.last_used_at"> · Last used {{ new Date(pk.last_used_at).toLocaleDateString() }}</template>
                                    </span>
                                </div>
                                <div class="passkey-actions">
                                    <button class="button is-small" title="Rename" @click="editPasskeyName(pk.passkey_id, pk.name)">
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['pen']" /></span>
                                    </button>
                                    <button class="button is-small is-danger is-light" title="Remove" @click="removePasskey(pk.passkey_id, pk.name)">
                                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']" /></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p v-else-if="!passkeysLoading" class="has-text-grey mb-4">No passkeys registered yet.</p>

                        <!-- Register new passkey -->
                        <div class="field has-addons">
                            <div class="control is-expanded">
                                <input
                                    class="input"
                                    type="text"
                                    v-model="passkeyName"
                                    placeholder="Passkey name (e.g. MacBook, Phone)"
                                    maxlength="50"
                                />
                            </div>
                            <div class="control">
                                <button
                                    class="button is-info"
                                    :class="{ 'is-loading': passkeyRegistering }"
                                    @click="addPasskey"
                                >
                                    <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
                                    <span>Add Passkey</span>
                                </button>
                            </div>
                        </div>

                        <!-- Password login toggle -->
                        <hr />
                        <div class="field">
                            <label class="label">Password Login</label>
                            <p class="mb-2 is-size-7 has-text-grey">
                                If you have at least one passkey, you can disable password login for extra security.
                                You can always re-enable it here. This does not affect Google sign-in.
                            </p>
                            <button
                                class="button"
                                :class="[
                                    currentUser?.password_login_disabled ? 'is-success' : 'is-warning',
                                    { 'is-loading': passkeyTogglingPw },
                                ]"
                                :disabled="!currentUser?.has_passkeys && !currentUser?.password_login_disabled"
                                @click="handleTogglePasswordLogin"
                            >
                                <span class="icon">
                                    <FontAwesomeIcon :icon="currentUser?.password_login_disabled ? byPrefixAndName.fas['lock-open'] : byPrefixAndName.fas['lock']" />
                                </span>
                                <span>{{ currentUser?.password_login_disabled ? 'Re-enable Password Login' : 'Disable Password Login' }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Danger zone -->
                    <div class="box danger-zone">
                        <h2 class="title is-5 has-text-danger">Delete Account</h2>
                        <p class="mb-3">
                            Permanently delete your account and <strong>all of your readings</strong>. This cannot be undone.
                        </p>
                        <div class="field">
                            <label class="label" for="del-pw">Confirm your password</label>
                            <input class="input" id="del-pw" type="password" v-model="deletePassword" autocomplete="current-password" placeholder="Your password" />
                        </div>
                        <div class="notification is-danger is-light" v-if="deleteError">{{ deleteError }}</div>
                        <button class="button is-danger" :class="{ 'is-loading': deleting }" @click="removeAccount">
                            <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['trash']" /></span>
                            <span>Delete My Account</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.danger-zone {
    border: 1px solid var(--myst-danger, #e5556e);
}
.passkey-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5rem 0;
    border-bottom: 1px solid hsl(0, 0%, 90%);
}
.passkey-item:last-child {
    border-bottom: none;
}
.passkey-info {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    flex-wrap: wrap;
}
.passkey-name {
    font-weight: 600;
}
.passkey-actions {
    display: flex;
    gap: 0.25rem;
}
</style>
