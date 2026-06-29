<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { ref, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import { useUser } from '@/composables/useUser'
import { useUserSpreads } from '@/composables/useUserSpreads'
import { useToasts } from '@/composables/useToasts'
import type { SpreadPosition } from '@/types'
import SpreadEditor from '@/components/admin/SpreadEditor.vue'

const router = useRouter()
const editorRef = useTemplateRef<InstanceType<typeof SpreadEditor>>('editorRef')
const { currentUser, isLoggedIn } = useUser()
const { createUserSpread } = useUserSpreads()
const toasts = useToasts()

const submitted = ref(false)
const saveMode = ref<'public' | 'personal'>('public')

async function submitSpread(payload: { name: string; description: string; card_count: number; positions: SpreadPosition[] }) {
    try {
        if (saveMode.value === 'personal') {
            const spread = await createUserSpread(payload)
            if (!spread) {
                editorRef.value?.setError('Failed to save the spread. Please try again.')
                return
            }
            toasts.success('Spread saved to your personal collection!')
            router.push({ name: 'account-spreads' })
            return
        }

        const res = await fetch('/api/spread/submit', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })

        if (res.status === 429) {
            editorRef.value?.setError('You have submitted too many spreads recently. Please try again later.')
            return
        }

        if (!res.ok) {
            editorRef.value?.setError('Failed to submit the spread. Please try again.')
            return
        }

        submitted.value = true
    } catch {
        editorRef.value?.setError('Failed to submit the spread. Please try again.')
    }
}

function submitAnother() {
    submitted.value = false
}

function goHome() {
    router.push({ name: 'home' })
}
</script>

<template>
    <section class="section">
        <div class="container">
            <h1 class="title is-3">Create a Spread</h1>
            <p class="subtitle is-5" v-if="isLoggedIn">
                Design a tarot spread and save it for personal use, or submit it for review
                to make it available to everyone on the site.
            </p>
            <p class="subtitle is-5" v-else>
                Design a tarot spread and submit it for review. Approved spreads become available
                to everyone on the site.
            </p>

            <!-- Success state -->
            <div v-if="submitted" class="notification is-success">
                <p class="mb-3">
                    <strong>Thanks for your submission!</strong>
                    Your spread has been sent to the queue and will appear on the site once an admin approves it.
                </p>
                <div class="buttons">
                    <button class="button is-link" @click="submitAnother">
                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['plus']" /></span>
                        <span>Create Another</span>
                    </button>
                    <button class="button" @click="goHome">Back to Home</button>
                </div>
            </div>

            <!-- Submission form -->
            <template v-else>
                <p class="mb-4">
                    <span class="icon-text">
                        <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['user']" /></span>
                        <span>
                            Submitting as <strong>{{ currentUser?.display_name ?? 'Guest' }}</strong>.
                            <template v-if="!currentUser">
                                <router-link :to="{ name: 'login' }">Log in</router-link> to be credited by your display name.
                            </template>
                        </span>
                    </span>
                </p>

                <div v-if="isLoggedIn" class="myst-callout mb-5">
                    <div class="field mb-0">
                        <label class="label">
                            <span class="icon-text">
                                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['bookmark']" /></span>
                                <span>Save as</span>
                            </span>
                        </label>
                        <div class="myst-segmented">
                            <button
                                type="button"
                                class="myst-segmented__btn"
                                :class="{ 'is-active': saveMode === 'public' }"
                                @click="saveMode = 'public'"
                            >
                                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['globe']" /></span>
                                <span>Public Spread</span>
                            </button>
                            <button
                                type="button"
                                class="myst-segmented__btn"
                                :class="{ 'is-active': saveMode === 'personal' }"
                                @click="saveMode = 'personal'"
                            >
                                <span class="icon"><FontAwesomeIcon :icon="byPrefixAndName.fas['lock']" /></span>
                                <span>Personal Spread</span>
                            </button>
                        </div>
                        <p class="help mt-3" v-if="saveMode === 'public'">Your spread will be submitted for admin review before it becomes available to everyone.</p>
                        <p class="help mt-3" v-else>Your spread will be saved privately to your account and only you can use it.</p>
                    </div>
                </div>

                <SpreadEditor
                    ref="editorRef"
                    :spread="null"
                    :save-label="saveMode === 'personal' ? 'Save Spread' : 'Submit Spread'"
                    @save="submitSpread"
                    @cancel="goHome"
                />
            </template>
        </div>
    </section>
</template>

