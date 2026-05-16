<script setup>
import { ref, watch, onBeforeUnmount, nextTick } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import Mention from '@tiptap/extension-mention';

const props = defineProps({
    modelValue: { type: String, default: '' },
    members: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

const editor = useEditor({
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3] },
        }),
        Placeholder.configure({
            placeholder: 'Write your announcement here...',
        }),
        Mention.configure({
            HTMLAttributes: { class: 'mention-tag' },
            suggestion: {
                items: ({ query }) => {
                    return props.members
                        .filter(m => m.name.toLowerCase().includes(query.toLowerCase()))
                        .slice(0, 5);
                },
                render: () => {
                    let component;
                    let popup;

                    return {
                        onStart: (propsData) => {
                            popup = document.createElement('div');
                            popup.className = 'mention-popup';
                            document.body.appendChild(popup);
                            updatePopup(propsData);
                        },
                        onUpdate: (propsData) => {
                            updatePopup(propsData);
                        },
                        onKeyDown: (propsData) => {
                            if (propsData.event.key === 'Escape') {
                                destroyPopup();
                                return true;
                            }
                            if (propsData.event.key === 'ArrowDown') {
                                const items = popup?.querySelectorAll('.mention-item');
                                const active = popup?.querySelector('.mention-item.active');
                                if (items && active) {
                                    const idx = Array.from(items).indexOf(active);
                                    const next = items[(idx + 1) % items.length];
                                    active.classList.remove('active');
                                    next.classList.add('active');
                                }
                                return true;
                            }
                            if (propsData.event.key === 'ArrowUp') {
                                const items = popup?.querySelectorAll('.mention-item');
                                const active = popup?.querySelector('.mention-item.active');
                                if (items && active) {
                                    const idx = Array.from(items).indexOf(active);
                                    const prev = items[(idx - 1 + items.length) % items.length];
                                    active.classList.remove('active');
                                    prev.classList.add('active');
                                }
                                return true;
                            }
                            if (propsData.event.key === 'Enter' || propsData.event.key === 'Tab') {
                                const active = popup?.querySelector('.mention-item.active');
                                if (active) {
                                    active.click();
                                    return true;
                                }
                            }
                            return false;
                        },
                        onExit: () => {
                            destroyPopup();
                        },
                    };

                    function updatePopup(propsData) {
                        if (!popup) return;
                        const items = propsData.items;
                        if (!items.length) {
                            popup.innerHTML = '';
                            return;
                        }
                        popup.innerHTML = items.map((item, i) =>
                            `<div class="mention-item ${i === 0 ? 'active' : ''}" data-id="${item.id}" data-label="${item.name}">
                                <span class="mention-avatar">${item.name.charAt(0).toUpperCase()}</span>
                                <span class="mention-name">${item.name}</span>
                                <span class="mention-role">${item.designation || ''}</span>
                            </div>`
                        ).join('');
                        popup.querySelectorAll('.mention-item').forEach(el => {
                            el.addEventListener('click', () => {
                                propsData.command({ id: el.dataset.id, label: el.dataset.label });
                            });
                        });
                        const { clientRect } = propsData;
                        if (clientRect) {
                            const rect = clientRect();
                            popup.style.position = 'fixed';
                            popup.style.top = `${rect.bottom + 4}px`;
                            popup.style.left = `${rect.left}px`;
                            popup.style.zIndex = '9999';
                        }
                    }

                    function destroyPopup() {
                        if (popup) {
                            popup.remove();
                            popup = null;
                        }
                    }
                },
            },
        }),
    ],
    content: props.modelValue,
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML());
    },
});

const showSource = ref(false);
const sourceCode = ref('');

function toggleSource() {
    if (showSource.value) {
        editor.value.commands.setContent(sourceCode.value || '', false);
        emit('update:modelValue', sourceCode.value);
        showSource.value = false;
    } else {
        sourceCode.value = editor.value.getHTML();
        showSource.value = true;
    }
}

function onSourceInput(e) {
    sourceCode.value = e.target.value;
    emit('update:modelValue', sourceCode.value);
}

