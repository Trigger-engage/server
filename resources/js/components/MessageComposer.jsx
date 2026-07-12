import { useEffect, useMemo, useState } from 'react';
import { FieldError, inputClass, panelClass, secondaryButtonClass } from './Layout';
import EmailWysiwyg from './EmailWysiwyg';
import { engagePath } from '../lib/engagePath';

const colorFields = [
    ['background_color', 'Page'], ['card_color', 'Card'], ['heading_color', 'Heading'],
    ['text_color', 'Body text'], ['muted_color', 'Muted text'], ['footer_color', 'Footer'],
    ['footer_divider_color', 'Footer divider'], ['accent_color', 'Accent'],
];

const socialFields = [
    ['instagram_url', 'Instagram'], ['youtube_url', 'YouTube'], ['facebook_url', 'Facebook'],
    ['tiktok_url', 'TikTok'], ['linkedin_url', 'LinkedIn'], ['x_url', 'X / Twitter'],
];

/**
 * Content authoring + exact live preview shared by the template editor and the
 * broadcast composer. The parent owns the persisted state and passes the message
 * fields down through `data`/`setField`; this component renders the editing UI,
 * the branded-email controls, and the debounced preview. `leading` and `footer`
 * are slotted into the editor column (name field, save/send buttons, etc.).
 */
