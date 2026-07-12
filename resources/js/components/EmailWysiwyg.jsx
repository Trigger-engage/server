import Color from '@tiptap/extension-color';
import Highlight from '@tiptap/extension-highlight';
import Link from '@tiptap/extension-link';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import Underline from '@tiptap/extension-underline';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useEffect, useState } from 'react';

const EmailLink = Link.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            class: {
                default: null,
                parseHTML: (element) => element.getAttribute('class'),
                renderHTML: (attributes) => attributes.class ? { class: attributes.class } : {},
            },
        };
    },
});

const variables = [
    ['First name', '{{ person.first_name }}'],
    ['Full name', '{{ person.name }}'],
    ['Email', '{{ person.email }}'],
    ['Phone', '{{ person.phone }}'],
    ['Person ID', '{{ person.external_id }}'],
    ['Event name', '{{ event.name }}'],
];

export default function EmailWysiwyg({ value, onChange }) {
    const [mode, setMode] = useState('visual');
    const editor = useEditor({
        extensions: [
            StarterKit.configure({ link: false, underline: false }),
            EmailLink.configure({ openOnClick: false, autolink: true, defaultProtocol: 'https' }),
            Underline,
            TextStyle,
            Color,
            Highlight.configure({ multicolor: true }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
        ],
        content: value,
        editorProps: {
            attributes: {
                class: 'te-wysiwyg-content',
                'aria-label': 'Email body visual editor',
            },
        },
        onUpdate: ({ editor: instance }) => onChange(instance.getHTML()),
    });

    useEffect(() => {
        if (editor && mode === 'visual' && editor.getHTML() !== value) {
            editor.commands.setContent(value || '<p></p>', { emitUpdate: false });
        }
    }, [editor, mode, value]);

    const setLink = () => {
        const previous = editor.getAttributes('link').href ?? 'https://';
        const href = window.prompt('Link URL', previous);
        if (href === null) return;
        if (!href.trim()) editor.chain().focus().unsetLink().run();
        else editor.chain().focus().extendMarkRange('link').setLink({ href: href.trim() }).run();
    };

    const insertButton = () => {
        editor.chain().focus().insertContent('<p><a class="te-button" href="https://example.com">Button label</a></p>').run();
    };

    return <div className="mt-2 overflow-hidden rounded-xl border border-white/10 bg-[#0d1424] shadow-xl shadow-black/10">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-white/[0.08] bg-white/[0.025] px-3 py-2">
            <div className="flex items-center gap-1 rounded-lg bg-slate-950/60 p-1" role="tablist" aria-label="Editor mode">
                <ModeButton active={mode === 'visual'} onClick={() => setMode('visual')}>Visual</ModeButton>
                <ModeButton active={mode === 'html'} onClick={() => setMode('html')}>HTML</ModeButton>
            </div>
            <div className="flex items-center gap-2 text-[11px] text-slate-500"><span className="size-1.5 rounded-full bg-emerald-400" />Live preview enabled</div>
        </div>

        {mode === 'visual' ? <>
            <div className="flex flex-wrap items-center gap-1 border-b border-white/[0.08] px-3 py-2">
                <Tool label="Undo" disabled={!editor?.can().undo()} onClick={() => editor.chain().focus().undo().run()}>↶</Tool>
                <Tool label="Redo" disabled={!editor?.can().redo()} onClick={() => editor.chain().focus().redo().run()}>↷</Tool>
                <Divider />
                <select aria-label="Text style" className="h-8 rounded-md border border-white/10 bg-slate-900 px-2 text-xs text-slate-300 outline-none" value={editor?.isActive('heading', { level: 1 }) ? 'h1' : editor?.isActive('heading', { level: 2 }) ? 'h2' : 'p'} onChange={(event) => { const style = event.target.value; if (style === 'p') editor.chain().focus().setParagraph().run(); else editor.chain().focus().toggleHeading({ level: Number(style.slice(1)) }).run(); }}><option value="p">Paragraph</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option></select>
                <Tool label="Bold" active={editor?.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}><strong>B</strong></Tool>
                <Tool label="Italic" active={editor?.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}><em>I</em></Tool>
                <Tool label="Underline" active={editor?.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}><span className="underline">U</span></Tool>
                <Tool label="Strikethrough" active={editor?.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}><span className="line-through">S</span></Tool>
                <label title="Text color" className="grid h-8 w-8 cursor-pointer place-items-center rounded-md border border-white/10 text-xs font-bold text-slate-300 hover:bg-white/10">A<input aria-label="Text color" type="color" className="absolute size-0 opacity-0" value={editor?.getAttributes('textStyle').color ?? '#1B1D3E'} onChange={(event) => editor.chain().focus().setColor(event.target.value).run()} /></label>
                <Tool label="Highlight" active={editor?.isActive('highlight')} onClick={() => editor.chain().focus().toggleHighlight({ color: '#FFF1A8' }).run()}>▰</Tool>
                <Divider />
                <Tool label="Bulleted list" active={editor?.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}>•≡</Tool>
                <Tool label="Numbered list" active={editor?.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}>1.</Tool>
                <Tool label="Quote" active={editor?.isActive('blockquote')} onClick={() => editor.chain().focus().toggleBlockquote().run()}>❝</Tool>
                <Divider />
                <Tool label="Align left" active={editor?.isActive({ textAlign: 'left' })} onClick={() => editor.chain().focus().setTextAlign('left').run()}>≡</Tool>
                <Tool label="Align center" active={editor?.isActive({ textAlign: 'center' })} onClick={() => editor.chain().focus().setTextAlign('center').run()}>≡</Tool>
                <Tool label="Align right" active={editor?.isActive({ textAlign: 'right' })} onClick={() => editor.chain().focus().setTextAlign('right').run()}>≡</Tool>
                <Divider />
                <Tool label="Add link" active={editor?.isActive('link')} onClick={setLink}>↗</Tool>
                {editor?.isActive('link') && <Tool label="Remove link" onClick={() => editor.chain().focus().unsetLink().run()}>×↗</Tool>}
                <Tool label="Insert divider" onClick={() => editor.chain().focus().setHorizontalRule().run()}>―</Tool>
                <Tool label="Insert button" onClick={insertButton}>Button</Tool>
                <select aria-label="Insert variable" className="h-8 rounded-md border border-emerald-400/20 bg-emerald-400/5 px-2 text-xs font-medium text-emerald-300 outline-none" value="" onChange={(event) => { if (event.target.value) editor.chain().focus().insertContent(event.target.value).run(); event.target.value = ''; }}><option value="">＋ Variable</option>{variables.map(([label, token]) => <option key={token} value={token}>{label}</option>)}</select>
            </div>
            <div className="bg-[#f6f2ee] p-3 sm:p-5"><div className="mx-auto min-h-[360px] max-w-2xl rounded-lg bg-white shadow-sm ring-1 ring-black/5"><EditorContent editor={editor} /></div></div>
        </> : <div className="relative"><div className="border-b border-white/[0.06] px-4 py-2 font-mono text-[10px] uppercase tracking-wider text-slate-600">HTML and Liquid source</div><textarea aria-label="Email body HTML source" rows="20" className="block w-full resize-y bg-[#090f1c] px-4 py-4 font-mono text-xs leading-6 text-slate-300 outline-none" value={value} onChange={(event) => onChange(event.target.value)} spellCheck="false" /></div>}
    </div>;
}

function Tool({ label, active = false, disabled = false, onClick, children }) {
    return <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick} className={`grid h-8 min-w-8 place-items-center rounded-md border px-2 text-xs transition disabled:opacity-30 ${active ? 'border-emerald-400/30 bg-emerald-400/15 text-emerald-300' : 'border-transparent text-slate-400 hover:border-white/10 hover:bg-white/[0.06] hover:text-white'}`}>{children}</button>;
}

function ModeButton({ active, onClick, children }) {
    return <button type="button" role="tab" aria-selected={active} onClick={onClick} className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${active ? 'bg-white/10 text-white shadow-sm' : 'text-slate-500 hover:text-slate-300'}`}>{children}</button>;
}

function Divider() { return <span aria-hidden="true" className="mx-1 h-5 w-px bg-white/10" />; }