watch(() => props.modelValue, (val) => {
    if (showSource.value) {
        if (sourceCode.value !== val) sourceCode.value = val || '';
    } else if (editor.value && editor.value.getHTML() !== val) {
        editor.value.commands.setContent(val || '', false);
    }
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<template>
    <div class="announcement-editor">
        <!-- Toolbar -->
        <div v-if="editor" class="editor-toolbar">
            <button type="button" @click="editor.chain().focus().toggleBold().run()" :class="{ active: editor.isActive('bold') }" title="Bold">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 4h8a4 4 0 014 4 4 4 0 01-4 4H6z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6 12h9a4 4 0 014 4 4 4 0 01-4 4H6z"/></svg>
            </button>
            <button type="button" @click="editor.chain().focus().toggleItalic().run()" :class="{ active: editor.isActive('italic') }" title="Italic">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 4h4m-2 0v16m-4 0h8"/></svg>
            </button>
            <div class="toolbar-divider"></div>
            <button type="button" @click="editor.chain().focus().toggleBulletList().run()" :class="{ active: editor.isActive('bulletList') }" title="Bullet List">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
            </button>
            <button type="button" @click="editor.chain().focus().toggleOrderedList().run()" :class="{ active: editor.isActive('orderedList') }" title="Numbered List">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6h11M10 12h11M10 18h11M3 5l2 1V4M3 11h2l-2 2M3 17h1.5l.5-.5.5.5H6"/></svg>
            </button>
            <div class="toolbar-divider"></div>
            <button type="button" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()" :class="{ active: editor.isActive('heading', { level: 2 }) }" title="Heading">
                <span class="text-xs font-bold">H</span>
            </button>
            <button type="button" @click="editor.chain().focus().toggleBlockquote().run()" :class="{ active: editor.isActive('blockquote') }" title="Quote">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h4v4H3zm11 0h4v4h-4zM3 18h18M3 6h18"/></svg>
            </button>
            <button type="button" @click="editor.chain().focus().setHorizontalRule().run()" title="Divider">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M3 12h18"/></svg>
            </button>
            <div class="toolbar-divider"></div>
            <button type="button" @click="toggleSource" :class="{ active: showSource }" title="HTML Source">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5"/></svg>
            </button>
        </div>

        <!-- Editor content -->
        <EditorContent v-show="!showSource" :editor="editor" class="editor-content" />

        <!-- HTML Source editor -->
        <textarea v-show="showSource" :value="sourceCode" @input="onSourceInput" class="source-editor" placeholder="Paste or write HTML here..." spellcheck="false"></textarea>
    </div>
</template>

<style>
.announcement-editor {
    border: 1px solid rgb(51 65 85 / 0.5);
    border-radius: 0.5rem;
    overflow: hidden;
    background: rgb(15 23 42 / 0.5);
}

.editor-toolbar {
    display: flex;
    align-items: center;
    gap: 2px;
    padding: 6px 8px;
    background: rgb(30 41 59 / 0.6);
    border-bottom: 1px solid rgb(51 65 85 / 0.5);
}

.editor-toolbar button {
    padding: 6px;
    border-radius: 4px;
    color: rgb(148 163 184);
    transition: all 0.15s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.editor-toolbar button:hover {
    color: rgb(241 245 249);
    background: rgb(51 65 85 / 0.5);
}

.editor-toolbar button.active {
    color: rgb(132 204 22);
    background: rgb(132 204 22 / 0.1);
}

.toolbar-divider {
    width: 1px;
    height: 16px;
    background: rgb(51 65 85);
    margin: 0 4px;
}

.editor-content .tiptap {
    padding: 12px 14px;
    min-height: 150px;
    max-height: 300px;
    overflow-y: auto;
    outline: none;
    font-size: 0.875rem;
    line-height: 1.6;
    color: rgb(203 213 225);
}

.editor-content .tiptap p.is-editor-empty:first-child::before {
    content: attr(data-placeholder);
    float: left;
    color: rgb(100 116 139);
    pointer-events: none;
    height: 0;
}

.editor-content .tiptap p { margin-bottom: 0.5em; }
.editor-content .tiptap p:last-child { margin-bottom: 0; }
.editor-content .tiptap strong { color: rgb(241 245 249); font-weight: 600; }
.editor-content .tiptap em { font-style: italic; }
.editor-content .tiptap ul, .editor-content .tiptap ol { margin: 0.5em 0; padding-left: 1.5em; }
.editor-content .tiptap ul { list-style-type: disc; }
.editor-content .tiptap ol { list-style-type: decimal; }
.editor-content .tiptap li { margin-bottom: 0.25em; }
.editor-content .tiptap li p { margin-bottom: 0; }
.editor-content .tiptap blockquote { border-left: 3px solid rgb(71 85 105); padding-left: 0.75em; margin: 0.5em 0; color: rgb(148 163 184); }
.editor-content .tiptap hr { border: none; border-top: 1px solid rgb(51 65 85); margin: 0.75em 0; }
.editor-content .tiptap h2 { color: rgb(241 245 249); font-weight: 600; font-size: 1.1em; margin: 0.5em 0 0.25em; }
.editor-content .tiptap h3 { color: rgb(241 245 249); font-weight: 600; font-size: 1.05em; margin: 0.5em 0 0.25em; }
.editor-content .tiptap code { background: rgb(30 41 59); padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.9em; }

.editor-content .tiptap .mention-tag {
    color: rgb(132 204 22);
    font-weight: 500;
    background: rgb(132 204 22 / 0.1);
    padding: 1px 4px;
    border-radius: 3px;
}

/* Mention popup */
.mention-popup {
    background: rgb(30 41 59);
    border: 1px solid rgb(51 65 85 / 0.5);
    border-radius: 8px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.4);
    overflow: hidden;
    min-width: 200px;
}

.mention-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.1s;
}

.mention-item:hover, .mention-item.active {
    background: rgb(132 204 22 / 0.1);
}

.mention-item .mention-avatar {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: rgb(51 65 85);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 600;
    color: rgb(203 213 225);
    flex-shrink: 0;
}

.mention-item .mention-name {
    font-size: 13px;
    color: rgb(226 232 240);
    font-weight: 500;
}

.mention-item .mention-role {
    font-size: 11px;
    color: rgb(100 116 139);
    margin-left: auto;
}

.source-editor {
    width: 100%;
    min-height: 150px;
    max-height: 300px;
    padding: 12px 14px;
    background: rgb(15 23 42 / 0.5);
    border: none;
    outline: none;
    resize: vertical;
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: 0.8rem;
    line-height: 1.6;
    color: rgb(165 243 252);
    tab-size: 2;
}

.source-editor::placeholder {
    color: rgb(100 116 139);
}
</style>
