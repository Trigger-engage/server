import { Link, router } from '@inertiajs/react';
import { panelClass } from './Layout';
import { engagePath } from '../lib/engagePath';

const CHANNEL_LABELS = { email: 'emails', sms: 'text messages', push: 'push notifications' };
const REASONS = { unsubscribe: 'Unsubscribed', manual: 'Unsubscribed by your team', bounce: 'Address bounced', complaint: 'Reported as spam' };

// The person's channel and topic subscriptions, each with a switch. Lifting a
// bounce or a spam complaint asks first: those protect the sender reputation.
export default function PersonSubscriptions({ person, subscriptions }) {
    const change = (scope, target, subscribed, warning) => {
        if (warning && !window.confirm(warning)) return;
        router.post(engagePath(`people/${person.id}/subscriptions`), { scope, target, subscribed }, { preserveScroll: true });
    };

    return <section className={panelClass}>
        <div className="flex items-center justify-between gap-3">
            <h2 className="font-semibold">Subscriptions</h2>
            <Link href={engagePath('subscriptions')} className="text-xs font-medium text-emerald-300">Topics →</Link>
        </div>
        <ul className="mt-4 divide-y divide-white/[0.07] text-sm">
            {Object.entries(subscriptions.channels).map(([channel, state]) => {
                const risky = ['bounce', 'complaint'].includes(state.reason);
                return <li key={channel} className="flex items-center justify-between gap-3 py-3">
                    <span>
                        <span className="text-slate-200">All {CHANNEL_LABELS[channel]}</span>
                        <span className={`block text-xs ${state.subscribed ? 'text-slate-400' : 'text-amber-200'}`}>
                            {state.subscribed ? (state.reachable ? 'Subscribed' : 'Subscribed · no address yet') : `${REASONS[state.reason] ?? 'Unsubscribed'}${state.since ? ` · ${new Date(state.since).toLocaleDateString()}` : ''}`}
                        </span>
                    </span>
                    <Toggle on={state.subscribed} label={`${state.subscribed ? 'Unsubscribe from' : 'Resubscribe to'} all ${CHANNEL_LABELS[channel]}`}
                        onClick={() => change('channel', channel, !state.subscribed, !state.subscribed && risky
                            ? `This ${channel} address is suppressed because it ${state.reason === 'bounce' ? 'bounced' : 'was reported as spam'}. Sending to it again can hurt delivery for everyone. Resubscribe anyway?`
                            : null)} />
                </li>;
            })}
            {subscriptions.topics.map((topic) => <li key={topic.id} className="flex items-center justify-between gap-3 py-3">
                <span className="min-w-0">
                    <span className="text-slate-200">{topic.name}</span>
                    <span className="block text-xs text-slate-400">
                        {topic.subscribed ? 'Subscribed' : 'Opted out'}
                        {topic.decided_by === 'default' && ' · topic default'}
                        {topic.decided_by === 'property' && <> · from <code>{topic.property}</code></>}
                    </span>
                </span>
                <Toggle on={topic.subscribed} label={`${topic.subscribed ? 'Unsubscribe from' : 'Subscribe to'} ${topic.name}`}
                    onClick={() => change('topic', topic.key, !topic.subscribed)} />
            </li>)}
        </ul>
        {subscriptions.topics.length === 0 && <p className="mt-2 text-xs text-slate-400">No topics yet. Create them on the Subscriptions page.</p>}
    </section>;
}

function Toggle({ on, label, onClick }) {
    return <button type="button" role="switch" aria-checked={on} aria-label={label} onClick={onClick}
        className={`relative h-6 w-11 shrink-0 rounded-full transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400/60 ${on ? 'bg-emerald-400' : 'bg-white/15'}`}>
        <span className={`absolute top-0.5 size-5 rounded-full bg-white shadow transition-all ${on ? 'left-[22px]' : 'left-0.5'}`} />
    </button>;
}
