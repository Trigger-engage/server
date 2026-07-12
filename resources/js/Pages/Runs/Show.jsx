import { Link } from '@inertiajs/react';
import Layout, { panelClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

export default function ShowRun({ workspace, run }) {
    return <Layout title={`Run #${run.id}`} workspace={workspace}>
        <Link href={engagePath("runs")} className="text-sm text-slate-400">← Back to runs</Link>
        <div className="mt-5 flex items-end justify-between"><div><h1 className="text-3xl font-bold">Run #{run.id}</h1><p className="mt-2 text-sm text-slate-400">{run.automation.name} · {run.person.external_id} · {run.occurrence?.event?.name}</p></div><span className={`rounded-full px-3 py-1 text-sm ${run.context?.goal_reached ? 'bg-violet-400/15 text-violet-200' : 'bg-white/10'}`}>{run.status}{run.context?.goal_reached && ' · goal reached'}</span></div>
        <section className={`${panelClass} mt-8`}><h2 className="font-semibold">Timeline</h2><div className="mt-6">{run.steps.map((step, index) => <div key={step.id} className="grid grid-cols-[20px_1fr] gap-4"><div className="flex flex-col items-center"><span className={`mt-1 size-3 rounded-full ${step.status === 'failed' ? 'bg-rose-400' : ['retrying', 'waiting'].includes(step.status) ? 'bg-amber-400' : 'bg-emerald-400'}`} />{index < run.steps.length - 1 && <span className="h-full w-px bg-white/10" />}</div><div className="pb-7"><div className="flex items-center justify-between"><strong>{step.type.replaceAll('_', ' ')}</strong><span className="text-xs text-slate-500">{new Date(step.executed_at).toLocaleString()}</span></div><p className="mt-1 text-xs text-slate-400">{step.status} · {step.attempts || 1} attempt(s)</p>{step.type === 'wait_for_event' && <EventWaitSummary wait={run.event_waits?.find((wait) => wait.node_id === step.node_id)} output={step.output} />}{step.type === 'goal' && <GoalSummary output={step.output} />}{step.error && <p className="mt-2 rounded bg-rose-400/10 p-2 text-xs text-rose-200">{step.error}</p>}{step.output?.warnings?.map((warning) => <p key={warning} className="mt-2 text-xs text-amber-200">{warning}</p>)}{step.message && <div className="mt-3 rounded-lg border border-white/10 bg-slate-950 p-3 text-xs"><div>{step.message.channel} → {step.message.to_address}</div><div className="mt-1 text-slate-500">{step.message.status} {step.message.provider_message_id && `· ${step.message.provider_message_id}`}</div></div>}</div></div>)}</div></section>
    </Layout>;
}

function EventWaitSummary({ wait, output }) {
    if (!wait) return null;
    const tone = wait.status === 'matched' ? 'border-emerald-400/20 bg-emerald-400/5 text-emerald-200' : wait.status === 'timed_out' ? 'border-amber-400/20 bg-amber-400/5 text-amber-200' : 'border-sky-400/20 bg-sky-400/5 text-sky-200';
    return <div className={`mt-3 rounded-lg border p-3 text-xs ${tone}`}><div className="font-medium">Waiting for {wait.event?.name} · {wait.status.replaceAll('_', ' ')}</div><div className="mt-1 opacity-70">Deadline {new Date(wait.expires_at).toLocaleString()}</div>{output?.matched_occurrence_id && <div className="mt-1 opacity-70">Matched occurrence #{output.matched_occurrence_id}</div>}</div>;
}

function GoalSummary({ output }) {
    return <div className="mt-3 rounded-lg border border-violet-400/20 bg-violet-400/5 p-3 text-xs text-violet-200"><div className="font-medium">Automation goal reached</div><div className="mt-1 opacity-70">Occurrence #{output?.occurrence_id} stopped the remaining journey.</div></div>;
}
