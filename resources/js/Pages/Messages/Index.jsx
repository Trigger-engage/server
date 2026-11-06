import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import Layout, { EmptyState, PageHeader, StatusBadge, inputClass, panelClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';
import MessageSource from '../../components/MessageSource';
import { channelLabel, deliverySignals } from '../../lib/messages';

const STATUS_CHIPS = [
    ['', 'All', 'all'],
    ['sent', 'Sent', 'sent'],
    ['delivered', 'Delivered', 'delivered'],
    ['problem', 'Failed or bounced', 'problem'],
    ['pending', 'Still sending', 'pending'],
];

const PERIODS = [['', 'Any time'], ['1', 'Last 24 hours'], ['7', 'Last 7 days'], ['30', 'Last 30 days'], ['90', 'Last 90 days']];

// Drops empty values so the URL only carries filters that are actually set.
function query(filters, changes = {}) {
    return Object.fromEntries(Object.entries({ ...filters, ...changes })
        .filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

export default function Index({ workspace, messages, counts, filters, scope, automations, broadcasts }) {
    const [search, setSearch] = useState(filters.search ?? '');
    const visit = (changes) => router.get(engagePath('messages'), query(filters, changes), { preserveState: true, preserveScroll: true, replace: true });
    const scopeLabel = scope.person ? `to ${scope.person.external_id}` : scope.automation ? `from “${scope.automation.name}”` : scope.broadcast ? `from the “${scope.broadcast.name}” broadcast` : null;
    const filtered = Object.values(query(filters)).length > 0;

    return <Layout title="Messages" workspace={workspace}>
        <PageHeader eyebrow="Activity" title="Messages" description="Every email, SMS and push message sent to a person, where it came from, and what the provider reported back." />

        <section className={panelClass}>
            <form className="grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1.4fr)_repeat(4,minmax(140px,1fr))]" onSubmit={(event) => { event.preventDefault(); visit({ search }); }}>
                <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Search
                    <input type="search" className={inputClass} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Address, subject or person id" />
                </label>
                <Select label="Channel" value={filters.channel} onChange={(channel) => visit({ channel })} options={[['', 'All channels'], ['email', 'Email'], ['sms', 'SMS'], ['push', 'Push']]} />
                <Select label="Automation" value={filters.automation ?? ''} onChange={(automation) => visit({ automation, broadcast: '' })} options={[['', 'Any automation'], ...automations.map((item) => [String(item.id), item.name])]} />
                <Select label="Broadcast" value={filters.broadcast ?? ''} onChange={(broadcast) => visit({ broadcast, automation: '' })} options={[['', 'Any broadcast'], ...broadcasts.map((item) => [String(item.id), item.name])]} />
                <Select label="Sent" value={filters.days ?? ''} onChange={(days) => visit({ days })} options={PERIODS} />
            </form>

            <div className="mt-5 flex flex-wrap items-center gap-2">
                {STATUS_CHIPS.map(([value, label, key]) => {
                    const active = (filters.status ?? '') === value;
                    return <Link key={key} href={engagePath('messages')} data={query(filters, { status: value })} preserveState preserveScroll
                        className={`rounded-full px-3 py-1 text-xs ${active ? 'bg-emerald-400 text-slate-950' : 'bg-white/5 text-slate-400 hover:bg-white/10'}`}>
                        {label} · {counts[key]}
                    </Link>;
                })}
                {filtered && <Link href={engagePath('messages')} className="ml-auto text-xs font-medium text-slate-400 hover:text-slate-200">Clear filters</Link>}
            </div>
            {scopeLabel && <p className="mt-4 text-sm text-slate-300">Showing messages {scopeLabel}.{scope.person && <> <Link href={engagePath(`people/${scope.person.id}`)} className="text-emerald-300 hover:text-emerald-200">Open profile →</Link></>}</p>}
        </section>

        <section className={`${panelClass} mt-6`}>
            {messages.data.length === 0
                ? <EmptyState title={filtered ? 'No messages match' : 'No messages yet'} description={filtered ? 'Try a wider period or clear a filter.' : 'Messages appear here as soon as an automation step or a broadcast sends one.'} />
                : <>
                <ul className="divide-y divide-white/[0.07] md:hidden">
                    {messages.data.map((message) => <li key={message.id} className="py-4">
                        <div className="flex items-start justify-between gap-3">
                            <Link href={engagePath(`messages/${message.id}`)} className="min-w-0 text-sm text-slate-200 hover:text-emerald-300">
                                <span className="mr-2 rounded bg-white/5 px-1.5 py-0.5 text-[11px] font-medium uppercase tracking-wider text-slate-400">{channelLabel(message.channel)}</span>
                                {message.subject || message.excerpt || 'Untitled message'}
                            </Link>
                            <StatusBadge status={message.status} />
                        </div>
                        <p className="mt-2 text-xs text-slate-400">
                            {message.person ? <Link href={engagePath(`people/${message.person.id}`)} className="text-slate-300 hover:text-emerald-300">{message.person.external_id}</Link> : 'Deleted person'}
                            {' · '}{new Date(message.created_at).toLocaleString()}
                        </p>
                        {message.error && <p className="mt-1 truncate font-mono text-[11px] text-rose-300">{message.error}</p>}
                        <div className="mt-2 text-xs"><MessageSource source={message.source} /></div>
                    </li>)}
                </ul>
                <div className="hidden overflow-x-auto md:block">
                    <table className="w-full min-w-[880px] text-left text-sm">
                        <thead>
                            <tr className="text-xs uppercase tracking-wider text-slate-400">
                                <th className="pb-3 pr-4 font-medium">Created</th>
                                <th className="pb-3 pr-4 font-medium">Person</th>
                                <th className="pb-3 pr-4 font-medium">Message</th>
                                <th className="pb-3 pr-4 font-medium">From</th>
                                <th className="pb-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.07]">
                            {messages.data.map((message) => <tr key={message.id} className="align-top hover:bg-white/[0.02]">
                                <td className="whitespace-nowrap py-3 pr-4 text-xs text-slate-400">{new Date(message.created_at).toLocaleString()}</td>
                                <td className="py-3 pr-4">
                                    {message.person
                                        ? <Link href={engagePath(`people/${message.person.id}`)} className="font-medium text-slate-200 hover:text-emerald-300">{message.person.external_id}</Link>
                                        : <span className="text-slate-400">Deleted person</span>}
                                    <div className="mt-0.5 max-w-[220px] truncate text-xs text-slate-400">{message.to_address}</div>
                                </td>
                                <td className="max-w-md py-3 pr-4">
                                    <Link href={engagePath(`messages/${message.id}`)} className="group block">
                                        <span className="mr-2 rounded bg-white/5 px-1.5 py-0.5 text-[11px] font-medium uppercase tracking-wider text-slate-400">{channelLabel(message.channel)}</span>
                                        <span className="text-slate-200 group-hover:text-emerald-300">{message.subject || message.excerpt || 'Untitled message'}</span>
                                    </Link>
                                    {message.template && <div className="mt-1 text-xs text-slate-400">Template: {message.template.name}</div>}
                                </td>
                                <td className="py-3 pr-4 text-xs"><MessageSource source={message.source} /></td>
                                <td className="py-3">
                                    <StatusBadge status={message.status} />
                                    <div className="mt-1.5 flex flex-wrap gap-1.5 text-[11px] text-slate-400">{deliverySignals(message).map((signal) => <span key={signal}>{signal}</span>)}</div>
                                    {message.error && <p className="mt-1.5 max-w-[240px] truncate font-mono text-[11px] text-rose-300" title={message.error}>{message.error}</p>}
                                </td>
                            </tr>)}
                        </tbody>
                    </table>
                </div>
                </>}
            {messages.links.length > 3 && <nav aria-label="Pagination" className="mt-5 flex flex-wrap gap-2 border-t border-white/[0.07] pt-5">
                {messages.links.map((link, index) => link.url
                    ? <Link key={index} href={link.url} preserveScroll className={`rounded-lg px-3 py-1.5 text-xs ${link.active ? 'bg-emerald-400 text-slate-950' : 'bg-white/5 text-slate-400 hover:bg-white/10'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                    : <span key={index} className="rounded-lg px-3 py-1.5 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}
            </nav>}
        </section>
    </Layout>;
}

function Select({ label, value, onChange, options }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{label}
        <select className={inputClass} value={value ?? ''} onChange={(event) => onChange(event.target.value)}>
            {options.map(([optionValue, optionLabel]) => <option key={optionValue} value={optionValue}>{optionLabel}</option>)}
        </select>
    </label>;
}
