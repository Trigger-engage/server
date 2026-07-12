import { Link, useForm } from '@inertiajs/react';
import Layout, { FieldError, PageHeader, buttonClass, inputClass, panelClass, secondaryButtonClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

const TYPE_LABELS = {
    all: 'Default audience',
    manual: 'Manual membership',
    event: 'Event-driven',
    rule: 'Rule-based',
};

export default function Show({ workspace, segment, members, availablePeople, filters }) {
    const settings = useForm({ name: segment.name, description: segment.description ?? '' });
    const membership = useForm({});
    const deletion = useForm({});

    const saveSettings = (event) => {
        event.preventDefault();
        settings.put(engagePath(`segments/${segment.id}`), { preserveScroll: true });
    };

    const addPerson = (person) => membership.post(engagePath(`segments/${segment.id}/people/${person.id}`), { preserveScroll: true });
    const removePerson = (person) => {
        if (window.confirm(`Remove ${personLabel(person)} from “${segment.name}”?`)) {
            membership.delete(engagePath(`segments/${segment.id}/people/${person.id}`), { preserveScroll: true });
        }
    };

    const deleteSegment = () => {
        if (window.confirm(`Delete “${segment.name}”? Its membership will be removed permanently.`)) {
            deletion.delete(engagePath(`segments/${segment.id}`));
        }
    };

    return (
        <Layout title={`Segment · ${segment.name}`} workspace={workspace}>
            <Link href={engagePath('segments')} className="text-sm text-slate-400 hover:text-white">← Back to segments</Link>
            <div className="mt-4">
                <PageHeader
                    eyebrow="Audience management"
                    title={segment.name}
                    description={segment.description || membershipDescription(segment)}
                    action={<div className="flex items-center gap-2"><span className="rounded-full bg-violet-400/10 px-3 py-1 text-xs font-medium text-violet-200">{TYPE_LABELS[segment.type]}</span><span className="rounded-full bg-emerald-400/10 px-3 py-1 text-xs font-medium text-emerald-300">{segment.people_count} people</span></div>}
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,.7fr)]">
                <div className="space-y-6">
                    {segment.editable_membership && (
                        <section className={panelClass}>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div><h2 className="font-semibold">Add people</h2><p className="mt-1 text-sm text-slate-500">Search this workspace and add profiles to the audience.</p></div>
                                <form method="get" action={engagePath(`segments/${segment.id}`)} className="flex w-full gap-2 sm:max-w-md">
                                    <input type="hidden" name="search" value={filters.search} />
                                    <input aria-label="Find people to add" name="add_search" defaultValue={filters.add_search} className={`${inputClass} mt-0`} placeholder="Name, ID, email, phone…" />
                                    <button className={secondaryButtonClass}>Search</button>
                                </form>
                            </div>
                            <FieldError message={membership.errors.segment} />
                            <div className="mt-5 grid gap-3 md:grid-cols-2">
                                {availablePeople.length === 0
                                    ? <p className="md:col-span-2 rounded-xl border border-dashed border-white/10 px-4 py-8 text-center text-sm text-slate-500">No matching profiles are available to add.</p>
                                    : availablePeople.map((person) => (
                                        <div key={person.id} className="flex items-center justify-between gap-3 rounded-xl border border-white/[0.07] bg-slate-950/30 p-3">
                                            <PersonIdentity person={person} />
                                            <button type="button" className={secondaryButtonClass} disabled={membership.processing} onClick={() => addPerson(person)}>Add</button>
                                        </div>
                                    ))}
                            </div>
                        </section>
                    )}

                    {!segment.editable_membership && (
                        <div className="rounded-2xl border border-violet-400/15 bg-violet-400/[0.05] px-5 py-4 text-sm text-violet-100">
                            <span className="font-semibold">Membership is automatic.</span> {membershipDescription(segment)} You can view people here, but manual additions and removals are disabled.
                        </div>
                    )}

                    <section className={panelClass}>
                        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                            <div><h2 className="font-semibold">Segment members</h2><p className="mt-1 text-sm text-slate-500">{members.total} profiles currently belong to this audience.</p></div>
                            <form method="get" action={engagePath(`segments/${segment.id}`)} className="flex w-full gap-2 sm:max-w-md">
                                <input type="hidden" name="add_search" value={filters.add_search} />
                                <input aria-label="Search segment members" name="search" defaultValue={filters.search} className={`${inputClass} mt-0`} placeholder="Search members…" />
                                <button className={secondaryButtonClass}>Search</button>
                            </form>
                        </div>
                        <div className="mt-5 divide-y divide-white/[0.07]">
                            {members.data.length === 0
                                ? <p className="py-10 text-center text-sm text-slate-500">No members match this search.</p>
                                : members.data.map((person) => (
                                    <div key={person.id} className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-white/[0.05] text-xs font-bold text-slate-300">{personInitials(person)}</span>
                                            <div className="min-w-0"><Link href={engagePath(`people/${person.id}`)} className="truncate text-sm font-medium text-slate-100 hover:text-emerald-300">{personLabel(person)}</Link><p className="mt-0.5 truncate text-xs text-slate-500">{person.email || person.phone || 'No contact information'} · joined via {person.pivot.source}</p></div>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-xs text-slate-600">{formatDate(person.pivot.added_at)}</span>
                                            {segment.editable_membership && <button type="button" className="rounded-lg px-3 py-1.5 text-xs font-medium text-rose-300 hover:bg-rose-400/10" disabled={membership.processing} onClick={() => removePerson(person)}>Remove</button>}
                                        </div>
                                    </div>
                                ))}
                        </div>
                        {(members.prev_page_url || members.next_page_url) && <div className="mt-5 flex items-center justify-between border-t border-white/[0.07] pt-4"><span className="text-xs text-slate-500">Page {members.current_page} of {members.last_page}</span><div className="flex gap-2">{members.prev_page_url && <Link className={secondaryButtonClass} href={members.prev_page_url}>Previous</Link>}{members.next_page_url && <Link className={secondaryButtonClass} href={members.next_page_url}>Next</Link>}</div></div>}
                    </section>
                </div>

                <aside className="space-y-6">
                    <section className={panelClass}>
                        <h2 className="font-semibold">Segment settings</h2>
                        {segment.protected ? (
                            <div className="mt-4 rounded-xl border border-emerald-400/15 bg-emerald-400/[0.05] p-4 text-sm leading-6 text-emerald-100">All people is a protected workspace audience. Its name, membership, and lifecycle are managed by Trigger Engage.</div>
                        ) : (
                            <form onSubmit={saveSettings} className="mt-5 space-y-4">
                                <Field label="Name"><input className={inputClass} value={settings.data.name} onChange={(event) => settings.setData('name', event.target.value)} /><FieldError message={settings.errors.name} /></Field>
                                <Field label="Description"><textarea rows="4" className={inputClass} value={settings.data.description} onChange={(event) => settings.setData('description', event.target.value)} placeholder="Who belongs in this audience?" /><FieldError message={settings.errors.description} /></Field>
                                <button className={buttonClass} disabled={settings.processing}>{settings.processing ? 'Saving…' : 'Save changes'}</button>
                            </form>
                        )}
                    </section>

                    <section className={panelClass}>
                        <h2 className="font-semibold">Details</h2>
                        <dl className="mt-4 space-y-3 text-sm"><Detail label="Type" value={TYPE_LABELS[segment.type]} /><Detail label="Public ID" value={segment.public_id} mono /><Detail label="Broadcasts" value={segment.broadcasts_count} /><Detail label="Created" value={formatDate(segment.created_at)} />{segment.event && <Detail label="Trigger event" value={segment.event.name} />}</dl>
                    </section>

                    {!segment.protected && (
                        <section className="rounded-2xl border border-rose-400/15 bg-rose-400/[0.035] p-6">
                            <h2 className="font-semibold text-rose-200">Delete segment</h2>
                            <p className="mt-2 text-sm leading-6 text-slate-500">Deleting removes its membership permanently. Segments used in broadcast history are retained for reporting.</p>
                            <FieldError message={deletion.errors.segment} />
                            <button type="button" className="mt-4 rounded-lg border border-rose-400/25 px-4 py-2 text-sm font-semibold text-rose-300 hover:bg-rose-400/10 disabled:cursor-not-allowed disabled:opacity-50" disabled={deletion.processing || segment.broadcasts_count > 0} onClick={deleteSegment}>{segment.broadcasts_count > 0 ? 'Used by broadcast history' : deletion.processing ? 'Deleting…' : 'Delete segment'}</button>
                        </section>
                    )}
                </aside>
            </div>
        </Layout>
    );
}

