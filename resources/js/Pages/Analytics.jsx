import { router } from '@inertiajs/react';
import Layout, { PageHeader, panelClass } from '../components/Layout';
import { ChannelBars, FunnelBars, TrendChart } from '../components/Charts';
import { engagePath } from '../lib/engagePath';

export default function Analytics({ workspace, days, ranges, series, totals, funnel, channels }) {
    const setRange = (value) => router.get(engagePath('analytics'), { days: value }, { preserveState: true, preserveScroll: true, replace: true });

    return (
        <Layout title="Analytics" workspace={workspace}>
            <PageHeader eyebrow="Insights" title="Analytics" description="Delivery health and messaging trends over time." action={
                <div className="inline-flex rounded-lg border border-white/10 bg-white/[0.03] p-1">
                    {ranges.map((range) => <button key={range} type="button" onClick={() => setRange(range)} className={`rounded-md px-3 py-1.5 text-xs font-medium transition ${days === range ? 'bg-emerald-400 text-slate-950' : 'text-slate-400 hover:text-slate-200'}`}>{range}d</button>)}
                </div>
            } />

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <Stat label="Messages" total={totals.messages} />
                <Stat label="Delivered" total={totals.delivered} tone="emerald" />
                <Stat label="Failed / bounced" total={totals.failed} tone="rose" invert />
                <Stat label="Runs started" total={totals.runs} />
                <Stat label="Events tracked" total={totals.events} />
            </div>

            <section className={`${panelClass} mt-6`}>
                <div className="mb-4 flex items-center justify-between"><div><h2 className="font-semibold">Message volume</h2><p className="mt-1 text-sm text-slate-500">Sent vs delivered per day (UTC).</p></div></div>
                <TrendChart primary={series.messages} secondary={series.delivered} primaryLabel="Sent" secondaryLabel="Delivered" />
            </section>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <section className={`${panelClass} lg:col-span-2`}>
                    <h2 className="font-semibold">Automation runs</h2>
                    <p className="mt-1 text-sm text-slate-500">Journeys started per day.</p>
                    <div className="mt-4"><TrendChart primary={series.runs} primaryLabel="Runs" accent="#60a5fa" height={180} /></div>
                </section>
                <section className={panelClass}>
                    <h2 className="font-semibold">Delivery funnel</h2>
                    <p className="mt-1 text-sm text-slate-500">Share of sent messages reaching each stage.</p>
                    <div className="mt-5"><FunnelBars stages={funnel} /></div>
                </section>
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <section className={`${panelClass} lg:col-span-2`}>
                    <h2 className="font-semibold">Events tracked</h2>
                    <p className="mt-1 text-sm text-slate-500">Inbound activity per day.</p>
                    <div className="mt-4"><TrendChart primary={series.events} primaryLabel="Events" accent="#f59e0b" height={180} /></div>
                </section>
                <section className={panelClass}>
                    <h2 className="font-semibold">By channel</h2>
                    <p className="mt-1 text-sm text-slate-500">Delivered vs failed per channel.</p>
                    <div className="mt-5"><ChannelBars channels={channels} /></div>
                </section>
            </div>
        </Layout>
    );
}

function Stat({ label, total, tone = 'slate', invert = false }) {
    const color = tone === 'emerald' ? 'text-emerald-300' : tone === 'rose' ? 'text-rose-300' : 'text-white';
    const delta = total.delta;
    const good = invert ? delta <= 0 : delta >= 0;
    const deltaColor = delta === 0 ? 'text-slate-500' : good ? 'text-emerald-400' : 'text-rose-400';
    return (
        <div className={panelClass}>
            <p className="text-xs font-medium uppercase tracking-wider text-slate-500">{label}</p>
            <p className={`mt-3 text-3xl font-bold ${color}`}>{total.current.toLocaleString()}</p>
            <p className={`mt-1 text-xs ${deltaColor}`}>{delta > 0 ? '↑' : delta < 0 ? '↓' : '·'} {Math.abs(delta)}% vs prior period</p>
        </div>
    );
}
