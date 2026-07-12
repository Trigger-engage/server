import { Link } from '@inertiajs/react';
import Layout, { EmptyState, PageHeader, panelClass } from '../components/Layout';
import { engagePath } from '../lib/engagePath';

const sections = [
    ['people', 'People', 'View profiles and customer properties.'],
    ['automations', 'Automations', 'Build and publish customer journeys.'],
    ['events', 'Events', 'Manage the activity that starts and advances journeys.'],
    ['templates', 'Templates', 'Create branded email, SMS, and push messages.'],
    ['channels', 'Channels', 'Connect SMTP and messaging providers.'],
    ['segments', 'Segments', 'Organize people into reusable audiences.'],
    ['broadcasts', 'Broadcasts', 'Send one-time messages to a segment.'],
];

export default function Dashboard({ workspace, counts, automations, metrics, recentRuns }) {
    return <Layout title="Overview" workspace={workspace}>
        <PageHeader eyebrow="Workspace overview" title="Good to see you" description="Monitor delivery health and continue building your customer messaging from one place." />
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Metric label="Runs in 30 days" value={metrics.runs_30d} />
            <Metric label="Messages sent" value={metrics.messages_30d} />
            <Metric label="Delivered" value={metrics.delivered_30d} tone="emerald" />
            <Metric label="Failed or bounced" value={metrics.failed_30d} tone="rose" />
        </div>
        <div className="mt-7 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {sections.map(([key, label, description]) => <Link key={key} href={engagePath(key)} className="rounded-2xl border border-white/[0.08] bg-white/[0.025] p-5 transition hover:-translate-y-0.5 hover:border-emerald-400/20 hover:bg-white/[0.04]"><div className="flex items-center justify-between"><span className="text-sm font-semibold text-slate-200">{label}</span><span className="text-2xl font-bold text-white">{counts[key]}</span></div><p className="mt-3 text-sm leading-6 text-slate-500">{description}</p><span className="mt-4 inline-block text-xs font-medium text-emerald-300">Open {label.toLowerCase()} →</span></Link>)}
        </div>
        <div className="mt-7 grid gap-6 xl:grid-cols-5">
            <section className={`${panelClass} xl:col-span-3`}><div className="flex items-center justify-between"><div><h2 className="font-semibold">Recent automations</h2><p className="mt-1 text-sm text-slate-500">Your latest journey drafts and published flows.</p></div><Link href={engagePath('automations')} className="text-xs font-medium text-emerald-300">View all →</Link></div><div className="mt-5 divide-y divide-white/[0.07]">{automations.length === 0 ? <EmptyState title="No automations yet" description="Define an event, then create your first customer journey." action={<Link href={engagePath('automations')} className="text-sm font-medium text-emerald-300">Create an automation →</Link>} /> : automations.map((item) => <Link key={item.id} href={engagePath(`automations/${item.id}`)} className="flex items-center justify-between gap-4 py-4 hover:text-emerald-300"><div><div className="text-sm font-medium">{item.name}</div><div className="mt-1 text-xs text-slate-500">{item.trigger_event?.name} · {item.runs_count} runs</div></div><Status value={item.status} /></Link>)}</div></section>
            <section className={`${panelClass} xl:col-span-2`}><div className="flex items-center justify-between"><h2 className="font-semibold">Recent runs</h2><Link href={engagePath('runs')} className="text-xs font-medium text-emerald-300">View all →</Link></div><div className="mt-4 divide-y divide-white/[0.07]">{recentRuns.length === 0 ? <p className="py-8 text-center text-sm text-slate-500">Runs will appear after an event starts an automation.</p> : recentRuns.map((run) => <Link key={run.id} href={engagePath(`runs/${run.id}`)} className="flex items-center justify-between gap-3 py-3 text-sm hover:text-emerald-300"><span className="min-w-0 truncate"><span className="text-slate-500">#{run.id}</span> {run.automation.name}</span><Status value={run.status} /></Link>)}</div></section>
        </div>
    </Layout>;
}

function Metric({ label, value, tone = 'slate' }) { const colors = tone === 'emerald' ? 'text-emerald-300' : tone === 'rose' ? 'text-rose-300' : 'text-white'; return <div className={panelClass}><p className="text-xs font-medium uppercase tracking-wider text-slate-500">{label}</p><p className={`mt-3 text-3xl font-bold ${colors}`}>{value}</p></div>; }
function Status({ value }) { return <span className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-medium ${['active', 'completed', 'delivered'].includes(value) ? 'bg-emerald-400/10 text-emerald-300' : value === 'failed' ? 'bg-rose-400/10 text-rose-300' : 'bg-amber-400/10 text-amber-200'}`}>{value}</span>; }
