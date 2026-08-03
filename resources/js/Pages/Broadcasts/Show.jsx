import { Link, router, usePage } from '@inertiajs/react';
import Layout, { EmptyState, PageHeader, StatusBadge, panelClass, secondaryButtonClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

// Machine reasons written by SendBroadcastRecipient, translated for the
// marketer reading this page. Anything not in this map is an unexpected
// failure and gets shown with its raw error text.
const KNOWN_REASONS = {
    'Person has no delivery destination.': {
        email: 'No email address on this profile',
        sms: 'No phone number on this profile',
        push: 'No push destination on this profile',
    },
    'Person is suppressed for this channel.': 'Unsubscribed or suppressed',
};

function reasonLabel(error, channel) {
    const known = KNOWN_REASONS[error];
    if (!known) return null;
    return typeof known === 'string' ? known : (known[channel] ?? Object.values(known)[0]);
}

export default function Show({ workspace, broadcast, counts, reasons, recipients, filters }) {
    const { errors } = usePage().props;
    const segments = [
        { key: 'sent', value: counts.sent, bar: 'bg-emerald-400/80' },
        { key: 'skipped', value: counts.skipped, bar: 'bg-amber-400/70' },
        { key: 'failed', value: counts.failed, bar: 'bg-rose-400/80' },
        { key: 'pending', value: counts.pending, bar: 'bg-slate-500/60' },
    ].filter((segment) => segment.value > 0);

    const retry = () => {
        const plural = counts.failed === 1 ? 'recipient' : 'recipients';
        if (!window.confirm(`Retry ${counts.failed} failed ${plural}? Skipped people are expected outcomes and won't be retried.`)) return;
        router.post(engagePath(`broadcasts/${broadcast.id}/retry-failed`), {}, { preserveScroll: true });
    };

    const filterChip = (value, label, count) => {
        const active = (filters.status ?? '') === value;
        return <Link key={label} href={engagePath(`broadcasts/${broadcast.id}`) + (value ? `?status=${value}` : '')} preserveScroll
            className={`rounded-full px-3 py-1 text-xs ${active ? 'bg-emerald-400 text-slate-950' : 'bg-white/5 text-slate-400 hover:bg-white/10'}`}>
            {label}{typeof count === 'number' ? ` · ${count}` : ''}
        </Link>;
    };

    return <Layout title={`Broadcast · ${broadcast.name}`} workspace={workspace}>
        <Link href={engagePath('broadcasts')} className="text-xs text-slate-400 hover:text-slate-200">← Back to broadcasts</Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
            <PageHeader eyebrow="Delivery report" title={broadcast.name} description={`${broadcast.segment?.name ?? 'Segment'} · ${broadcast.channel} · ${broadcast.template?.name ?? 'template'} · via ${broadcast.delivery_channel?.name ?? 'channel'}`} />
        </div>
        <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-400">
            <StatusBadge status={broadcast.status} />
            {broadcast.started_at && <span>Started {new Date(broadcast.started_at).toLocaleString()}</span>}
            {broadcast.completed_at && <span>Finished {new Date(broadcast.completed_at).toLocaleString()}</span>}
            <Link href={engagePath(`broadcasts/${broadcast.id}/edit`)} className="font-medium text-emerald-300 hover:text-emerald-200">View message content →</Link>
        </div>

        <section className={`${panelClass} mt-6`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-semibold">Outcome</h2>
                {counts.failed > 0 && broadcast.status === 'completed'
                    && <button onClick={retry} className={secondaryButtonClass}>Retry {counts.failed} failed</button>}
            </div>
            {errors?.broadcast && <p role="alert" className="mt-3 text-xs text-rose-300">{errors.broadcast}</p>}
            {counts.total === 0
                ? <div className="mt-4"><EmptyState title="No recipients" description="This broadcast has no recipient records." /></div>
                : <>
                    <div className="mt-5 flex h-3 w-full overflow-hidden rounded-full bg-white/5" role="img"
                        aria-label={`${counts.sent} sent, ${counts.skipped} skipped, ${counts.failed} failed${counts.pending ? `, ${counts.pending} pending` : ''} of ${counts.total}`}>
                        {segments.map((segment) => <div key={segment.key} className={segment.bar} style={{ width: `${(segment.value / counts.total) * 100}%` }} />)}
                    </div>
                    <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <Stat label="Sent" value={counts.sent} tone="text-emerald-300" />
                        <Stat label="Skipped" value={counts.skipped} tone="text-amber-200" hint="Expected — no address or unsubscribed" />
                        <Stat label="Failed" value={counts.failed} tone="text-rose-300" hint={counts.failed > 0 ? 'Something broke — see reasons below' : undefined} />
                        {counts.pending > 0
                            ? <Stat label="Still sending" value={counts.pending} tone="text-slate-300" />
                            : <Stat label="Total" value={counts.total} tone="text-slate-200" />}
                    </div>
                </>}
        </section>

        {reasons.length > 0 && <section className={`${panelClass} mt-6`}>
            <h2 className="font-semibold">Why people were skipped or failed</h2>
            <div className="mt-4 divide-y divide-white/[0.07]">
                {reasons.map((reason, index) => {
                    const label = reasonLabel(reason.error, broadcast.channel);
                    return <div key={index} className="flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex items-start gap-3">
                            <StatusBadge status={reason.status} />
                            <div>
                                <p className="text-sm text-slate-200">{label ?? 'Something went wrong during delivery'}</p>
                                {!label && reason.error && <p className="mt-1 max-w-xl break-words font-mono text-xs text-slate-400">{reason.error}</p>}
                            </div>
                        </div>
                        <span className="text-xs text-slate-400">{reason.total} {reason.total === 1 ? 'person' : 'people'}</span>
                    </div>;
                })}
            </div>
        </section>}

        <section className={`${panelClass} mt-6`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="font-semibold">Recipients</h2>
                <div className="flex flex-wrap gap-2">
                    {filterChip('', 'All', counts.total)}
                    {filterChip('sent', 'Sent', counts.sent)}
                    {filterChip('skipped', 'Skipped', counts.skipped)}
                    {filterChip('failed', 'Failed', counts.failed)}
                </div>
            </div>
            {recipients.data.length === 0
                ? <div className="mt-4"><EmptyState title="No recipients match" description="Try a different status filter." /></div>
                : <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr className="text-xs uppercase tracking-wider text-slate-400">
                                <th className="pb-3 pr-4 font-medium">Person</th>
                                <th className="pb-3 pr-4 font-medium">Status</th>
                                <th className="pb-3 pr-4 font-medium">Reason</th>
                                <th className="pb-3 font-medium">Sent</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.07]">
                            {recipients.data.map((recipient) => {
                                const label = recipient.error ? reasonLabel(recipient.error, broadcast.channel) : null;
                                return <tr key={recipient.id}>
                                    <td className="py-3 pr-4">
                                        {recipient.person
                                            ? <Link href={engagePath(`people/${recipient.person.id}`)} className="text-slate-200 hover:text-emerald-300">
                                                <span className="font-medium">{recipient.person.external_id}</span>
                                                <span className="ml-2 text-xs text-slate-400">{recipient.person.email ?? recipient.person.phone ?? ''}</span>
                                            </Link>
                                            : <span className="text-slate-400">Deleted person</span>}
                                    </td>
                                    <td className="py-3 pr-4"><StatusBadge status={recipient.status} /></td>
                                    <td className="max-w-md py-3 pr-4 text-xs text-slate-400">
                                        {recipient.error
                                            ? (label ?? <span className="break-words font-mono">{recipient.error}</span>)
                                            : '—'}
                                    </td>
                                    <td className="py-3 text-xs text-slate-400">{recipient.sent_at ? new Date(recipient.sent_at).toLocaleString() : '—'}</td>
                                </tr>;
                            })}
                        </tbody>
                    </table>
                </div>}
            {recipients.links.length > 3 && <div className="mt-5 flex flex-wrap gap-2 border-t border-white/[0.07] pt-5">
                {recipients.links.map((link, index) => link.url
                    ? <Link key={index} href={link.url} preserveScroll className={`rounded-lg px-3 py-1.5 text-xs ${link.active ? 'bg-emerald-400 text-slate-950' : 'bg-white/5 text-slate-400 hover:bg-white/10'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                    : <span key={index} className="rounded-lg px-3 py-1.5 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}
            </div>}
        </section>
    </Layout>;
}

function Stat({ label, value, tone, hint }) {
    return <div>
        <div className="text-xs uppercase tracking-wider text-slate-400">{label}</div>
        <div className={`mt-1 text-2xl font-semibold ${tone}`}>{value}</div>
        {hint && <div className="mt-1 text-xs text-slate-400">{hint}</div>}
    </div>;
}
