import { Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Layout, { FieldError, buttonClass, inputClass, panelClass, secondaryButtonClass } from '../../components/Layout';

const colorFields = [
    ['background_color', 'Page'], ['card_color', 'Card'], ['heading_color', 'Heading'],
    ['text_color', 'Body text'], ['muted_color', 'Muted text'], ['footer_color', 'Footer'],
    ['footer_divider_color', 'Footer divider'], ['accent_color', 'Accent'],
];

const socialFields = [
    ['instagram_url', 'Instagram'], ['youtube_url', 'YouTube'], ['facebook_url', 'Facebook'],
    ['tiktok_url', 'TikTok'], ['linkedin_url', 'LinkedIn'], ['x_url', 'X / Twitter'],
];

export default function EditTemplate({ workspace, template, preview, defaultSettings }) {
    const form = useForm({
        channel: template.channel,
        name: template.name,
        subject: template.subject ?? '',
        preheader: template.preheader ?? '',
        body: template.body,
        layout: template.layout ?? 'mytherapist',
        from_name: template.from_name ?? '',
        from_address: template.from_address ?? '',
        settings: { ...defaultSettings, ...(template.settings ?? {}) },
    });
    const [rendered, setRendered] = useState(preview);
    const [previewError, setPreviewError] = useState('');
    const [previewing, setPreviewing] = useState(false);
    const bodyEditor = useRef(null);
    const previewKey = useMemo(() => JSON.stringify(form.data), [form.data]);
    const isEmail = form.data.channel === 'email';
    const isBranded = isEmail && form.data.layout === 'mytherapist';

    const updateSetting = (key, value) => form.setData('settings', { ...form.data.settings, [key]: value });
    const insertBody = (snippet) => {
        const editor = bodyEditor.current;
        const start = editor?.selectionStart ?? form.data.body.length;
        const end = editor?.selectionEnd ?? start;
        form.setData('body', `${form.data.body.slice(0, start)}${snippet}${form.data.body.slice(end)}`);
        window.requestAnimationFrame(() => {
            editor?.focus();
            editor?.setSelectionRange(start + snippet.length, start + snippet.length);
        });
    };

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setPreviewing(true);
            try {
                const response = await fetch(`${window.location.origin}/app/templates/preview`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: previewKey,
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(Object.values(payload?.errors ?? {})[0]?.[0] ?? 'Preview could not be rendered.');
                setRendered(payload);
                setPreviewError('');
            } catch (error) {
                if (error.name !== 'AbortError') setPreviewError(error.message);
            } finally {
                if (!controller.signal.aborted) setPreviewing(false);
            }
        }, 350);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [previewKey]);

    const save = (event) => {
        event.preventDefault();
        form.put(`/app/templates/${template.id}`, { preserveScroll: true });
    };

    return <Layout title={`Template · ${template.name}`} workspace={workspace}>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><Link href="/app" className="text-sm text-slate-400 hover:text-white">← Back to workspace</Link><h1 className="mt-4 text-3xl font-bold tracking-tight">Customize {template.name}</h1><p className="mt-2 text-sm text-slate-400">Edit content and branding, then verify the exact email output before saving.</p></div>
            <span className="rounded-full bg-white/10 px-3 py-1 text-xs uppercase tracking-wider text-slate-300">{template.channel}</span>
        </div>

        <form onSubmit={save} className="mt-8 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(420px,0.9fr)]">
            <div className="space-y-6">
                <section className={panelClass}>
                    <h2 className="text-lg font-semibold">Content</h2><p className="mt-1 text-sm text-slate-500">Liquid variables such as {'{{ person.first_name }}'} and {'{{ event.plan }}'} are replaced when the automation sends.</p>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Internal name"><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>{isEmail && <Field label="Email subject"><input className={inputClass} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} /></Field>}</div>
                    {isEmail && <div className="mt-4"><Field label="Inbox preheader"><input className={inputClass} value={form.data.preheader} onChange={(e) => form.setData('preheader', e.target.value)} placeholder="A short summary shown beside the subject" /></Field></div>}
                    <div className="mt-4"><label htmlFor="template-body" className="block text-xs font-medium uppercase tracking-wider text-slate-400">{isEmail ? 'HTML + Liquid body' : 'Message body'}</label>{isEmail && <div className="mt-2 flex flex-wrap gap-2">{[['Heading', '<h1>Your heading</h1>\n'], ['Paragraph', '<p>Write your message.</p>\n'], ['Button', '<p><a class="te-button" href="https://example.com">Button label</a></p>\n'], ['Divider', '<hr style="border:0;border-top:1px solid #EFEAF2;margin:28px 0;" />\n']].map(([label, snippet]) => <button key={label} type="button" className="rounded-md border border-white/10 px-2.5 py-1.5 text-xs text-slate-400 hover:bg-white/10" onClick={() => insertBody(snippet)}>+ {label}</button>)}</div>}<textarea id="template-body" ref={bodyEditor} rows="14" className={`${inputClass} font-mono text-xs leading-6`} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} /></div>
                    <FieldError message={form.errors.name || form.errors.subject || form.errors.preheader || form.errors.body} />
                </section>

                {isEmail && <section className={panelClass}>
                    <div className="flex items-center justify-between gap-4"><div><h2 className="text-lg font-semibold">Email layout</h2><p className="mt-1 text-sm text-slate-500">Mytherapist.ng branding is the default for new and migrated email templates.</p></div><select className={`${inputClass} max-w-48`} value={form.data.layout} onChange={(e) => form.setData('layout', e.target.value)}><option value="mytherapist">Branded layout</option><option value="plain">Plain HTML</option></select></div>
                </section>}

                {isBranded && <>
                    <section className={panelClass}>
                        <div className="flex items-center justify-between gap-4"><div><h2 className="text-lg font-semibold">Brand</h2><p className="mt-1 text-sm text-slate-500">Logo, identity, destinations, and the navy-and-gold footer.</p></div><button type="button" className={secondaryButtonClass} onClick={() => form.setData('settings', { ...defaultSettings })}>Restore Mytherapist.ng</button></div>
                        <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Brand name"><input className={inputClass} value={form.data.settings.brand_name} onChange={(e) => updateSetting('brand_name', e.target.value)} /></Field><Field label="Brand suffix"><input className={inputClass} value={form.data.settings.brand_suffix} onChange={(e) => updateSetting('brand_suffix', e.target.value)} /></Field><Field label="Tagline"><input className={inputClass} value={form.data.settings.tagline} onChange={(e) => updateSetting('tagline', e.target.value)} /></Field><Field label="Support email"><input type="email" className={inputClass} value={form.data.settings.support_email} onChange={(e) => updateSetting('support_email', e.target.value)} /></Field><Field label="Logo URL"><input type="url" className={inputClass} value={form.data.settings.logo_url} onChange={(e) => updateSetting('logo_url', e.target.value)} /></Field><Field label="Website URL"><input type="url" className={inputClass} value={form.data.settings.website_url} onChange={(e) => updateSetting('website_url', e.target.value)} /></Field></div>
                    </section>

                    <section className={panelClass}>
                        <h2 className="text-lg font-semibold">Colors</h2><div className="mt-5 grid gap-3 sm:grid-cols-2">{colorFields.map(([key, label]) => <label key={key} className="flex items-center justify-between gap-3 rounded-lg border border-white/10 bg-slate-950/50 p-3 text-sm text-slate-300"><span>{label}<span className="ml-2 font-mono text-xs text-slate-500">{form.data.settings[key]}</span></span><input aria-label={`${label} color`} type="color" className="h-9 w-12 cursor-pointer rounded border-0 bg-transparent" value={form.data.settings[key]} onChange={(e) => updateSetting(key, e.target.value.toUpperCase())} /></label>)}</div>
                    </section>

                    <section className={panelClass}>
                        <h2 className="text-lg font-semibold">Footer and app links</h2>
                        <div className="mt-5 space-y-4"><Switch checked={form.data.settings.show_app_badges} onChange={(value) => updateSetting('show_app_badges', value)}>Show App Store and Google Play badges</Switch>{form.data.settings.show_app_badges && <div className="grid gap-4 sm:grid-cols-2"><Field label="Badge caption"><input className={inputClass} value={form.data.settings.badge_caption} onChange={(e) => updateSetting('badge_caption', e.target.value)} /></Field><div /><Field label="App Store URL"><input type="url" className={inputClass} value={form.data.settings.app_store_url} onChange={(e) => updateSetting('app_store_url', e.target.value)} /></Field><Field label="Google Play URL"><input type="url" className={inputClass} value={form.data.settings.play_store_url} onChange={(e) => updateSetting('play_store_url', e.target.value)} /></Field></div>}<Field label="Why the recipient received this"><textarea rows="2" className={inputClass} value={form.data.settings.footer_note} onChange={(e) => updateSetting('footer_note', e.target.value)} /></Field><Field label="Crisis support copy"><textarea rows="3" className={inputClass} value={form.data.settings.crisis_text} onChange={(e) => updateSetting('crisis_text', e.target.value)} /></Field><Field label="Company line"><input className={inputClass} value={form.data.settings.company_line} onChange={(e) => updateSetting('company_line', e.target.value)} /></Field></div>
                        <details className="mt-5 rounded-lg border border-white/10 p-4"><summary className="cursor-pointer text-sm font-medium text-slate-300">Social links</summary><div className="mt-4"><Switch checked={form.data.settings.show_social_links} onChange={(value) => updateSetting('show_social_links', value)}>Show social icons</Switch>{form.data.settings.show_social_links && <div className="mt-4 grid gap-3 sm:grid-cols-2">{socialFields.map(([key, label]) => <Field key={key} label={label}><input type="url" className={inputClass} value={form.data.settings[key]} onChange={(e) => updateSetting(key, e.target.value)} /></Field>)}</div>}</div></details>
                    </section>
                </>}

                {isEmail && <section className={panelClass}><h2 className="text-lg font-semibold">Sender override</h2><p className="mt-1 text-sm text-slate-500">Leave blank to use the delivery channel defaults.</p><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="From name"><input className={inputClass} value={form.data.from_name} onChange={(e) => form.setData('from_name', e.target.value)} /></Field><Field label="From email"><input type="email" className={inputClass} value={form.data.from_address} onChange={(e) => form.setData('from_address', e.target.value)} /></Field></div></section>}

                <FieldError message={Object.values(form.errors)[0] || previewError} />
                <div className="flex justify-end"><button className={buttonClass} disabled={form.processing || Boolean(previewError)}>{form.processing ? 'Saving…' : 'Save template'}</button></div>
            </div>

            <aside className="xl:sticky xl:top-6 xl:self-start">
                <section className={`${panelClass} overflow-hidden p-0`}><div className="flex items-center justify-between border-b border-white/10 px-4 py-3"><div><div className="text-xs font-semibold uppercase tracking-wider text-slate-500">Live preview</div><div className="mt-1 max-w-md truncate text-sm text-slate-300">{rendered.subject || '(No subject)'}</div></div><span className={`text-xs ${previewError ? 'text-rose-300' : previewing ? 'text-amber-300' : 'text-emerald-300'}`}>{previewError ? 'Invalid' : previewing ? 'Rendering…' : 'Up to date'}</span></div><iframe title="Email template preview" sandbox="" className="h-[760px] w-full bg-white" srcDoc={rendered.html} /></section>
                {rendered.warnings?.length > 0 && <div className="mt-3 rounded-lg border border-amber-400/20 bg-amber-400/5 p-3 text-xs text-amber-200">Preview values are missing for: {rendered.warnings.join(', ')}</div>}
            </aside>
        </form>
    </Layout>;
}

function Field({ label, children }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{label}{children}</label>;
}

function Switch({ checked, onChange, children }) {
    return <label className="flex items-center gap-3 text-sm text-slate-300"><input type="checkbox" className="size-4 accent-emerald-400" checked={Boolean(checked)} onChange={(e) => onChange(e.target.checked)} />{children}</label>;
}
