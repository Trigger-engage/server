import { Link, router } from '@inertiajs/react';
import Layout, { EmptyState, PageHeader, StatusBadge, inputClass, panelClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

const STATUS_CHIPS = [
    ['', 'All'],
    ['active', 'In progress'],
    ['completed', 'Completed'],
    ['step_failed', 'A step failed'],
    ['failed', 'Failed'],
    ['cancelled', 'Cancelled'],
];

function query(filters, changes = {}) {
    return Object.fromEntries(Object.entries({ ...filters, ...changes })
        .filter(([, value]) => value !== '' && value !== null && value !== undefined));
}

export default function Index({ workspace, runs, filters, automations, scopedPerson }) {
    const visit = (changes) => router.get(engagePath('runs'), query(filters, changes), { preserveState: true, preserveScroll: true, replace: true });
    const filtered = Object.values(query(filters)).length > 0;

    return <Layout title="Runs" workspace={workspace}>
        <PageHeader eyebrow="Activity" title="Automation runs" description="Inspect every person moving through an automation and diagnose delivery issues." />

        <section className={panelClass}>
            <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div className="flex flex-wrap gap-2">
                    {STATUS_CHIPS.map(([value, label]) => {
                        const active = (filters.status ?? '') === value;
                        return <Link key={label} href={engagePath('runs')} data={query(filters, { status: value })} preserveState preserveScroll
                            className={`rounded-full px-3 py-1 text-xs ${active ? 'bg-emerald-400 text-slate-950' : 'bg-white/5 text-slate-400 hover:bg-white/10'}`}>{label}</Link>;
                    })}
                </div>
                <label className="block w-full text-xs font-medium uppercase tracking-wider text-slate-400 lg:w-72">Automation
                    <select className={inputClass} value={filters.automation ?? ''} onChange={(event) => visit({ automation: event.target.value })}>
                        <option value="">Any automation</option>
                        {automations.map((automation) => <option key={automation.id} value={automation.id}>{automation.name}</option>)}
                    </select>
                </label>
            </div>
            {(scopedPerson || filtered) && <div className="mt-4 flex flex-wrap items-center gap-3 text-sm text-slate-300">
                {scopedPerson && <span>Showing runs for <Link href={engagePath(`people/${scopedPerson.id}`)} className="text-emerald-300 hover:text-emerald-200">{scopedPerson.external_id}</Link>.</span>}
                {filtered && <Link href={engagePath('runs')} className="text-xs font-medium text-slate-400 hover:text-slate-200">Clear filters</Link>}
            </div>}
        </section>

        <section className={`${panelClass} mt-6`}>
            {runs.data.length === 0
                ? <EmptyState title={filtered ? 'No runs match' : 'No runs yet'} description={filtered ? 'Try another status or automation.' : 'A run will appear when an incoming event starts an active automation.'} />
                : <div className="overflow-x-auto">
                    <table className="w-full min-w-[800px] text-left text-sm">
                        <thead className="border-b border-white/10 text-xs uppercase tracking-wider text-slate-400">
                            <tr><th className="py-3 font-medium">Run</th><th className="font-medium">Automation</th><th className="font-medium">Person</th><th className="font-medium">Trigger</th><th className="font-medium">Status</th><th className="font-medium">Started</th></tr>
                        </thead>
                        <tbody className="divide-y divide-white/[0.07]">
                            {runs.data.map((run) => <tr key={run.id} className="hover:bg-white/[0.02]">
                                <td className="py-4"><Link href={engagePath(`runs/${run.id}`)} className="font-medium text-emerald-300">#{run.id}</Link></td>
                                <td>{run.automation
                                    ? <Link href={engagePath(`automations/${run.automation.id}`)} className="text-slate-200 hover:text-emerald-300">{run.automation.name}</Link>
                                    : <span className="text-slate-400">Deleted automation</span>}</td>
                                <td>{run.person
                                    ? <Link href={engagePath(`people/${run.person.id}`)} className="group block"><span className="text-slate-200 group-hover:text-emerald-300">{run.person.external_id}</span><span className="block text-xs text-slate-400">{run.person.email}</span></Link>
                                    : <span className="text-slate-400">Deleted person</span>}</td>
                                <td className="text-slate-400">{run.occurrence?.event?.name || '—'}</td>
                                <td>
                                    <StatusBadge status={run.status} />
                                    {run.failed_steps_count > 0 && <span className="ml-2 text-xs text-rose-300">{run.failed_steps_count} {run.failed_steps_count === 1 ? 'step' : 'steps'} failed</span>}
                                </td>
                                <td className="text-slate-400">{new Date(run.created_at).toLocaleString()}</td>
                            </tr>)}
                        </tbody>
                    </table>
                </div>}
            {runs.links.length > 3 && <nav aria-label="Pagination" className="mt-5 flex flex-wrap gap-2 border-t border-white/[0.07] pt-5">
                {runs.links.map((link, index) => link.url
                    ? <Link key={index} href={link.url} className={`rounded-lg px-3 py-1.5 text-xs ${link.active ? 'bg-emerald-400 text-slate-950' : 'bg-white/5 text-slate-400 hover:bg-white/10'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                    : <span key={index} className="rounded-lg px-3 py-1.5 text-xs text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />)}
            </nav>}
        </section>
    </Layout>;
}