export default function MessageComposer({
    channel,
    name,
    onNameChange,
    nameLabel = 'Internal name',
    data,
    setField,
    errors = {},
    defaultSettings,
    initialPreview,
    disabled = false,
    leading = null,
    footer = null,
}) {
    const [rendered, setRendered] = useState(initialPreview);
    const [previewError, setPreviewError] = useState('');
    const [previewing, setPreviewing] = useState(false);

    const isEmail = channel === 'email';
    const isBranded = isEmail && data.layout === 'mytherapist';
    const settings = data.settings ?? {};

    const updateSetting = (key, value) => setField('settings', { ...settings, [key]: value });

    const previewPayload = useMemo(() => JSON.stringify({
        channel,
        name: name || 'Untitled message',
        subject: data.subject ?? '',
        preheader: data.preheader ?? '',
        body: data.body ?? '',
        layout: data.layout ?? 'mytherapist',
        from_name: data.from_name ?? '',
        from_address: data.from_address ?? '',
        settings,
    }), [channel, name, data.subject, data.preheader, data.body, data.layout, data.from_name, data.from_address, settings]);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setPreviewing(true);
            try {
                const response = await fetch(`${window.location.origin}${engagePath('templates/preview')}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: previewPayload,
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
    }, [previewPayload]);

    return <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(420px,0.9fr)]">
        <fieldset disabled={disabled} className="space-y-6 disabled:opacity-70">
            {leading}

            <section className={panelClass}>
                <h2 className="text-lg font-semibold">Content</h2><p className="mt-1 text-sm text-slate-500">Liquid variables such as {'{{ person.first_name }}'} and {'{{ event.plan }}'} are replaced when the message sends.</p>
                {(onNameChange || isEmail) && <div className="mt-5 grid gap-4 sm:grid-cols-2">{onNameChange && <Field label={nameLabel}><input className={inputClass} value={name ?? ''} onChange={(e) => onNameChange(e.target.value)} /></Field>}{isEmail && <Field label="Email subject"><input className={inputClass} value={data.subject ?? ''} onChange={(e) => setField('subject', e.target.value)} /></Field>}</div>}
                {isEmail && <div className="mt-4"><Field label="Inbox preheader"><input className={inputClass} value={data.preheader ?? ''} onChange={(e) => setField('preheader', e.target.value)} placeholder="A short summary shown beside the subject" /></Field></div>}
                <div className="mt-4"><div className="block text-xs font-medium uppercase tracking-wider text-slate-400">{isEmail ? 'Email body' : 'Message body'}</div>{isEmail ? <EmailWysiwyg value={data.body ?? ''} onChange={(body) => setField('body', body)} /> : <textarea rows="14" aria-label="Message body" className={`${inputClass} font-mono text-xs leading-6`} value={data.body ?? ''} onChange={(e) => setField('body', e.target.value)} />}</div>
                <FieldError message={errors.name || errors.subject || errors.preheader || errors.body} />
            </section>

            {isEmail && <section className={panelClass}>
                <div className="flex items-center justify-between gap-4"><div><h2 className="text-lg font-semibold">Email layout</h2><p className="mt-1 text-sm text-slate-500">Mytherapist.ng branding is the default for branded emails.</p></div><select className={`${inputClass} max-w-48`} value={data.layout ?? 'mytherapist'} onChange={(e) => setField('layout', e.target.value)}><option value="mytherapist">Branded layout</option><option value="plain">Plain HTML</option></select></div>
            </section>}

            {isBranded && <>
                <section className={panelClass}>
                    <div className="flex items-center justify-between gap-4"><div><h2 className="text-lg font-semibold">Brand</h2><p className="mt-1 text-sm text-slate-500">Logo, identity, destinations, and the navy-and-gold footer.</p></div><button type="button" className={secondaryButtonClass} onClick={() => setField('settings', { ...defaultSettings })}>Restore Mytherapist.ng</button></div>
                    <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Brand name"><input className={inputClass} value={settings.brand_name ?? ''} onChange={(e) => updateSetting('brand_name', e.target.value)} /></Field><Field label="Brand suffix"><input className={inputClass} value={settings.brand_suffix ?? ''} onChange={(e) => updateSetting('brand_suffix', e.target.value)} /></Field><Field label="Tagline"><input className={inputClass} value={settings.tagline ?? ''} onChange={(e) => updateSetting('tagline', e.target.value)} /></Field><Field label="Support email"><input type="email" className={inputClass} value={settings.support_email ?? ''} onChange={(e) => updateSetting('support_email', e.target.value)} /></Field><Field label="Logo URL"><input type="url" className={inputClass} value={settings.logo_url ?? ''} onChange={(e) => updateSetting('logo_url', e.target.value)} /></Field><Field label="Website URL"><input type="url" className={inputClass} value={settings.website_url ?? ''} onChange={(e) => updateSetting('website_url', e.target.value)} /></Field></div>
                </section>

                <section className={panelClass}>
                    <h2 className="text-lg font-semibold">Colors</h2><div className="mt-5 grid gap-3 sm:grid-cols-2">{colorFields.map(([key, label]) => <label key={key} className="flex items-center justify-between gap-3 rounded-lg border border-white/10 bg-slate-950/50 p-3 text-sm text-slate-300"><span>{label}<span className="ml-2 font-mono text-xs text-slate-500">{settings[key]}</span></span><input aria-label={`${label} color`} type="color" className="h-9 w-12 cursor-pointer rounded border-0 bg-transparent" value={settings[key] ?? '#000000'} onChange={(e) => updateSetting(key, e.target.value.toUpperCase())} /></label>)}</div>
                </section>

                <section className={panelClass}>
                    <h2 className="text-lg font-semibold">Footer and app links</h2>
                    <div className="mt-5 space-y-4"><Switch checked={settings.show_app_badges} onChange={(value) => updateSetting('show_app_badges', value)}>Show App Store and Google Play badges</Switch>{settings.show_app_badges && <div className="grid gap-4 sm:grid-cols-2"><Field label="Badge caption"><input className={inputClass} value={settings.badge_caption ?? ''} onChange={(e) => updateSetting('badge_caption', e.target.value)} /></Field><div /><Field label="App Store URL"><input type="url" className={inputClass} value={settings.app_store_url ?? ''} onChange={(e) => updateSetting('app_store_url', e.target.value)} /></Field><Field label="Google Play URL"><input type="url" className={inputClass} value={settings.play_store_url ?? ''} onChange={(e) => updateSetting('play_store_url', e.target.value)} /></Field></div>}<Field label="Why the recipient received this"><textarea rows="2" className={inputClass} value={settings.footer_note ?? ''} onChange={(e) => updateSetting('footer_note', e.target.value)} /></Field><Field label="Crisis support copy"><textarea rows="3" className={inputClass} value={settings.crisis_text ?? ''} onChange={(e) => updateSetting('crisis_text', e.target.value)} /></Field><Field label="Company line"><input className={inputClass} value={settings.company_line ?? ''} onChange={(e) => updateSetting('company_line', e.target.value)} /></Field></div>
                    <details className="mt-5 rounded-lg border border-white/10 p-4"><summary className="cursor-pointer text-sm font-medium text-slate-300">Social links</summary><div className="mt-4"><Switch checked={settings.show_social_links} onChange={(value) => updateSetting('show_social_links', value)}>Show social icons</Switch>{settings.show_social_links && <div className="mt-4 grid gap-3 sm:grid-cols-2">{socialFields.map(([key, label]) => <Field key={key} label={label}><input type="url" className={inputClass} value={settings[key] ?? ''} onChange={(e) => updateSetting(key, e.target.value)} /></Field>)}</div>}</div></details>
                </section>
            </>}

            {isEmail && <section className={panelClass}><h2 className="text-lg font-semibold">Sender override</h2><p className="mt-1 text-sm text-slate-500">Leave blank to use the delivery channel defaults.</p><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="From name"><input className={inputClass} value={data.from_name ?? ''} onChange={(e) => setField('from_name', e.target.value)} /></Field><Field label="From email"><input type="email" className={inputClass} value={data.from_address ?? ''} onChange={(e) => setField('from_address', e.target.value)} /></Field></div></section>}

            {typeof footer === 'function' ? footer({ previewError, previewing }) : footer}
        </fieldset>

        <aside className="xl:sticky xl:top-6 xl:self-start">
            <section className={`${panelClass} overflow-hidden p-0`}><div className="flex items-center justify-between border-b border-white/10 px-4 py-3"><div><div className="text-xs font-semibold uppercase tracking-wider text-slate-500">Live preview</div><div className="mt-1 max-w-md truncate text-sm text-slate-300">{rendered.subject || '(No subject)'}</div></div><span className={`text-xs ${previewError ? 'text-rose-300' : previewing ? 'text-amber-300' : 'text-emerald-300'}`}>{previewError ? 'Invalid' : previewing ? 'Rendering…' : 'Up to date'}</span></div><iframe title="Message preview" sandbox="" className="h-[760px] w-full bg-white" srcDoc={rendered.html} /></section>
            {rendered.warnings?.length > 0 && <div className="mt-3 rounded-lg border border-amber-400/20 bg-amber-400/5 p-3 text-xs text-amber-200">Preview values are missing for: {rendered.warnings.join(', ')}</div>}
        </aside>
    </div>;
}

function Field({ label, children }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{label}{children}</label>;
}

function Switch({ checked, onChange, children }) {
    return <label className="flex items-center gap-3 text-sm text-slate-300"><input type="checkbox" className="size-4 accent-emerald-400" checked={Boolean(checked)} onChange={(e) => onChange(e.target.checked)} />{children}</label>;
}
