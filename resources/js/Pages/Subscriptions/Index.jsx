import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Layout, { EmptyState, FieldError, PageHeader, buttonClass, inputClass, panelClass, secondaryButtonClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

const CHANNEL_NOUNS = { email: 'emails', sms: 'text messages', push: 'push notifications' };

function slug(value) {
    return value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 64);
}

export default function Index({ workspace, topics, people_count: peopleCount, unsubscribed_channels: unsubscribedChannels }) {
    const [editing, setEditing] = useState(null);

    return <Layout title="Subscriptions" workspace={workspace}>
        <PageHeader eyebrow="Audience" title="Subscriptions"
            description="Topics let people opt out of one kind of message without unsubscribing from everything. Pick a topic on a template or broadcast, and people who opted out of it are skipped." />

        <section className={panelClass}>
            <h2 className="font-semibold">Off a whole channel</h2>
            <p className="mt-1 text-sm text-slate-400">Unsubscribed, bounced or reported as spam: nothing on that channel reaches them, whatever its topic.</p>
            <div className="mt-4 grid gap-3 sm:grid-cols-3">
                {Object.entries(unsubscribedChannels).map(([channel, count]) => <div key={channel} className="rounded-xl bg-white/[0.035] p-4">
                    <div className="text-2xl font-bold">{count}</div>
                    <div className="mt-1 text-xs text-slate-400">of {peopleCount} {peopleCount === 1 ? 'person gets' : 'people get'} no {CHANNEL_NOUNS[channel]}</div>
                </div>)}
            </div>
        </section>

        <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(320px,.8fr)]">
            <section className={panelClass}>
                <h2 className="font-semibold">Topics</h2>
                <div className="mt-4 divide-y divide-white/[0.07]">
                    {topics.length === 0
                        ? <EmptyState title="No topics yet" description="Create one for each kind of message people may want to stop on its own, such as product news or reminders." />
                        : topics.map((topic) => editing === topic.id
                            ? <div key={topic.id} className="py-5"><TopicForm topic={topic} onDone={() => setEditing(null)} /></div>
                            : <TopicRow key={topic.id} topic={topic} onEdit={() => setEditing(topic.id)} />)}
                </div>
            </section>

            <section className={`${panelClass} self-start`}>
                <h2 className="font-semibold">New topic</h2>
                <div className="mt-4"><TopicForm /></div>
            </section>
        </div>
    </Layout>;
}

function TopicRow({ topic, onEdit }) {
    const remove = () => {
        const used = topic.templates.length ? ` ${topic.templates.length} ${topic.templates.length === 1 ? 'template' : 'templates'} will stop belonging to a topic.` : '';
        if (window.confirm(`Delete the “${topic.name}” topic?${used} Everyone's choices for it are deleted too.`)) {
            router.delete(engagePath(`subscriptions/${topic.id}`), { preserveScroll: true });
        }
    };

    return <div className="flex flex-col gap-4 py-5 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="font-medium text-slate-100">{topic.name}</h3>
                <code className="rounded bg-white/5 px-1.5 py-0.5 text-xs text-slate-400">{topic.key}</code>
                <span className={`rounded-full px-2 py-0.5 text-xs ${topic.default_subscribed ? 'bg-emerald-400/10 text-emerald-300' : 'bg-sky-400/10 text-sky-200'}`}>
                    {topic.default_subscribed ? 'Everyone until they opt out' : 'Opt-in only'}
                </span>
            </div>
            {topic.description && <p className="mt-1 text-sm text-slate-400">{topic.description}</p>}
            {topic.property && <p className="mt-2 text-xs text-slate-400">Follows the <code className="text-slate-300">{topic.property}</code> property: true is subscribed, false is not.</p>}
            <p className="mt-2 text-xs text-slate-400">
                {topic.unsubscribed_count} {topic.unsubscribed_count === 1 ? 'person' : 'people'} {topic.default_subscribed ? 'opted out' : 'not opted in'}
                {' · '}
                {topic.templates.length === 0
                    ? 'No templates use it yet'
                    : <>Used by {topic.templates.map((template, index) => <span key={template.id}>{index > 0 && ', '}<Link href={engagePath(`templates/${template.id}/edit`)} className="text-emerald-300 hover:text-emerald-200">{template.name}</Link></span>)}</>}
            </p>
        </div>
        <div className="flex shrink-0 gap-2">
            <button type="button" className={secondaryButtonClass} onClick={onEdit}>Edit</button>
            <button type="button" className="rounded-lg px-3 py-2 text-sm text-rose-300 hover:bg-rose-400/10" onClick={remove}>Delete</button>
        </div>
    </div>;
}

function TopicForm({ topic = null, onDone }) {
    const form = useForm({
        name: topic?.name ?? '',
        key: topic?.key ?? '',
        description: topic?.description ?? '',
        default_subscribed: topic?.default_subscribed ?? true,
        property: topic?.property ?? '',
    });
    const [keyTouched, setKeyTouched] = useState(Boolean(topic));

    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { if (topic) onDone?.(); else { form.reset(); setKeyTouched(false); } } };
        topic ? form.put(engagePath(`subscriptions/${topic.id}`), options) : form.post(engagePath('subscriptions'), options);
    };

    return <form onSubmit={submit} className="space-y-4">
        <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Name
            <input className={inputClass} value={form.data.name} placeholder="Care reminders"
                onChange={(event) => form.setData({ ...form.data, name: event.target.value, key: keyTouched ? form.data.key : slug(event.target.value) })} />
            <FieldError message={form.errors.name} />
        </label>
        <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Key
            <input className={`${inputClass} font-mono`} value={form.data.key} placeholder="care_reminders"
                onChange={(event) => { setKeyTouched(true); form.setData('key', event.target.value); }} />
            <span className="mt-1 block normal-case tracking-normal text-slate-400">How your app names this topic in the API.</span>
            <FieldError message={form.errors.key} />
        </label>
        <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Description
            <input className={inputClass} value={form.data.description} placeholder="Reminders about upcoming sessions and check-ins"
                onChange={(event) => form.setData('description', event.target.value)} />
            <span className="mt-1 block normal-case tracking-normal text-slate-400">Shown to people on their preference page.</span>
            <FieldError message={form.errors.description} />
        </label>
        <fieldset>
            <legend className="text-xs font-medium uppercase tracking-wider text-slate-400">Who gets it</legend>
            <div className="mt-2 space-y-2 text-sm text-slate-200">
                <label className="flex items-start gap-2"><input type="radio" className="mt-1" checked={form.data.default_subscribed} onChange={() => form.setData('default_subscribed', true)} /><span>Everyone, until they opt out</span></label>
                <label className="flex items-start gap-2"><input type="radio" className="mt-1" checked={!form.data.default_subscribed} onChange={() => form.setData('default_subscribed', false)} /><span>Only people who opt in</span></label>
            </div>
        </fieldset>
        <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Follow a profile property (optional)
            <input className={`${inputClass} font-mono`} value={form.data.property} placeholder="email_communication"
                onChange={(event) => form.setData('property', event.target.value)} />
            <span className="mt-1 block normal-case leading-5 tracking-normal text-slate-400">If your app already stores this choice as a true/false property, name it here and it decides. Choices made on the preference page write it back; listen for the SubscriptionChanged event so your app saves them too.</span>
            <FieldError message={form.errors.property} />
        </label>
        <div className="flex gap-2">
            <button className={buttonClass} disabled={form.processing}>{topic ? 'Save topic' : 'Create topic'}</button>
            {topic && <button type="button" className={secondaryButtonClass} onClick={onDone}>Cancel</button>}
        </div>
    </form>;
}
