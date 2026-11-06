import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout, { EmptyState, FieldError, PageHeader, buttonClass, inputClass, panelClass, secondaryButtonClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

const DRIVERS = {
    email: [['smtp', 'SMTP'], ['log', 'Log (development)']],
    sms: [['termii', 'Termii'], ['log', 'Log (development)']],
    push: [['onesignal', 'OneSignal'], ['expo', 'Expo'], ['log', 'Log (development)']],
};
const DRIVER_LABELS = { smtp: 'SMTP', termii: 'Termii', onesignal: 'OneSignal', expo: 'Expo', log: 'Log' };

// The credential fields each driver takes. Secrets are never sent back to the
// browser: on edit, a blank secret keeps the stored one.
const FIELDS = {
    smtp: [
        { key: 'host', label: 'Host', placeholder: 'smtp.zeptomail.com' },
        { key: 'port', label: 'Port', type: 'number' },
        { key: 'username', label: 'Username' },
        { key: 'password', label: 'Password', secret: true },
        { key: 'encryption', label: 'Encryption', options: [['tls', 'TLS'], ['ssl', 'SSL']] },
    ],
    termii: [
        { key: 'base_url', label: 'Base URL', placeholder: 'https://v3.api.termii.com' },
        { key: 'api_key', label: 'API key', secret: true },
        { key: 'secret_key', label: 'Secret key', secret: true },
        { key: 'sender_id', label: 'Sender ID' },
        { key: 'route', label: 'Route', options: [['dnd', 'DND (transactional)'], ['generic', 'Generic']] },
    ],
    onesignal: [
        { key: 'app_id', label: 'App ID' },
        { key: 'api_key', label: 'REST API key', secret: true },
        { key: 'webhook_token', label: 'Webhook token', secret: true, placeholder: 'At least 24 random characters' },
    ],
    expo: [
        { key: 'access_token', label: 'Access token', secret: true, placeholder: 'Only if your project enforces one' },
        { key: 'priority', label: 'Priority', options: [['high', 'High'], ['normal', 'Normal'], ['default', 'Default']] },
        { key: 'sound', label: 'Sound', placeholder: 'default' },
        { key: 'android_channel_id', label: 'Android channel ID', placeholder: 'Optional' },
    ],
    log: [],
};
const SECRET_LABELS = { password: 'Password', api_key: 'API key', secret_key: 'Secret key', webhook_token: 'Webhook token', access_token: 'Access token' };

const BLANK = { host: '', port: 587, username: '', password: '', encryption: 'tls', base_url: '', api_key: '', secret_key: '', sender_id: '', route: 'dnd', app_id: '', webhook_token: '', access_token: '', priority: 'high', sound: 'default', android_channel_id: '' };

export default function Index({ workspace, channels }) {
    const { errors } = usePage().props;
    const [editing, setEditing] = useState(null);

    return <Layout title="Channels" workspace={workspace}>
        <PageHeader eyebrow="Delivery infrastructure" title="Channels" description="Connect the providers used to deliver email, SMS, and push messages." />
        <CreateChannel />

        <section className={`${panelClass} mt-6`}>
            <h2 className="font-semibold">Connected channels</h2>
            {errors?.channel && <p role="alert" className="mt-3 rounded-lg bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{errors.channel}</p>}
            <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {channels.length === 0
                    ? <div className="md:col-span-2 xl:col-span-3"><EmptyState title="No channels connected" description="Use the form above to connect your first delivery provider." /></div>
                    : channels.map((channel) => editing === channel.id
                        ? <EditChannel key={channel.id} channel={channel} onDone={() => setEditing(null)} />
                        : <ChannelCard key={channel.id} channel={channel} onEdit={() => setEditing(channel.id)} />)}
            </div>
        </section>
    </Layout>;
}

function CreateChannel() {
    const form = useForm({ type: 'email', name: '', driver: 'smtp', is_default: true, ...BLANK });
    const [testing, setTesting] = useState(false);
    const submit = (event) => {
        event.preventDefault();
        form.post(engagePath('channels'), { preserveScroll: true, onSuccess: () => form.reset('name', 'host', 'username', 'password', 'api_key', 'secret_key', 'sender_id', 'app_id', 'webhook_token', 'access_token', 'android_channel_id') });
    };
    const test = () => {
        setTesting(true);
        router.post(engagePath('channels/test'), form.data, { preserveScroll: true, preserveState: true, onFinish: () => setTesting(false) });
    };

    return <section className={panelClass}>
        <h2 className="font-semibold">Connect a channel</h2>
        <form onSubmit={submit} className="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Field label="Type">
                <select className={inputClass} value={form.data.type} onChange={(event) => form.setData({ ...form.data, type: event.target.value, driver: DRIVERS[event.target.value][0][0] })}>
                    <option value="email">Email</option><option value="sms">SMS</option><option value="push">Push</option>
                </select>
            </Field>
            <Field label="Name"><input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Transactional email" /></Field>
            <Field label="Driver">
                <select className={inputClass} value={form.data.driver} onChange={(event) => form.setData('driver', event.target.value)}>
                    {DRIVERS[form.data.type].map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
            </Field>
            <DriverFields driver={form.data.driver} data={form.data} setData={form.setData} errors={form.errors} />
            <div className="md:col-span-2 xl:col-span-3">
                <label className="flex items-center gap-2 text-sm text-slate-400"><input type="checkbox" checked={form.data.is_default} onChange={(event) => form.setData('is_default', event.target.checked)} />Use as the default {form.data.type} channel</label>
                <FieldError message={form.errors.name || form.errors.driver} />
                <div className="mt-4 flex flex-wrap gap-3">
                    <button className={buttonClass} disabled={form.processing}>Connect channel</button>
                    <button type="button" onClick={test} className={secondaryButtonClass} disabled={testing || form.processing}>{testing ? 'Testing…' : 'Test connection'}</button>
                </div>
            </div>
        </form>
    </section>;
}

function ChannelCard({ channel, onEdit }) {
    const [testing, setTesting] = useState(false);
    const used = channel.usage.automations.length > 0 || channel.usage.broadcasts > 0;
    const summary = channelSummary(channel);

    const test = () => {
        setTesting(true);
        router.post(engagePath(`channels/${channel.id}/test`), {}, { preserveScroll: true, onFinish: () => setTesting(false) });
    };
    const remove = () => {
        if (window.confirm(`Delete “${channel.name}”? Its stored credentials are deleted too.`)) {
            router.delete(engagePath(`channels/${channel.id}`), { preserveScroll: true });
        }
    };

    return <article className="flex flex-col rounded-xl border border-white/[0.08] bg-slate-950/30 p-4">
        <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
                <h3 className="truncate font-medium" title={channel.name}>{channel.name}</h3>
                <p className="mt-1 text-xs uppercase tracking-wider text-slate-400">{channel.type} · {DRIVER_LABELS[channel.driver] ?? channel.driver}</p>
            </div>
            {channel.is_default && <span className="shrink-0 rounded-full bg-emerald-400/10 px-2 py-1 text-[11px] text-emerald-300">Default</span>}
        </div>

        {channel.driver === 'log' && <p className="mt-3 rounded-lg bg-amber-400/10 px-3 py-2 text-xs text-amber-200">In development: messages are written to the application log. No one receives them.</p>}
        {summary && <p className="mt-3 break-words text-xs text-slate-300">{summary}</p>}
        {channel.secrets.length > 0 && <p className="mt-1 text-xs text-slate-400">{channel.secrets.map((key) => SECRET_LABELS[key]).join(', ')} saved</p>}

        <DeliveryNote channel={channel} />

        <p className="mt-3 text-xs text-slate-400">
            {used
                ? <>Used by {[...channel.usage.automations.map((name) => `“${name}”`), channel.usage.broadcasts ? `${channel.usage.broadcasts} ${channel.usage.broadcasts === 1 ? 'broadcast' : 'broadcasts'}` : null].filter(Boolean).join(', ')}</>
                : 'Not used by any automation or broadcast yet'}
        </p>

        <div className="mt-4 flex flex-wrap gap-2 border-t border-white/[0.07] pt-4">
            <button type="button" className={secondaryButtonClass} onClick={test} disabled={testing}>{testing ? 'Testing…' : 'Test'}</button>
            <button type="button" className={secondaryButtonClass} onClick={onEdit}>Edit</button>
            {!channel.is_default && <button type="button" className="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-white/5" onClick={() => router.post(engagePath(`channels/${channel.id}/default`), {}, { preserveScroll: true })}>Make default</button>}
            <button type="button" onClick={remove} disabled={used} title={used ? 'Switch its automations and broadcasts to another channel first' : undefined}
                className="ml-auto rounded-lg px-3 py-2 text-sm text-rose-300 hover:bg-rose-400/10 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-transparent">Delete</button>
        </div>
    </article>;
}

function EditChannel({ channel, onDone }) {
    const form = useForm({ name: channel.name, driver: channel.driver, ...BLANK, ...channel.settings });
    const [testing, setTesting] = useState(false);
    const sameDriver = form.data.driver === channel.driver;
    const stored = sameDriver ? channel.secrets : [];

    const save = (event) => {
        event.preventDefault();
        form.put(engagePath(`channels/${channel.id}`), { preserveScroll: true, onSuccess: onDone });
    };
    const test = () => {
        setTesting(true);
        router.post(engagePath('channels/test'), { ...form.data, channel_id: channel.id }, { preserveScroll: true, preserveState: true, onFinish: () => setTesting(false) });
    };

    return <form onSubmit={save} className="rounded-xl border border-emerald-400/25 bg-slate-950/40 p-4 md:col-span-2 xl:col-span-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="font-medium">Edit {channel.name}</h3>
            <span className="text-xs uppercase tracking-wider text-slate-400">{channel.type} channel</span>
        </div>
        <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <Field label="Name"><input className={inputClass} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} /></Field>
            <Field label="Driver">
                <select className={inputClass} value={form.data.driver} onChange={(event) => form.setData('driver', event.target.value)}>
                    {DRIVERS[channel.type].map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
            </Field>
            <DriverFields driver={form.data.driver} data={form.data} setData={form.setData} errors={form.errors} storedSecrets={stored} />
        </div>
        {!sameDriver && <p className="mt-3 text-xs text-amber-200">Switching from {DRIVER_LABELS[channel.driver]} to {DRIVER_LABELS[form.data.driver]}. Every automation and broadcast on this channel sends through {DRIVER_LABELS[form.data.driver]} after you save.</p>}
        <FieldError message={form.errors.name || form.errors.driver} />
        <div className="mt-4 flex flex-wrap gap-3">
            <button className={buttonClass} disabled={form.processing}>{form.processing ? 'Saving…' : 'Save channel'}</button>
            <button type="button" className={secondaryButtonClass} onClick={test} disabled={testing}>{testing ? 'Testing…' : 'Test these settings'}</button>
            <button type="button" className="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-white/5" onClick={onDone}>Cancel</button>
        </div>
    </form>;
}

function DriverFields({ driver, data, setData, errors, storedSecrets = [] }) {
    return <>
        {FIELDS[driver].map((field) => <Field key={field.key} label={field.label}>
            {field.options
                ? <select className={inputClass} value={data[field.key] ?? ''} onChange={(event) => setData(field.key, event.target.value)}>
                    {field.options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
                : <input type={field.secret ? 'password' : field.type ?? 'text'} autoComplete={field.secret ? 'new-password' : 'off'} className={inputClass} value={data[field.key] ?? ''}
                    onChange={(event) => setData(field.key, event.target.value)}
                    placeholder={field.secret && storedSecrets.includes(field.key) ? 'Saved · leave blank to keep it' : field.placeholder} />}
            <FieldError message={errors[field.key]} />
        </Field>)}
        {driver === 'expo' && <p className="text-xs normal-case tracking-normal text-slate-400 md:col-span-2 xl:col-span-3">Expo is addressed by device token. Store each profile’s tokens on the <code className="text-xs">expo_push_tokens</code> attribute via the identify API. Trigger Engage fans a send out to every token it finds there.</p>}
    </>;
}

function DeliveryNote({ channel }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try { await navigator.clipboard.writeText(channel.webhook_url); setCopied(true); setTimeout(() => setCopied(false), 2000); } catch { setCopied(false); }
    };

    if (channel.webhook_url) {
        return <div className="mt-3 rounded-lg bg-white/[0.03] p-3 text-xs text-slate-400">
            <p>{channel.driver === 'termii'
                ? 'Set this as the delivery-report URL in Termii so delivered and failed come back:'
                : 'Add this URL to a OneSignal Event Stream, with the header Authorization: Bearer <your webhook token>:'}</p>
            <div className="mt-2 flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate text-slate-200" title={channel.webhook_url}>{channel.webhook_url}</code>
                <button type="button" onClick={copy} className="shrink-0 rounded-md bg-white/5 px-2 py-1 text-slate-200 hover:bg-white/10">{copied ? 'Copied' : 'Copy'}</button>
            </div>
        </div>;
    }
    if (channel.driver === 'smtp') return <p className="mt-3 text-xs text-slate-400">SMTP reports sent or failed only. It has no delivery receipts, so Delivered stays empty.</p>;
    if (channel.driver === 'expo') return <p className="mt-3 text-xs text-slate-400">Delivery receipts are fetched from Expo automatically.</p>;
    return null;
}

function channelSummary(channel) {
    const settings = channel.settings ?? {};
    switch (channel.driver) {
        case 'smtp': return settings.host ? `${settings.username ? `${settings.username} @ ` : ''}${settings.host}:${settings.port ?? 587} · ${String(settings.encryption ?? 'tls').toUpperCase()}` : 'No host set';
        case 'termii': return [settings.sender_id && `Sender ${settings.sender_id}`, settings.route && `${settings.route} route`, settings.base_url].filter(Boolean).join(' · ') || 'No sender set';
        case 'onesignal': return settings.app_id ? `App ${settings.app_id}` : 'No app ID set';
        case 'expo': return [`${settings.priority ?? 'high'} priority`, settings.android_channel_id && `Android channel ${settings.android_channel_id}`].filter(Boolean).join(' · ');
        default: return null;
    }
}

function Field({ label, children }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{label}{children}</label>;
}
