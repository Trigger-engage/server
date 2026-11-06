import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout, { EmptyState, FieldError, PageHeader, buttonClass, inputClass, panelClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

export default function Index({ workspace, endpoint, embedded, keys }) {
    const { flash, errors } = usePage().props;
    const issued = flash?.issued;
    const clientKey = keys.find((key) => key.kind === 'client' && !key.revoked_at);

    return <Layout title="Connect your app" workspace={workspace}>
        <PageHeader eyebrow="Setup" title="Connect your app"
            description="Send people and events from your backend with a server key, and from a browser or mobile app with a client key." />

        {issued && <IssuedKey issued={issued} />}

        <section className={panelClass}>
            <h2 className="font-semibold">Your workspace</h2>
            <dl className="mt-4 grid gap-4 md:grid-cols-2">
                <Copyable label="Workspace ID" value={workspace.public_id} />
                <Copyable label="API endpoint" value={endpoint} />
            </dl>
        </section>

        <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(340px,.8fr)]">
            <section className={panelClass}>
                <h2 className="font-semibold">Keys</h2>
                {errors?.key && <p role="alert" className="mt-3 rounded-lg bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{errors.key}</p>}
                {keys.length === 0
                    ? <div className="mt-4"><EmptyState title="No keys yet" description="Create a server key for your backend, or a client key for an app." /></div>
                    : <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead><tr className="text-xs uppercase tracking-wider text-slate-400"><th className="pb-3 pr-4 font-medium">Name</th><th className="pb-3 pr-4 font-medium">Key</th><th className="pb-3 pr-4 font-medium">Last used</th><th className="pb-3 font-medium" /></tr></thead>
                            <tbody className="divide-y divide-white/[0.07]">
                                {keys.map((key) => <tr key={key.id} className={key.revoked_at ? 'opacity-50' : ''}>
                                    <td className="py-3 pr-4 align-top">
                                        <div className="font-medium text-slate-200">{key.name}</div>
                                        <span className={`mt-1 inline-block rounded-full px-2 py-0.5 text-[11px] ${key.kind === 'client' ? 'bg-sky-400/10 text-sky-200' : 'bg-violet-400/10 text-violet-200'}`}>{key.kind === 'client' ? 'Client · publishable' : 'Server · secret'}</span>
                                    </td>
                                    <td className="py-3 pr-4 align-top">
                                        <code className="break-all text-xs text-slate-300">{key.public_key ?? `${key.prefix}…`}</code>
                                        {key.allowed_origins && <div className="mt-1 text-xs text-slate-400">Origins: {key.allowed_origins.join(', ')}</div>}
                                        {key.allowed_events && <div className="mt-1 text-xs text-slate-400">Events: {key.allowed_events.join(', ')}</div>}
                                    </td>
                                    <td className="py-3 pr-4 align-top text-xs text-slate-400">{key.last_used_at ? new Date(key.last_used_at).toLocaleString() : 'Never'}</td>
                                    <td className="py-3 text-right align-top">
                                        {key.revoked_at
                                            ? <span className="text-xs text-slate-400">Revoked</span>
                                            : <button type="button" className="rounded-lg px-3 py-1.5 text-sm text-rose-300 hover:bg-rose-400/10"
                                                onClick={() => window.confirm(`Revoke “${key.name}”? Anything using it stops working immediately.`) && router.post(engagePath(`connect/keys/${key.id}/revoke`), {}, { preserveScroll: true })}>Revoke</button>}
                                    </td>
                                </tr>)}
                            </tbody>
                        </table>
                    </div>}
            </section>

            <CreateKey />
        </div>

        <section className={`${panelClass} mt-6`}>
            <h2 className="font-semibold">Send from your code</h2>
            <div className="mt-4 grid gap-6 lg:grid-cols-2">
                <div>
                    <h3 className="text-sm font-medium text-slate-200">Laravel backend</h3>
                    <p className="mt-1 text-xs text-slate-400">{embedded ? 'Running embedded, the SDK writes straight to the database. These are only needed for a separate server.' : 'Install trigger-engage/laravel and set:'}</p>
                    <Code>{`TRIGGER_ENGAGE_ENDPOINT=${endpoint.replace(/\/api\/v1$/, '')}\nTRIGGER_ENGAGE_WORKSPACE_ID=${workspace.public_id}\nTRIGGER_ENGAGE_API_KEY=te_…`}</Code>
                    <Code>{`TriggerEngage::identify('user-42', ['email' => $user->email]);\nTriggerEngage::event('appointment_booked', ['therapist' => 'T-9'], person: 'user-42');`}</Code>
                </div>
                <div>
                    <h3 className="text-sm font-medium text-slate-200">Browser or React Native app</h3>
                    <p className="mt-1 text-xs text-slate-400">Install <code>@trigger-engage/js</code>. Your server signs the person id with the client key's identity secret; the app passes that token to <code>identify</code>.</p>
                    <Code>{`import { TriggerEngage } from '@trigger-engage/js';\n\nconst engage = new TriggerEngage({\n  endpoint: '${endpoint}',\n  clientKey: '${clientKey?.public_key ?? 'pk_…'}',\n});\n\nengage.identify('user-42', { identityToken });\nengage.track('app_opened');`}</Code>
                    <Code>{embedded
                        ? `// On your server, in the logged-in user's resource:\nuse TriggerEngage\\Server\\Support\\ClientIdentity;\n'engage_identity' => ClientIdentity::token('user-'.$user->id),`
                        : `// On your server:\nhash_hmac('sha256', 'user-42', env('TRIGGER_ENGAGE_IDENTITY_SECRET'));`}</Code>
                </div>
            </div>
        </section>
    </Layout>;
}

function CreateKey() {
    const form = useForm({ kind: 'client', name: '', allowed_origins: '', allowed_events: '' });
    const submit = (event) => {
        event.preventDefault();
        form.post(engagePath('connect/keys'), { preserveScroll: true, onSuccess: () => form.reset('name', 'allowed_origins', 'allowed_events') });
    };

    return <section className={`${panelClass} self-start`}>
        <h2 className="font-semibold">New key</h2>
        <form onSubmit={submit} className="mt-4 space-y-4">
            <fieldset className="space-y-2 text-sm text-slate-200">
                <legend className="sr-only">Kind of key</legend>
                <label className="flex items-start gap-2"><input type="radio" className="mt-1" checked={form.data.kind === 'client'} onChange={() => form.setData('kind', 'client')} /><span><span className="font-medium">Client key</span><span className="block text-xs text-slate-400">For a browser or mobile app. Safe to ship: it can only send events for an anonymous device or a person your server vouched for.</span></span></label>
                <label className="flex items-start gap-2"><input type="radio" className="mt-1" checked={form.data.kind === 'server'} onChange={() => form.setData('kind', 'server')} /><span><span className="font-medium">Server key</span><span className="block text-xs text-slate-400">For your backend. Full API access: keep it secret.</span></span></label>
            </fieldset>
            <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Name
                <input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder={form.data.kind === 'client' ? 'iOS and Android app' : 'Production backend'} />
                <FieldError message={form.errors.name} />
            </label>
            {form.data.kind === 'client' && <>
                <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Allowed origins (optional)
                    <input className={inputClass} value={form.data.allowed_origins} onChange={(event) => form.setData('allowed_origins', event.target.value)} placeholder="https://app.example.com" />
                    <span className="mt-1 block normal-case tracking-normal text-slate-400">Browsers on other sites are refused. Mobile apps send no origin and aren't affected.</span>
                    <FieldError message={form.errors.allowed_origins} />
                </label>
                <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Allowed events (optional)
                    <input className={inputClass} value={form.data.allowed_events} onChange={(event) => form.setData('allowed_events', event.target.value)} placeholder="app_opened, screen_viewed" />
                    <span className="mt-1 block normal-case tracking-normal text-slate-400">Leave empty to allow any event name.</span>
                </label>
            </>}
            <button className={buttonClass} disabled={form.processing}>Create key</button>
        </form>
    </section>;
}

function IssuedKey({ issued }) {
    return <section role="status" className="mb-6 rounded-2xl border border-emerald-400/30 bg-emerald-400/[0.06] p-6">
        <h2 className="font-semibold text-emerald-200">“{issued.name}” is ready</h2>
        {issued.kind === 'server'
            ? <><p className="mt-1 text-sm text-slate-300">Copy the key now. It is shown once and only its hash is kept.</p><dl className="mt-4"><Copyable label="Server key" value={issued.key} /></dl></>
            : <><p className="mt-1 text-sm text-slate-300">The client key goes in your app. The identity secret goes on your server only, to sign person ids; it is shown once.</p>
                <dl className="mt-4 grid gap-4 md:grid-cols-2"><Copyable label="Client key" value={issued.key} /><Copyable label="Identity secret" value={issued.secret} /></dl></>}
    </section>;
}

function Copyable({ label, value }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try { await navigator.clipboard.writeText(value); setCopied(true); setTimeout(() => setCopied(false), 2000); } catch { setCopied(false); }
    };

    return <div>
        <dt className="text-xs uppercase tracking-wider text-slate-400">{label}</dt>
        <dd className="mt-1 flex items-center gap-2 rounded-lg border border-white/10 bg-slate-950/50 px-3 py-2">
            <code className="min-w-0 flex-1 break-all text-sm text-slate-200">{value}</code>
            <button type="button" onClick={copy} className="shrink-0 rounded-md bg-white/5 px-2 py-1 text-xs text-slate-200 hover:bg-white/10">{copied ? 'Copied' : 'Copy'}</button>
        </dd>
    </div>;
}

function Code({ children }) {
    return <pre className="mt-3 overflow-x-auto rounded-lg border border-white/10 bg-slate-950/60 p-3 text-xs leading-5 text-slate-200"><code>{children}</code></pre>;
}
