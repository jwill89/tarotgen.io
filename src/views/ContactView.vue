<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useUser } from '@/composables/useUser'
import { useToasts } from '@/composables/useToasts'
import MarkdownEditor from '@/components/MarkdownEditor.vue'

const { currentUser, isLoggedIn } = useUser()
const { success: toastSuccess, error: toastError } = useToasts()

const name = ref('')
const email = ref('')
const message = ref('')
const submitting = ref(false)
const submitted = ref(false)

onMounted(() => {
    if (currentUser.value) {
        name.value = currentUser.value.display_name
        email.value = currentUser.value.email
    }
})

async function submit() {
    if (!name.value.trim() || !email.value.trim() || !message.value.trim()) {
        toastError('Please fill out all fields.')
        return
    }

    submitting.value = true

    try {
        const res = await fetch('/api/contact/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                name: name.value.trim(),
                email: email.value.trim(),
                message: message.value.trim(),
            }),
        })

        if (res.status === 429) {
            toastError('You have submitted too many messages recently. Please try again later.')
            return
        }

        if (!res.ok) {
            const body = await res.json().catch(() => ({})) as { error?: string }
            toastError(body.error || 'Failed to send your message. Please try again.')
            return
        }

        submitted.value = true
        toastSuccess('Your message has been sent!')
    } catch {
        toastError('Network error. Please check your connection and try again.')
    } finally {
        submitting.value = false
    }
}

function resetForm() {
    message.value = ''
    submitted.value = false
}
</script>

<template>
    <section class="section">
        <div class="container" style="max-width: 720px;">
            <h1 class="title is-3">Contact Us</h1>
            <p class="subtitle is-5">Have a question, suggestion, or just want to say hello? Send us a message.</p>

            <div v-if="submitted" class="notification is-success">
                <p class="mb-3">
                    <strong>Thanks for reaching out!</strong>
                    Your message has been received and we'll get back to you if a reply is needed.
                </p>
                <div class="buttons">
                    <button class="button is-link" @click="resetForm">
                        <span class="icon"><i class="fa-solid fa-paper-plane"></i></span>
                        <span>Send Another Message</span>
                    </button>
                    <router-link :to="{ name: 'home' }" class="button">Back to Home</router-link>
                </div>
            </div>

            <form v-else @submit.prevent="submit">
                <div class="field">
                    <label class="label">Name</label>
                    <div class="control has-icons-left">
                        <input
                            class="input"
                            type="text"
                            v-model="name"
                            placeholder="Your name"
                            :disabled="isLoggedIn"
                            required
                        />
                        <span class="icon is-small is-left"><i class="fa-solid fa-user"></i></span>
                    </div>
                </div>

                <div class="field">
                    <label class="label">Email</label>
                    <div class="control has-icons-left">
                        <input
                            class="input"
                            type="email"
                            v-model="email"
                            placeholder="your@email.com"
                            :disabled="isLoggedIn"
                            required
                        />
                        <span class="icon is-small is-left"><i class="fa-solid fa-envelope"></i></span>
                    </div>
                </div>

                <div class="field">
                    <label class="label">Message</label>
                    <MarkdownEditor
                        v-model="message"
                        placeholder="Write your message… Markdown is supported."
                        :rows="6"
                    />
                </div>

                <div class="field">
                    <div class="control">
                        <button
                            type="submit"
                            class="button is-primary"
                            :class="{ 'is-loading': submitting }"
                            :disabled="submitting"
                        >
                            <span class="icon"><i class="fa-solid fa-paper-plane"></i></span>
                            <span>Send Message</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </section>
</template>

