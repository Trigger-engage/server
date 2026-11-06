import { Link } from '@inertiajs/react';
import { FieldError, inputClass } from './Layout';
import { engagePath } from '../lib/engagePath';

// The topic a template or broadcast belongs to. People who opted out of it
// are skipped; with no topic, only whole-channel unsubscribes apply.
export default function SubscriptionTopicField({ topics, value, onChange, error, disabled = false }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">Subscription topic
        <select className={inputClass} value={value ?? ''} disabled={disabled} onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))}>
            <option value="">No topic (only whole-channel unsubscribes apply)</option>
            {topics.map((topic) => <option key={topic.id} value={topic.id}>{topic.name}</option>)}
        </select>
        <span className="mt-1 block normal-case leading-5 tracking-normal text-slate-400">
            People who opted out of this topic won't get it. {topics.length === 0 && <Link href={engagePath('subscriptions')} className="text-emerald-300 hover:text-emerald-200">Create a topic →</Link>}
        </span>
        <FieldError message={error} />
    </label>;
}