function membershipDescription(segment) {
    if (segment.type === 'all') return 'Every profile in the workspace is included automatically.';
    if (segment.type === 'event') return `People join when ${segment.event?.name ?? 'the selected event'} fires.`;
    if (segment.type === 'rule') return 'People join and leave as their properties and behaviour match the saved rules.';
    return 'Membership is managed manually.';
}

function personLabel(person) {
    return person.external_id || (person.anonymous_id ? `Anonymous · ${person.anonymous_id}` : `Profile ${person.id}`);
}

function personInitials(person) {
    const label = person.external_id || person.anonymous_id || '?';
    return label.slice(0, 2).toUpperCase();
}

function formatDate(value) {
    return value ? new Date(value).toLocaleDateString() : '—';
}

function PersonIdentity({ person }) {
    return <div className="min-w-0"><div className="truncate text-sm font-medium">{personLabel(person)}</div><div className="mt-0.5 truncate text-xs text-slate-500">{person.email || person.phone || 'No contact information'}</div></div>;
}

function Field({ label, children }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{label}{children}</label>;
}

function Detail({ label, value, mono = false }) {
    return <div className="flex items-start justify-between gap-4"><dt className="text-slate-500">{label}</dt><dd className={`max-w-[65%] break-all text-right text-slate-300 ${mono ? 'font-mono text-xs' : ''}`}>{value}</dd></div>;
}
