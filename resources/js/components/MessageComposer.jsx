import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { FieldError, inputClass, panelClass, secondaryButtonClass } from './Layout';
import EmailWysiwyg from './EmailWysiwyg';
import BrandControls, { brandOverrides } from './BrandControls';
import { engagePath } from '../lib/engagePath';

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

    const isPush = channel === 'push';
    // Extra push data is typed as JSON and stored as an object once it parses.
    const [dataText, setDataText] = useState(() => (settings.data && typeof settings.data === 'object' ? JSON.stringify(settings.data, null, 2) : ''));
    const [dataError, setDataError] = useState('');
    const updateDataText = (text) => {
        setDataText(text);
        if (text.trim() === '') { setDataError(''); updateSetting('data', null); return; }
        try {
            const parsed = JSON.parse(text);
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) { setDataError(''); updateSetting('data', parsed); } else { setDataError('Extra data must be a JSON object, like {"campaign": "welcome"}.'); }
        } catch { setDataError('Not valid JSON yet.'); }
    };

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
                <div className="flex items-center justify-between gap-4"><div><h2 className="text-lg font-semibold">Email layout</h2><p className="mt-1 text-sm text-slate-500">The branded layout uses your workspace brand. Plain HTML sends the body as written.</p></div><select className={`${inputClass} max-w-48`} value={data.layout ?? 'mytherapist'} onChange={(e) => setField('layout', e.target.value)}><option value="mytherapist">Branded layout</option><option value="plain">Plain HTML</option></select></div>
            </section>}

            {isBranded && <BrandControls settings={settings} updateSetting={updateSetting}
                description={(() => {
                    const differing = brandOverrides(settings, defaultSettings).length;
                    if (differing === 0) return 'Follows the workspace brand. A change here applies to this message only.';
                    return `${differing} brand ${differing === 1 ? 'setting differs' : 'settings differ'} from the workspace brand. Everything else follows it.`;
                })()}
                actions={<div className="flex flex-wrap gap-2"><Link href={engagePath('brand')} className="rounded-lg px-3 py-2 text-sm text-emerald-300 hover:bg-white/5">Workspace brand →</Link><button type="button" className={secondaryButtonClass} onClick={() => setField('settings', { ...settings, ...defaultSettings })}>Use workspace brand</button></div>} />}

            {isPush && <section className={panelClass}><h2 className="text-lg font-semibold">Where the tap goes</h2><p className="mt-1 text-sm text-slate-500">A deep link the app opens when the notification is tapped. Liquid works here too, so a journey can point at the person in the event: mytherapistng://book/{'{{ event.therapist_id }}'}. Leave blank to open the app on its home screen.</p><div className="mt-5 grid gap-4"><Field label="Deep link"><input className={inputClass} value={settings.url ?? ''} onChange={(e) => updateSetting('url', e.target.value)} placeholder="mytherapistng://therapists?openTio=1" /></Field><Field label="Extra data (JSON object)"><textarea rows="3" className={`${inputClass} font-mono text-xs leading-6`} value={dataText} onChange={(e) => updateDataText(e.target.value)} placeholder='{"campaign": "welcome"}' /></Field>{dataError && <p className="text-xs text-amber-200">{dataError}</p>}</div><FieldError message={errors['settings.url'] || errors['settings.data']} /></section>}

            {isEmail && <section className={panelClass}><h2 className="text-lg font-semibold">Sender override</h2><p className="mt-1 text-sm text-slate-500">Leave blank to use the delivery channel defaults. A reply-to sends replies somewhere a person reads, so the from address need not be a watched mailbox.</p><div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="From name"><input className={inputClass} value={data.from_name ?? ''} onChange={(e) => setField('from_name', e.target.value)} /></Field><Field label="From email"><input type="email" className={inputClass} value={data.from_address ?? ''} onChange={(e) => setField('from_address', e.target.value)} /></Field><Field label="Reply-to email"><input type="email" className={inputClass} value={settings.reply_to ?? ''} onChange={(e) => updateSetting('reply_to', e.target.value)} placeholder="support@mytherapist.ng" /></Field><Field label="Reply-to name"><input className={inputClass} value={settings.reply_to_name ?? ''} onChange={(e) => updateSetting('reply_to_name', e.target.value)} /></Field></div><FieldError message={errors['settings.reply_to'] || errors['settings.reply_to_name']} /></section>}

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
