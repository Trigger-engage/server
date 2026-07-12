import { Link, router, useForm } from '@inertiajs/react';
import { Background, Controls, ReactFlow } from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import Layout, { FieldError, buttonClass, inputClass, panelClass, secondaryButtonClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

const freshDelay = () => ({ type: 'delay', days: 0, hours: 0, minutes: 10, until_time: '' });
const freshWait = (events) => ({ type: 'wait_for_event', event_id: events[0]?.id ?? '', timeout_days: 1, timeout_hours: 0, timeout_minutes: 0, incoming_field: '', trigger_field: '', match_operator: 'equals', timeout_action: 'exit' });
const freshSend = (kind, templates, channels) => {
    const channelType = kind.replace('send_', '');
    return { type: kind, template_id: templates.find((item) => item.channel === channelType)?.id ?? '', channel_id: channels.find((item) => item.type === channelType)?.id ?? '', retry_attempts: 3, on_failure: 'continue' };
};
const freshVariant = (key, templates, channels, type = 'email') => ({ key, weight: 50, type, template_id: templates.find((item) => item.channel === type)?.id ?? '', channel_id: channels.find((item) => item.type === type)?.id ?? '', retry_attempts: 3, on_failure: 'continue' });
const freshSplit = (templates, channels) => ({ type: 'split', variants: [freshVariant('A', templates, channels), freshVariant('B', templates, channels)] });
const canSend = (type, templates, channels) => templates.some((item) => item.channel === type) && channels.some((item) => item.type === type);

export default function EditAutomation({ workspace, automation, templates, channels, events, abTests = [] }) {
    const form = useForm({ steps: automation.steps, goal: automation.goal });

    const addStep = (step) => form.setData('steps', [...form.data.steps, step]);
    const updateStep = (index, key, value) => form.setData('steps', form.data.steps.map((step, position) => position === index ? { ...step, [key]: value } : step));
    const updateGoal = (key, value) => form.setData('goal', { ...form.data.goal, [key]: value });
    const toggleGoal = (enabled) => form.setData('goal', { ...form.data.goal, enabled, event_id: form.data.goal.event_id || events[0]?.id || '' });
    const removeStep = (index) => form.setData('steps', form.data.steps.filter((_, position) => position !== index));
    const moveStep = (index, direction) => {
        const target = index + direction;
        if (target < 0 || target >= form.data.steps.length) return;
        const steps = [...form.data.steps];
        [steps[index], steps[target]] = [steps[target], steps[index]];
        form.setData('steps', steps);
    };

    const publish = (event) => {
        event.preventDefault();
        form.put(engagePath(`automations/${automation.id}/publish`), { preserveScroll: true });
    };
    const { nodes: flowNodes, edges: flowEdges } = buildFlow(form.data.steps, automation.trigger_event?.name, events, form.data.goal);
    const reorderFromCanvas = (_, node) => {
        if (!node.id.startsWith('step-')) return;
        const original = Number(node.id.replace('step-', ''));
        const target = flowNodes
            .filter((candidate) => candidate.id.startsWith('step-'))
            .map((candidate) => ({ index: Number(candidate.id.replace('step-', '')), distance: Math.abs(candidate.position.y - node.position.y) }))
            .sort((left, right) => left.distance - right.distance)[0]?.index ?? original;
        if (original === target) return;
        const steps = [...form.data.steps];
        const [moved] = steps.splice(original, 1);
        steps.splice(target, 0, moved);
        form.setData('steps', steps);
    };

    return (
        <Layout title={automation.name} workspace={workspace}>
            <Link href={engagePath('automations')} className="text-sm text-slate-400 transition hover:text-white">← Back to automations</Link>
            <div className="mt-5 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div><div className="flex items-center gap-3"><h1 className="text-3xl font-bold tracking-tight">{automation.name}</h1><span className={`rounded-full px-2.5 py-1 text-xs ${automation.status === 'active' ? 'bg-emerald-400/15 text-emerald-300' : 'bg-amber-400/15 text-amber-200'}`}>{automation.status}</span></div><p className="mt-2 text-sm text-slate-400">When <strong className="text-slate-200">{automation.trigger_event?.name}</strong> occurs · {automation.reentry_policy.replaceAll('_', ' ')}</p></div>
                {automation.status === 'active' && <button className={secondaryButtonClass} onClick={() => router.post(engagePath(`automations/${automation.id}/pause`))}>Pause automation</button>}
            </div>

            <form onSubmit={publish} className="mt-8 grid gap-6 lg:grid-cols-[1fr_300px]">
                <section className={panelClass}>
                    <div className="mb-6 h-[360px] overflow-hidden rounded-xl border border-white/10 bg-slate-950"><ReactFlow key={`${form.data.goal.enabled}:${form.data.goal.event_id}|${form.data.steps.map((step) => `${step.type}:${step.timeout_action ?? ''}:${step.variants?.length ?? ''}`).join('|')}`} nodes={flowNodes} edges={flowEdges} fitView fitViewOptions={{ padding: 0.24 }} onNodeDragStop={reorderFromCanvas} minZoom={0.35} maxZoom={1.5}><Background color="#334155" gap={18} /><Controls /></ReactFlow></div>
                    <div className="rounded-xl border border-emerald-400/25 bg-emerald-400/10 p-4"><div className="text-xs font-semibold uppercase tracking-wider text-emerald-300">Trigger</div><div className="mt-1 font-medium">{automation.trigger_event?.name}</div></div>

                    <div className="mx-7 h-6 w-px bg-white/15" />
                    {form.data.steps.length === 0 && <div className="rounded-xl border border-dashed border-white/15 p-8 text-center text-sm text-slate-500">No actions yet. Add a delay or email step.</div>}
                    {form.data.steps.map((step, index) => (
                        <div key={index}>
                            <div className="rounded-xl border border-white/10 bg-slate-900/80 p-5">
                                <div className="mb-4 flex items-center justify-between"><div><span className="mr-2 text-xs text-slate-500">{index + 1}</span><span className="font-semibold">{step.type === 'split' ? 'A/B test' : step.type.replaceAll('_', ' ')}</span></div><div className="flex gap-1"><TinyButton onClick={() => moveStep(index, -1)} disabled={index === 0}>↑</TinyButton><TinyButton onClick={() => moveStep(index, 1)} disabled={index === form.data.steps.length - 1}>↓</TinyButton><TinyButton onClick={() => removeStep(index)}>Remove</TinyButton></div></div>
                                {step.type === 'delay' && <DelayStep step={step} update={(key, value) => updateStep(index, key, value)} />}
                                {step.type === 'wait_for_event' && <WaitForEventStep step={step} update={(key, value) => updateStep(index, key, value)} events={events} templates={templates} channels={channels} />}
                                {step.type === 'split' && <SplitStep step={step} update={(key, value) => updateStep(index, key, value)} templates={templates} channels={channels} />}
                                {step.type.startsWith('send_') && <SendStep step={step} update={(key, value) => updateStep(index, key, value)} templates={templates} channels={channels} />}
                            </div>
                            <div className="mx-7 h-6 w-px bg-white/15" />
                        </div>
                    ))}
                    <div className="rounded-xl border border-white/10 bg-white/5 p-4"><div className="text-xs font-semibold uppercase tracking-wider text-slate-400">Exit</div><div className="mt-1 text-sm text-slate-300">Complete the run</div></div>
                </section>

                <aside className="space-y-4 lg:sticky lg:top-6 lg:self-start">
                    {abTests.length > 0 && <AbResults abTests={abTests} />}
                    <GoalSettings goal={form.data.goal} events={events} update={updateGoal} toggle={toggleGoal} error={form.errors.goal || form.errors['goal.event_id']} />
                    <section className={panelClass}><h2 className="font-semibold">Add a step</h2><div className="mt-4 grid gap-2"><button type="button" className={secondaryButtonClass} onClick={() => addStep(freshDelay())}>+ Delay</button><button type="button" className={secondaryButtonClass} disabled={events.length === 0} onClick={() => addStep(freshWait(events))}>+ Wait for event</button>{['email', 'sms', 'push'].map((type) => <button key={type} type="button" className={secondaryButtonClass} disabled={!canSend(type, templates, channels)} onClick={() => addStep(freshSend(`send_${type}`, templates, channels))}>+ Send {type}</button>)}<button type="button" className={secondaryButtonClass} disabled={!['email', 'sms', 'push'].some((type) => canSend(type, templates, channels))} onClick={() => addStep(freshSplit(templates, channels))}>+ A/B test</button></div>{events.length === 0 && <p className="mt-3 text-xs text-amber-200">Create the event you want to wait for first.</p>}</section>
                    <section className={panelClass}><h2 className="font-semibold">Publish</h2><p className="mt-2 text-xs leading-5 text-slate-500">Publishing creates an immutable version. Existing runs remain pinned to their original version.</p><FieldError message={form.errors.steps} /><button className={`${buttonClass} mt-4 w-full`} disabled={form.processing}>{form.processing ? 'Publishing…' : 'Publish new version'}</button>{automation.published_at && <p className="mt-3 text-center text-xs text-slate-600">Last published {new Date(automation.published_at).toLocaleString()}</p>}</section>
                </aside>
            </form>
        </Layout>
    );
}

function DelayStep({ step, update }) {
    return <div><div className="grid grid-cols-3 gap-3">{['days', 'hours', 'minutes'].map((unit) => <label key={unit} className="text-xs uppercase tracking-wider text-slate-500">{unit}<input type="number" min="0" className={inputClass} value={step[unit] ?? 0} onChange={(e) => update(unit, Number(e.target.value))} /></label>)}</div><div className="my-3 flex items-center gap-3 text-xs text-slate-600"><span className="h-px flex-1 bg-white/10" />or wait until local time<span className="h-px flex-1 bg-white/10" /></div><input type="time" className={inputClass} value={step.until_time ?? ''} onChange={(e) => update('until_time', e.target.value)} /></div>;
}

function GoalSettings({ goal, events, update, toggle, error }) {
    return <section className={panelClass}><div className="flex items-start gap-3"><input id="goal-enabled" type="checkbox" className="mt-1 size-4 accent-violet-400" checked={goal.enabled} disabled={events.length === 0} onChange={(e) => toggle(e.target.checked)} /><label htmlFor="goal-enabled"><span className="font-semibold">Goal / stop event</span><span className="mt-1 block text-xs leading-5 text-slate-500">Complete this run from any node when the goal event occurs.</span></label></div>{goal.enabled && <div className="mt-4 space-y-4"><label className="block text-xs uppercase tracking-wider text-slate-500">Goal event<select className={inputClass} value={goal.event_id ?? ''} onChange={(e) => update('event_id', Number(e.target.value))}>{events.map((event) => <option key={event.id} value={event.id}>{event.name}</option>)}</select></label><div className="rounded-lg border border-violet-400/20 bg-violet-400/5 p-3"><p className="text-xs font-semibold uppercase tracking-wider text-violet-200">Correlation (optional)</p><p className="mt-1 text-xs leading-5 text-slate-500">Require a field on the goal event to match the trigger event.</p><input className={inputClass} placeholder="Goal event field" value={goal.incoming_field ?? ''} onChange={(e) => update('incoming_field', e.target.value)} /><select aria-label="Goal match operator" className={inputClass} value={goal.match_operator ?? 'equals'} onChange={(e) => update('match_operator', e.target.value)}><option value="equals">equals</option><option value="not_equals">does not equal</option></select><input className={inputClass} placeholder="Trigger event field" value={goal.trigger_field ?? ''} onChange={(e) => update('trigger_field', e.target.value)} /></div></div>}<FieldError message={error} /></section>;
}

function WaitForEventStep({ step, update, events, templates, channels }) {
    const timeoutType = step.timeout_action?.replace('send_', '');
    const canSend = (type) => templates.some((item) => item.channel === type) && channels.some((item) => item.type === type);

    return <div className="space-y-5">
        <label className="block text-xs uppercase tracking-wider text-slate-500">Event to wait for<select className={inputClass} value={step.event_id ?? ''} onChange={(e) => update('event_id', Number(e.target.value))}>{events.map((event) => <option key={event.id} value={event.id}>{event.name}</option>)}</select></label>
        <div><p className="mb-2 text-xs uppercase tracking-wider text-slate-500">Timeout</p><div className="grid grid-cols-3 gap-3">{['days', 'hours', 'minutes'].map((unit) => <label key={unit} className="text-xs capitalize text-slate-500">{unit}<input type="number" min="0" className={inputClass} value={step[`timeout_${unit}`] ?? 0} onChange={(e) => update(`timeout_${unit}`, Number(e.target.value))} /></label>)}</div></div>
        <div className="rounded-lg border border-white/10 bg-slate-950/60 p-4"><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Correlation (optional)</p><p className="mt-1 text-xs leading-5 text-slate-500">Only resume when a field on the incoming event matches the same value from the event that started this run.</p><div className="mt-3 grid gap-3 sm:grid-cols-[1fr_auto_1fr]"><label className="text-xs text-slate-500">Incoming field<input className={inputClass} placeholder="appointment_id" value={step.incoming_field ?? ''} onChange={(e) => update('incoming_field', e.target.value)} /></label><select aria-label="Match operator" className={`${inputClass} self-end`} value={step.match_operator ?? 'equals'} onChange={(e) => update('match_operator', e.target.value)}><option value="equals">equals</option><option value="not_equals">does not equal</option></select><label className="text-xs text-slate-500">Trigger field<input className={inputClass} placeholder="appointment_id" value={step.trigger_field ?? ''} onChange={(e) => update('trigger_field', e.target.value)} /></label></div></div>
        <label className="block text-xs uppercase tracking-wider text-slate-500">When time runs out<select className={inputClass} value={step.timeout_action ?? 'exit'} onChange={(e) => update('timeout_action', e.target.value)}><option value="exit">Stop the run</option><option value="continue">Continue to the next step</option>{['email', 'sms', 'push'].filter(canSend).map((type) => <option key={type} value={`send_${type}`}>Send {type}, then continue</option>)}</select></label>
        {step.timeout_action?.startsWith('send_') && <TimeoutSendStep type={timeoutType} step={step} update={update} templates={templates} channels={channels} />}
    </div>;
}

function TimeoutSendStep({ type, step, update, templates, channels }) {
    const availableTemplates = templates.filter((item) => item.channel === type);
    const availableChannels = channels.filter((item) => item.type === type);
    const ensureDefault = (key, value) => step[key] || value;

    return <div className="grid gap-4 rounded-lg border border-amber-400/20 bg-amber-400/5 p-4 sm:grid-cols-2"><label className="text-xs uppercase tracking-wider text-slate-500">Timeout template<select className={inputClass} value={ensureDefault('timeout_template_id', availableTemplates[0]?.id ?? '')} onChange={(e) => update('timeout_template_id', Number(e.target.value))}>{availableTemplates.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="text-xs uppercase tracking-wider text-slate-500">Timeout channel<select className={inputClass} value={ensureDefault('timeout_channel_id', availableChannels[0]?.id ?? '')} onChange={(e) => update('timeout_channel_id', Number(e.target.value))}>{availableChannels.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.driver})</option>)}</select></label><label className="text-xs uppercase tracking-wider text-slate-500">Attempts<input type="number" min="1" max="10" className={inputClass} value={step.timeout_retry_attempts ?? 3} onChange={(e) => update('timeout_retry_attempts', Number(e.target.value))} /></label><label className="text-xs uppercase tracking-wider text-slate-500">After final failure<select className={inputClass} value={step.timeout_on_failure ?? 'continue'} onChange={(e) => update('timeout_on_failure', e.target.value)}><option value="continue">Continue run</option><option value="fail">Fail run</option></select></label></div>;
}

