<script setup lang="ts">
import { byPrefixAndName } from '@/fontawesome'
import { watch, onBeforeUnmount } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Placeholder from '@tiptap/extension-placeholder'
import TurndownService from 'turndown'
import { marked } from 'marked'

const props = withDefaults(defineProps<{
    modelValue: string
    placeholder?: string
}>(), {
    placeholder: 'Write your notes…',
})

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const turndown = new TurndownService({
    headingStyle: 'atx',
    bulletListMarker: '-',
    emDelimiter: '_',
})

/** Convert markdown string → HTML for Tiptap to consume */
function mdToHtml(md: string): string {
    if (!md) return ''
    return marked.parse(md, { async: false }) as string
}

/** Convert Tiptap HTML → markdown string for the model */
function htmlToMd(html: string): string {
    if (!html || html === '<p></p>') return ''
    return turndown.turndown(html)
}

const editor = useEditor({
    content: mdToHtml(props.modelValue),
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3] },
        }),
        Link.configure({
            openOnClick: false,
            HTMLAttributes: { rel: 'noopener noreferrer nofollow' },
        }),
        Placeholder.configure({
            placeholder: props.placeholder,
        }),
    ],
    onUpdate({ editor }) {
        const md = htmlToMd(editor.getHTML())
        emit('update:modelValue', md)
    },
})

// Sync external model changes into the editor (e.g. form reset)
watch(() => props.modelValue, (newVal) => {
    if (!editor.value) return
    const currentMd = htmlToMd(editor.value.getHTML())
    if (newVal !== currentMd) {
        editor.value.commands.setContent(mdToHtml(newVal), false)
    }
})

onBeforeUnmount(() => {
    editor.value?.destroy()
})

function setLink() {
    const url = window.prompt('URL')
    if (!url) {
        editor.value?.chain().focus().unsetLink().run()
        return
    }
    editor.value?.chain().focus().setLink({ href: url }).run()
}
</script>

<template>
    <div class="wysiwyg-editor box" v-if="editor">
        <div class="wysiwyg-toolbar">
            <div class="buttons has-addons are-small mb-0">
                <button
                    type="button" class="button is-small" tabindex="-1" title="Bold"
                    :class="{ 'is-active': editor.isActive('bold') }"
                    @click="editor.chain().focus().toggleBold().run()"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['bold']" /></span>
                </button>
                <button
                    type="button" class="button is-small" tabindex="-1" title="Italic"
                    :class="{ 'is-active': editor.isActive('italic') }"
                    @click="editor.chain().focus().toggleItalic().run()"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['italic']" /></span>
                </button>
                <button
                    type="button" class="button is-small" tabindex="-1" title="Heading"
                    :class="{ 'is-active': editor.isActive('heading', { level: 2 }) }"
                    @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['heading']" /></span>
                </button>
                <button
                    type="button" class="button is-small" tabindex="-1" title="Quote"
                    :class="{ 'is-active': editor.isActive('blockquote') }"
                    @click="editor.chain().focus().toggleBlockquote().run()"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['quote-left']" /></span>
                </button>
                <button
                    type="button" class="button is-small" tabindex="-1" title="Bulleted list"
                    :class="{ 'is-active': editor.isActive('bulletList') }"
                    @click="editor.chain().focus().toggleBulletList().run()"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['list-ul']" /></span>
                </button>
                <button
                    type="button" class="button is-small" tabindex="-1" title="Numbered list"
                    :class="{ 'is-active': editor.isActive('orderedList') }"
                    @click="editor.chain().focus().toggleOrderedList().run()"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['list-ol']" /></span>
                </button>
                <button
                    type="button" class="button is-small" tabindex="-1" title="Inline code"
                    :class="{ 'is-active': editor.isActive('code') }"
                    @click="editor.chain().focus().toggleCode().run()"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['code']" /></span>
                </button>
                <button
                    type="button" class="button is-small" tabindex="-1" title="Link"
                    :class="{ 'is-active': editor.isActive('link') }"
                    @click="setLink"
                >
                    <span class="icon is-small"><FontAwesomeIcon :icon="byPrefixAndName.fas['link']" /></span>
                </button>
            </div>
        </div>

        <EditorContent :editor="editor" class="wysiwyg-content" />
    </div>
</template>

<style scoped>
.wysiwyg-editor {
    padding: 0;
    overflow: hidden;
    border: 1px solid var(--myst-border-strong, rgba(255, 255, 255, 0.18));
}

.wysiwyg-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.4rem;
    padding: 0.4rem 0.5rem;
    background: var(--myst-surface-3, rgba(255, 255, 255, 0.05));
    border-bottom: 1px solid var(--myst-border, rgba(255, 255, 255, 0.12));
}

.wysiwyg-toolbar .button.is-active {
    background-color: var(--bulma-link, hsl(229, 53%, 53%));
    border-color: transparent;
    color: #fff;
}

.wysiwyg-content {
    padding: 0.75rem 1rem;
    min-height: 10rem;
}

/* Tiptap editor area styling */
.wysiwyg-content :deep(.tiptap) {
    outline: none;
    min-height: 8rem;
    color: inherit;
    font-size: inherit;
    line-height: 1.6;
}

.wysiwyg-content :deep(.tiptap p) {
    margin-bottom: 0.75em;
}

.wysiwyg-content :deep(.tiptap h2) {
    font-size: 1.4em;
    font-weight: 700;
    margin-bottom: 0.5em;
}

.wysiwyg-content :deep(.tiptap h3) {
    font-size: 1.2em;
    font-weight: 600;
    margin-bottom: 0.5em;
}

.wysiwyg-content :deep(.tiptap ul),
.wysiwyg-content :deep(.tiptap ol) {
    padding-left: 1.5em;
    margin-bottom: 0.75em;
}

.wysiwyg-content :deep(.tiptap blockquote) {
    border-left: 3px solid var(--myst-border-strong, rgba(255, 255, 255, 0.25));
    padding-left: 1em;
    margin-left: 0;
    margin-bottom: 0.75em;
    opacity: 0.85;
}

.wysiwyg-content :deep(.tiptap code) {
    background: rgba(255, 255, 255, 0.08);
    border-radius: 3px;
    padding: 0.15em 0.35em;
    font-size: 0.9em;
}

.wysiwyg-content :deep(.tiptap a) {
    color: var(--bulma-link, hsl(229, 53%, 53%));
    text-decoration: underline;
}

/* Placeholder */
.wysiwyg-content :deep(.tiptap p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    float: left;
    color: rgba(255, 255, 255, 0.35);
    pointer-events: none;
    height: 0;
}
</style>
