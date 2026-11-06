import { Link } from '@inertiajs/react';
import Layout, { StatusBadge, panelClass } from '../../components/Layout';
import MessageSource from '../../components/MessageSource';
import { engagePath } from '../../lib/engagePath';
import { channelLabel, previewDocument } from '../../lib/messages';

export default function Show({ workspace, message }) {
    const title = message.subject || `${channelLabel(message.channel)} to ${message.person?.external_id ?? message.to_address}`;
    const timeline = [
        ['Created', message.created_at],
        ['Sent', message.sent_at],
        ['Delivered', message.delivered_at],
        ['Opened', message.opened_at],
        ['Clicked', message.clicked_at],
        ['Bounced', message.bounced_at],
    ];

    return <Layout title={`Message · ${title}`} workspace={workspace}>
        <Link href={engagePath(message.person ? `messages?person=${message.person.id}` : 'messages')} className="text-xs text-slate-400 hover:text-slate-200">
            ← {message.person ? `All messages to ${message.person.external_id}` : 'Back to messages'}
        </Link>
        <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-400">{channelLabel(message.channel)} message</p>
                <h1 className="mt-1 text-2xl font-bold tracking-tight text-white sm:text-3xl">{title}</h1>
                <p className="mt-2 text-sm text-slate-400">To {message.to_address}</p>
            </div>
            <StatusBadge status={message.status} />
        </div>

        {message.error && <div role="alert" className="mt-6 rounded-xl border border-rose-400/25 bg-rose-400/8 px-4 py-3">
            <p className="text-sm font-medium text-rose-200">The provider or the send step reported an error</p>
            <p className="mt-1 break-words font-mono text-xs text-rose-200/80">{message.error}</p>
        </div>}

        <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(300px,.7fr)]">
            <section className={`${panelClass} overflow-hidden p-0`}>
                <div className="border-b border-white/10 px-5 py-3">
                    <div className="text-xs font-semibold uppercase tracking-wider text-slate-400">What was sent</div>
                    {message.subject && <div className="mt-1 text-sm text-slate-200">{message.subject}</div>}
                </div>
                {!message.body
                    ? <p className="px-5 py-10 text-center text-sm text-slate-400">No content was stored for this message.</p>
                    : message.channel === 'email'
                        ? <iframe title="Sent email" sandbox="" className="h-[720px] w-full bg-white" srcDoc={previewDocument(message.body)} />
                        : <p className="whitespace-pre-wrap break-words px-5 py-6 text-sm leading-6 text-slate-200">{message.body}</p>}
            </section>

            <aside className="space-y-6">
                <section className={panelClass}>
                    <h2 className="font-semibold">Details</h2>
                    <dl className="mt-4 space-y-3 text-sm">
                        <Detail label="Person">
                            {message.person
                                ? <Link href={engagePath(`people/${message.person.id}`)} className="text-slate-200 hover:text-emerald-300">{message.person.external_id}</Link>
                                : 'Deleted person'}
                        </Detail>
                        <Detail label="From"><MessageSource source={message.source} /></Detail>
                        {message.source?.type === 'automation' && message.source.attempts > 1 && <Detail label="Attempts">{message.source.attempts}</Detail>}
                        <Detail label="Template">
                            {message.template
                                ? <Link href={engagePath(`templates/${message.template.id}/edit`)} className="text-slate-200 hover:text-emerald-300">{message.template.name}</Link>
                                : '—'}
                        </Detail>
                        {message.provider_message_id && <Detail label="Provider id"><span className="break-all font-mono text-xs">{message.provider_message_id}</span></Detail>}
                    </dl>
                </section>

                <section className={panelClass}>
                    <h2 className="font-semibold">Delivery</h2>
                    <ol className="mt-4 space-y-3">
                        {timeline.map(([label, at]) => <li key={label} className="flex items-center justify-between gap-3 text-sm">
                            <span className="flex items-center gap-2.5">
                                <span className={`size-2 rounded-full ${at ? (label === 'Bounced' ? 'bg-rose-400' : 'bg-emerald-400') : 'bg-white/15'}`} />
                                <span className={at ? 'text-slate-200' : 'text-slate-400'}>{label}</span>
                            </span>
                            <span className="text-xs text-slate-400">{at ? new Date(at).toLocaleString() : 'Not reported'}</span>
                        </li>)}
                    </ol>
                    {message.awaiting_receipt && <p className="mt-4 text-xs text-amber-200">Waiting for the push provider's receipt.</p>}
                    <p className="mt-4 text-xs leading-5 text-slate-400">Delivered, opened and clicked come from the provider's webhooks or receipts. "Not reported" means the provider never told us, not that it didn't happen.</p>
                </section>
            </aside>
        </div>
    </Layout>;
}

function Detail({ label, children }) {
    return <div className="grid grid-cols-[96px_1fr] gap-3">
        <dt className="text-xs uppercase tracking-wider text-slate-400">{label}</dt>
        <dd className="min-w-0 text-slate-300">{children}</dd>
    </div>;
}
