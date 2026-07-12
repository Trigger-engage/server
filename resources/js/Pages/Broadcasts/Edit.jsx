import { Link, router, useForm } from '@inertiajs/react';
import Layout, { FieldError, buttonClass, panelClass, secondaryButtonClass } from '../../components/Layout';
import MessageComposer from '../../components/MessageComposer';
import { engagePath } from '../../lib/engagePath';

const statusStyles = {
    completed: 'bg-emerald-400/10 text-emerald-300',
    draft: 'bg-slate-400/10 text-slate-300',
};

export default function EditBroadcast({ workspace, broadcast, preview, defaultSettings }) {
    const editable = broadcast.editable;
    const form = useForm({
        name: broadcast.name,
        channel: broadcast.channel,
        subject: broadcast.subject ?? '',
        preheader: broadcast.preheader ?? '',
        body: broadcast.body ?? '',
        layout: broadcast.layout ?? 'mytherapist',
        from_name: broadcast.from_name ?? '',
        from_address: broadcast.from_address ?? '',
        settings: broadcast.channel === 'email' ? { ...defaultSettings, ...(broadcast.settings ?? {}) } : broadcast.settings,
    });

    const save = (event) => {
        event.preventDefault();
        form.put(engagePath(`broadcasts/${broadcast.id}`), { preserveScroll: true });
    };

    const sendNow = () => {
        if (!window.confirm(`Save and send “${form.data.name}” to the ${broadcast.segment?.name ?? 'selected'} audience now?`)) return;
        form.put(engagePath(`broadcasts/${broadcast.id}`), {
            preserveScroll: true,
            onSuccess: () => router.post(engagePath(`broadcasts/${broadcast.id}/send`)),
        });
    };

    const audience = <section className={panelClass}>
        <div className="flex items-center justify-between gap-3"><h2 className="text-lg font-semibold">Audience &amp; delivery</h2><span className={`rounded-full px-2 py-0.5 text-[10px] uppercase tracking-wider ${statusStyles[broadcast.status] ?? 'bg-amber-400/10 text-amber-200'}`}>{broadcast.status}</span></div>
        <dl className="mt-4 grid gap-4 sm:grid-cols-3">
            <Detail label="Segment" value={broadcast.segment?.name ?? '—'} />
            <Detail label="Delivery channel" value={broadcast.delivery_channel ? `${broadcast.delivery_channel.name} · ${broadcast.delivery_channel.driver}` : '—'} />
            <Detail label="From template" value={broadcast.template?.name ?? '—'} />
        </dl>
        <p className="mt-4 text-xs text-slate-500">Content below started as a copy of the template. Edits here only affect this broadcast — the template stays untouched.</p>
    </section>;

    return <Layout title={`Broadcast · ${broadcast.name}`} workspace={workspace}>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><Link href={engagePath('broadcasts')} className="text-sm text-slate-400 hover:text-white">← Back to broadcasts</Link><h1 className="mt-4 text-3xl font-bold tracking-tight">Compose {broadcast.name}</h1><p className="mt-2 text-sm text-slate-400">{editable ? 'Edit the message for this send and preview the exact output before you send it.' : 'This broadcast has been sent. Its content is shown read-only.'}</p></div>
            <span className="rounded-full bg-white/10 px-3 py-1 text-xs uppercase tracking-wider text-slate-300">{broadcast.channel}</span>
        </div>

        <form onSubmit={save} className="mt-8">
            <MessageComposer
                channel={form.data.channel}
                name={form.data.name}
                onNameChange={editable ? (value) => form.setData('name', value) : undefined}
                nameLabel="Broadcast name"
                data={form.data}
                setField={form.setData}
                errors={form.errors}
                defaultSettings={defaultSettings}
                initialPreview={preview}
                disabled={!editable}
                leading={audience}
                footer={({ previewError }) => editable ? <>
                    <FieldError message={form.errors.broadcast || Object.values(form.errors)[0] || previewError} />
                    <div className="flex flex-wrap justify-end gap-3">
                        <button type="submit" className={secondaryButtonClass} disabled={form.processing || Boolean(previewError)}>{form.processing ? 'Saving…' : 'Save draft'}</button>
                        <button type="button" onClick={sendNow} className={buttonClass} disabled={form.processing || Boolean(previewError)}>Save &amp; send now</button>
                    </div>
                </> : <div className="rounded-lg border border-white/10 bg-white/[0.03] p-4 text-sm text-slate-400">This broadcast is {broadcast.status}. <Link href={engagePath('broadcasts')} className="text-emerald-300 hover:text-emerald-200">Return to broadcasts</Link>.</div>}
            />
        </form>
    </Layout>;
}

function Detail({ label, value }) {
    return <div><dt className="text-xs font-medium uppercase tracking-wider text-slate-500">{label}</dt><dd className="mt-1 truncate text-sm text-slate-200">{value}</dd></div>;
}
