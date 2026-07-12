import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Layout, { EmptyState, FieldError, PageHeader, buttonClass, inputClass, panelClass, secondaryButtonClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

const OPERATOR_LABELS = {
    equals: 'equals', not_equals: 'does not equal', gt: 'greater than', gte: 'greater or equal',
    lt: 'less than', lte: 'less or equal', contains: 'contains', exists: 'is set', not_exists: 'is not set',
};
const VALUE_LESS = ['exists', 'not_exists'];
const freshAttribute = (operators) => ({ kind: 'attribute', field: '', operator: operators[0] ?? 'equals', value: '' });
const freshEvent = (events) => ({ kind: 'event', event_id: events[0]?.id ?? '', performed: true, within_days: 30 });
const blankRules = () => ({ match: 'all', conditions: [] });

export default function Index({ workspace, events, segments, operators }) {
    const [editing, setEditing] = useState(null);

    return (
        <Layout title="Segments" workspace={workspace}>
            <PageHeader eyebrow="Audiences" title="Segments" description="All people is always ready for workspace-wide messages. Build narrower audiences manually, from events, or with behavioural rules." />
            <SegmentForm workspace={workspace} events={events} operators={operators} editing={editing} onDone={() => setEditing(null)} />
            <section className={`${panelClass} mt-6`}>
                <div className="flex justify-between"><h2 className="font-semibold">All segments</h2><span className="text-xs text-slate-500">{segments.length} total</span></div>
                <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {segments.length === 0
                        ? <div className="md:col-span-2 xl:col-span-3"><EmptyState title="No segments yet" description="Create an audience to organize people and send targeted broadcasts." /></div>
                        : segments.map((segment) => <SegmentCard key={segment.id} segment={segment} events={events} onEdit={() => setEditing(segment)} />)}
                </div>
            </section>
        </Layout>
    );
}

function SegmentForm({ workspace, events, operators, editing, onDone }) {
    const isEdit = Boolean(editing);
    const form = useForm({
        name: editing?.name ?? '', description: editing?.description ?? '',
        type: editing?.type ?? 'manual', event_id: editing?.event_id ?? '',
        rules: editing?.rules ?? blankRules(),
    });
    // Re-seed the form when the edit target changes.
    const [seededFor, setSeededFor] = useState(editing?.id ?? null);
    if ((editing?.id ?? null) !== seededFor) {
        setSeededFor(editing?.id ?? null);
        form.setData({
            name: editing?.name ?? '', description: editing?.description ?? '',
            type: editing?.type ?? 'rule', event_id: editing?.event_id ?? '',
            rules: editing?.rules ?? blankRules(),
        });
    }

    const setRules = (updater) => form.setData('rules', updater(form.data.rules));
    const addCondition = (condition) => setRules((r) => ({ ...r, conditions: [...r.conditions, condition] }));
    const updateCondition = (index, patch) => setRules((r) => ({ ...r, conditions: r.conditions.map((c, i) => i === index ? { ...c, ...patch } : c) }));
    const removeCondition = (index) => setRules((r) => ({ ...r, conditions: r.conditions.filter((_, i) => i !== index) }));

    const submit = (e) => {
        e.preventDefault();
        const done = () => { form.reset(); onDone(); };
        if (isEdit) form.put(engagePath(`segments/${editing.id}`), { onSuccess: done, preserveScroll: true });
        else form.post(engagePath('segments'), { onSuccess: done, preserveScroll: true });
    };

    return (
        <section className={panelClass}>
            <div className="flex items-center justify-between">
                <h2 className="font-semibold">{isEdit ? `Edit rules · ${editing.name}` : 'Create a segment'}</h2>
                {isEdit && <button type="button" className="text-xs text-slate-400 hover:text-white" onClick={() => { form.reset(); onDone(); }}>Cancel edit</button>}
            </div>
            <form onSubmit={submit} className="mt-5 grid gap-4 lg:grid-cols-2">
                <Field label="Name"><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="No-show in the last 30 days" /><FieldError message={form.errors.name} /></Field>
                <Field label="Membership">
                    <select className={inputClass} value={form.data.type} disabled={isEdit} onChange={(e) => form.setData('type', e.target.value)}>
                        <option value="manual">Manual via SDK or API</option>
                        <option value="event">Automatic from an event</option>
                        <option value="rule">Rule-based (attributes + behaviour)</option>
                    </select>
                </Field>
                {form.data.type === 'event' && <Field label="Add person when this event fires"><select className={inputClass} value={form.data.event_id} onChange={(e) => form.setData('event_id', Number(e.target.value))}><option value="">Select event</option>{events.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select><FieldError message={form.errors.event_id} /></Field>}
                <Field label="Description"><input className={inputClass} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="Who belongs in this audience?" /></Field>

                {form.data.type === 'rule' && (
                    <div className="lg:col-span-2 rounded-xl border border-violet-400/20 bg-violet-400/[0.04] p-5">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <span className="text-slate-400">Include a person when</span>
                            <select aria-label="Match mode" className="rounded-lg border border-white/10 bg-slate-900 px-2 py-1 text-sm text-violet-200" value={form.data.rules.match} onChange={(e) => setRules((r) => ({ ...r, match: e.target.value }))}>
                                <option value="all">all</option><option value="any">any</option>
                            </select>
                            <span className="text-slate-400">of these conditions match:</span>
                        </div>
                        <div className="mt-4 space-y-3">
                            {form.data.rules.conditions.length === 0 && <p className="text-xs text-slate-500">No conditions yet — add an attribute or behaviour below.</p>}
                            {form.data.rules.conditions.map((condition, index) => (
                                <ConditionRow key={index} condition={condition} events={events} operators={operators} onChange={(patch) => updateCondition(index, patch)} onRemove={() => removeCondition(index)} />
                            ))}
                        </div>
                        <div className="mt-4 flex gap-2">
                            <button type="button" className={secondaryButtonClass} onClick={() => addCondition(freshAttribute(operators))}>+ Attribute</button>
                            <button type="button" className={secondaryButtonClass} disabled={events.length === 0} onClick={() => addCondition(freshEvent(events))}>+ Behaviour</button>
                        </div>
                        <FieldError message={form.errors.rules || form.errors['rules.conditions']} />
                    </div>
                )}

                <div className="lg:col-span-2"><button className={buttonClass} disabled={form.processing}>{form.processing ? 'Saving…' : isEdit ? 'Save rules & recompute' : 'Create segment'}</button></div>
            </form>
        </section>
    );
}

function ConditionRow({ condition, events, operators, onChange, onRemove }) {
    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-white/10 bg-slate-950/40 p-3">
            <select aria-label="Condition kind" className="rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm" value={condition.kind} onChange={(e) => onChange(e.target.value === 'event' ? freshEvent(events) : freshAttribute(operators))}>
                <option value="attribute">Attribute</option><option value="event">Behaviour</option>
            </select>
            {condition.kind === 'attribute' ? (
                <>
                    <input className="w-40 rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm" placeholder="plan" value={condition.field} onChange={(e) => onChange({ field: e.target.value })} />
                    <select aria-label="Operator" className="rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm" value={condition.operator} onChange={(e) => onChange({ operator: e.target.value })}>
                        {operators.map((op) => <option key={op} value={op}>{OPERATOR_LABELS[op] ?? op}</option>)}
                    </select>
                    {!VALUE_LESS.includes(condition.operator) && <input className="w-40 rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm" placeholder="premium" value={condition.value ?? ''} onChange={(e) => onChange({ value: e.target.value })} />}
                </>
            ) : (
                <>
                    <select aria-label="Performed" className="rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm" value={condition.performed ? 'did' : 'did_not'} onChange={(e) => onChange({ performed: e.target.value === 'did' })}>
                        <option value="did">performed</option><option value="did_not">did not perform</option>
                    </select>
                    <select aria-label="Event" className="rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm" value={condition.event_id} onChange={(e) => onChange({ event_id: Number(e.target.value) })}>
                        {events.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}
                    </select>
                    <span className="text-xs text-slate-500">in the last</span>
                    <input type="number" min="0" className="w-16 rounded-lg border border-white/10 bg-slate-900 px-2 py-1.5 text-sm" value={condition.within_days ?? 0} onChange={(e) => onChange({ within_days: Number(e.target.value) })} />
                    <span className="text-xs text-slate-500">days (0 = ever)</span>
                </>
            )}
            <button type="button" className="ml-auto rounded-md border border-white/10 px-2 py-1 text-xs text-slate-400 hover:bg-white/10" onClick={onRemove}>Remove</button>
        </div>
    );
}

function SegmentCard({ segment, events, onEdit }) {
    const summary = segment.type === 'all' ? 'Automatically includes every profile' : segment.type === 'event' ? `Auto: ${segment.event?.name}` : segment.type === 'rule' ? 'Rule-based' : 'Manual membership';
    return (
        <article className="rounded-xl border border-white/[0.08] bg-slate-950/30 p-5">
            <div className="flex items-start justify-between gap-3">
                <div><div className="flex items-center gap-2"><h3 className="font-medium">{segment.name}</h3>{segment.type === 'all' && <span className="rounded-full bg-emerald-400/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-300">Default</span>}</div><p className="mt-1 text-xs text-slate-500">{summary}</p></div>
                <span className="rounded-full bg-violet-400/10 px-2.5 py-1 text-xs text-violet-200">{segment.people_count} people</span>
            </div>
            {segment.description && <p className="mt-4 text-sm text-slate-500">{segment.description}</p>}
            {segment.type === 'rule' && <RuleSummary rules={segment.rules} events={events} />}
            <div className="mt-4 flex items-center justify-between gap-3 border-t border-white/[0.06] pt-3 font-mono text-[11px] text-slate-600">
                <span>{segment.public_id}</span>
                <div className="flex items-center gap-3 font-sans">
                    {segment.type === 'rule' && <button type="button" className="text-xs text-violet-300 hover:text-violet-200" onClick={onEdit}>Edit rules</button>}
                    <Link href={engagePath(`segments/${segment.id}`)} className="text-xs font-medium text-emerald-300 hover:text-emerald-200">Manage →</Link>
                </div>
            </div>
        </article>
    );
}

function RuleSummary({ rules, events }) {
    if (!rules?.conditions?.length) return null;
    const describe = (c) => c.kind === 'event'
        ? `${c.performed ? 'did' : 'did not'} ${events.find((e) => Number(e.id) === Number(c.event_id))?.name ?? 'event'}${c.within_days ? ` · ${c.within_days}d` : ''}`
        : `${c.field} ${OPERATOR_LABELS[c.operator] ?? c.operator}${VALUE_LESS.includes(c.operator) ? '' : ` ${c.value}`}`;
    return (
        <div className="mt-4 space-y-1.5">
            <p className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Match {rules.match}</p>
            {rules.conditions.map((c, i) => <div key={i} className="rounded-md bg-white/[0.03] px-2 py-1 text-xs text-slate-400">{describe(c)}</div>)}
        </div>
    );
}

function Field({ label, children }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{label}{children}</label>;
}