function SplitStep({ step, update, templates, channels }) {
    const variants = step.variants ?? [];
    const setVariant = (index, patch) => update('variants', variants.map((variant, position) => position === index ? { ...variant, ...patch } : variant));
    const addVariant = () => { const key = String.fromCharCode(65 + variants.length); update('variants', [...variants, freshVariant(key, templates, channels)]); };
    const removeVariant = (index) => update('variants', variants.filter((_, position) => position !== index).map((variant, position) => ({ ...variant, key: String.fromCharCode(65 + position) })));
    const totalWeight = variants.reduce((sum, variant) => sum + (Number(variant.weight) || 0), 0) || 1;

    return <div className="space-y-4">
        <p className="text-xs leading-5 text-slate-500">Randomly split each person across message variants (deterministic per person), then continue to the next step. Weights set the traffic share.</p>
        {variants.map((variant, index) => {
            const type = variant.type ?? 'email';
            const share = Math.round(((Number(variant.weight) || 0) / totalWeight) * 100);
            return <div key={index} className="rounded-lg border border-white/10 bg-slate-950/50 p-4">
                <div className="mb-3 flex items-center justify-between"><span className="rounded-md bg-emerald-400/15 px-2 py-0.5 text-xs font-semibold text-emerald-200">Variant {variant.key}</span><div className="flex items-center gap-2 text-xs text-slate-500"><span>{share}% of traffic</span>{variants.length > 2 && <button type="button" className="rounded-md border border-white/10 px-2 py-0.5 text-slate-400 hover:bg-white/10" onClick={() => removeVariant(index)}>Remove</button>}</div></div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-xs uppercase tracking-wider text-slate-500">Weight<input type="number" min="1" max="100" className={inputClass} value={variant.weight ?? 50} onChange={(e) => setVariant(index, { weight: Number(e.target.value) })} /></label>
                    <label className="text-xs uppercase tracking-wider text-slate-500">Channel<select className={inputClass} value={type} onChange={(e) => setVariant(index, freshVariant(variant.key, templates, channels, e.target.value))}>{['email', 'sms', 'push'].filter((t) => canSend(t, templates, channels)).map((t) => <option key={t} value={t}>{t}</option>)}</select></label>
                    <label className="text-xs uppercase tracking-wider text-slate-500">Template<select className={inputClass} value={variant.template_id ?? ''} onChange={(e) => setVariant(index, { template_id: Number(e.target.value) })}>{templates.filter((item) => item.channel === type).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                    <label className="text-xs uppercase tracking-wider text-slate-500">Channel provider<select className={inputClass} value={variant.channel_id ?? ''} onChange={(e) => setVariant(index, { channel_id: Number(e.target.value) })}>{channels.filter((item) => item.type === type).map((item) => <option key={item.id} value={item.id}>{item.name} ({item.driver})</option>)}</select></label>
                </div>
            </div>;
        })}
        {variants.length < 4 && <button type="button" className={secondaryButtonClass} onClick={addVariant}>+ Add variant</button>}
    </div>;
}

function AbResults({ abTests }) {
    return <section className={panelClass}>
        <h2 className="font-semibold">A/B results</h2>
        <p className="mt-1 text-xs leading-5 text-slate-500">Live for the published version. Conversion is {abTests[0].goal_based ? 'the automation goal' : 'completing the journey'}.</p>
        {abTests.map((test) => <div key={test.node_id} className="mt-4 space-y-3">{test.variants.map((variant) => {
            const best = Math.max(...test.variants.map((item) => item.rate));
            const leading = best > 0 && variant.rate === best && variant.entered > 0;
            return <div key={variant.key} className="rounded-lg border border-white/10 bg-slate-950/40 p-3">
                <div className="flex items-center justify-between text-sm"><span className="font-medium">Variant {variant.key}{leading && <span className="ml-2 rounded bg-emerald-400/15 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-200">leading</span>}</span><span className="text-slate-300">{variant.rate}%</span></div>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/10"><div className="h-full rounded-full bg-emerald-400" style={{ width: `${Math.min(100, variant.rate)}%` }} /></div>
                <div className="mt-2 text-[11px] text-slate-500">{variant.converted} / {variant.entered} converted · {variant.type}</div>
            </div>;
        })}</div>)}
    </section>;
}

function SendStep({ step, update, templates, channels }) {
    const type = step.type.replace('send_', '');
    return <div className="grid gap-4 sm:grid-cols-2"><label className="text-xs uppercase tracking-wider text-slate-500">Template<select className={inputClass} value={step.template_id ?? ''} onChange={(e) => update('template_id', Number(e.target.value))}>{templates.filter((item) => item.channel === type).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label className="text-xs uppercase tracking-wider text-slate-500">Channel<select className={inputClass} value={step.channel_id ?? ''} onChange={(e) => update('channel_id', Number(e.target.value))}>{channels.filter((item) => item.type === type).map((item) => <option key={item.id} value={item.id}>{item.name} ({item.driver})</option>)}</select></label><label className="text-xs uppercase tracking-wider text-slate-500">Attempts<input type="number" min="1" max="10" className={inputClass} value={step.retry_attempts ?? 3} onChange={(e) => update('retry_attempts', Number(e.target.value))} /></label><label className="text-xs uppercase tracking-wider text-slate-500">After final failure<select className={inputClass} value={step.on_failure ?? 'continue'} onChange={(e) => update('on_failure', e.target.value)}><option value="continue">Continue run</option><option value="fail">Fail run</option></select></label></div>;
}

function TinyButton({ children, ...props }) {
    return <button type="button" className="rounded-md border border-white/10 px-2 py-1 text-xs text-slate-400 hover:bg-white/10 disabled:opacity-30" {...props}>{children}</button>;
}

function buildFlow(steps, triggerName, events, goal) {
    const mainStyle = { background: '#111827', color: '#e2e8f0', border: '1px solid #334155', width: 220 };
    const nodes = [{ id: 'trigger', position: { x: 60, y: 20 }, data: { label: `Trigger · ${triggerName}` }, draggable: false, style: { background: '#064e3b', color: '#d1fae5', border: '1px solid #34d399', width: 220 } }];
    if (goal.enabled) {
        const goalName = events.find((event) => Number(event.id) === Number(goal.event_id))?.name ?? 'event';
        nodes.push({ id: 'goal', position: { x: 360, y: 20 }, data: { label: `Global goal · ${goalName}` }, draggable: false, style: { background: '#2e1065', color: '#ede9fe', border: '1px dashed #a78bfa', width: 220 } });
    }
    let y = 120;

    steps.forEach((step, index) => {
        const eventName = events.find((event) => Number(event.id) === Number(step.event_id))?.name;
        const label = step.type === 'wait_for_event' ? `${index + 1}. Wait · ${eventName ?? 'event'}` : step.type === 'split' ? `${index + 1}. A/B test` : `${index + 1}. ${step.type.replaceAll('_', ' ')}`;
        const style = step.type === 'wait_for_event' ? { ...mainStyle, border: '1px solid #38bdf8', background: '#082f49' } : step.type === 'split' ? { ...mainStyle, border: '1px solid #34d399', background: '#053225' } : mainStyle;
        nodes.push({ id: `step-${index}`, position: { x: 60, y }, data: { label }, style });
        if (step.type === 'split') {
            (step.variants ?? []).forEach((variant, vIndex) => {
                nodes.push({ id: `ab-${index}-${variant.key}`, position: { x: 360, y: y + vIndex * 68 }, data: { label: `Variant ${variant.key} · ${variant.type ?? 'email'}` }, draggable: false, style: { ...mainStyle, width: 200, border: '1px solid #34d399', background: '#04231b' } });
            });
        }
        y += step.type === 'split' ? Math.max(120, (step.variants?.length ?? 2) * 68 + 24) : (step.type === 'wait_for_event' && step.timeout_action !== 'continue' ? 150 : 100);
    });

    nodes.push({ id: 'exit', position: { x: 60, y }, data: { label: 'Exit' }, draggable: false, style: { background: '#1e293b', color: '#cbd5e1', border: '1px solid #475569', width: 220 } });
    const edges = [];

    if (steps.length === 0) {
        edges.push({ id: 'trigger-exit', source: 'trigger', target: 'exit', animated: true });
        return { nodes, edges };
    }

    edges.push({ id: 'trigger-first', source: 'trigger', target: 'step-0', animated: true });
    steps.forEach((step, index) => {
        const source = `step-${index}`;
        const next = index + 1 < steps.length ? `step-${index + 1}` : 'exit';

        if (step.type === 'split') {
            (step.variants ?? []).forEach((variant) => {
                const variantNode = `ab-${index}-${variant.key}`;
                edges.push({ id: `${source}-${variant.key}`, source, target: variantNode, label: variant.key, animated: true, style: { stroke: '#34d399' }, labelStyle: { fill: '#6ee7b7' } });
                edges.push({ id: `${variantNode}-next`, source: variantNode, target: next, style: { stroke: '#34d399' } });
            });
            return;
        }

        if (step.type !== 'wait_for_event') {
            edges.push({ id: `${source}-next`, source, target: next, animated: true });
            return;
        }

        edges.push({ id: `${source}-matched`, source, target: next, label: 'matched', animated: true, style: { stroke: '#34d399' }, labelStyle: { fill: '#6ee7b7' } });
        if (step.timeout_action === 'continue') {
            edges.push({ id: `${source}-timeout`, source, target: next, label: 'timed out', style: { stroke: '#f59e0b' }, labelStyle: { fill: '#fbbf24' } });
            return;
        }

        const waitNode = nodes.find((node) => node.id === source);
        const timeoutId = `timeout-${index}`;
        const timeoutLabel = step.timeout_action === 'exit' ? 'Timeout · stop run' : `Timeout · ${step.timeout_action.replaceAll('_', ' ')}`;
        nodes.push({ id: timeoutId, position: { x: 360, y: waitNode.position.y }, data: { label: timeoutLabel }, draggable: false, style: { background: '#451a03', color: '#fde68a', border: '1px solid #f59e0b', width: 220 } });
        edges.push({ id: `${source}-timeout`, source, target: timeoutId, label: 'timed out', style: { stroke: '#f59e0b' }, labelStyle: { fill: '#fbbf24' } });
        if (step.timeout_action !== 'exit') edges.push({ id: `${timeoutId}-next`, source: timeoutId, target: next, style: { stroke: '#f59e0b' } });
    });

    return { nodes, edges };
}
