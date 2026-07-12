import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { engagePath } from '../lib/engagePath';

const navigation = [
    { label: 'Overview', href: engagePath(), icon: 'overview', exact: true },
    { label: 'Analytics', href: engagePath('analytics'), icon: 'analytics' },
    { label: 'People', href: engagePath('people'), icon: 'people' },
    { label: 'Automations', href: engagePath('automations'), icon: 'automations' },
    { label: 'Events', href: engagePath('events'), icon: 'events' },
    { label: 'Segments', href: engagePath('segments'), icon: 'segments' },
    { label: 'Templates', href: engagePath('templates'), icon: 'templates' },
    { label: 'Channels', href: engagePath('channels'), icon: 'channels' },
    { label: 'Broadcasts', href: engagePath('broadcasts'), icon: 'broadcasts' },
    { label: 'Runs', href: engagePath('runs'), icon: 'runs' },
];

export default function Layout({ title, workspace, children }) {
    const { flash } = usePage().props;
    const url = usePage().url.split('?')[0];
    const [mobileOpen, setMobileOpen] = useState(false);
    const active = (item) => item.exact ? url === item.href : url === item.href || url.startsWith(`${item.href}/`);

    return <div className="min-h-screen bg-[#080d19] text-slate-100">
        <Head title={title} />

        {mobileOpen && <button type="button" aria-label="Close navigation" className="fixed inset-0 z-40 bg-slate-950/75 backdrop-blur-sm lg:hidden" onClick={() => setMobileOpen(false)} />}

        <aside className={`fixed inset-y-0 left-0 z-50 w-64 flex-col border-r border-white/[0.07] bg-[#0b1120] lg:flex lg:translate-x-0 ${mobileOpen ? 'flex translate-x-0' : 'hidden -translate-x-full'}`}>
            <div className="flex h-[72px] items-center gap-3 border-b border-white/[0.07] px-5">
                <span className="grid size-9 place-items-center rounded-xl bg-emerald-400 font-black text-slate-950 shadow-lg shadow-emerald-400/10">TE</span>
                <span><span className="block font-semibold tracking-tight">Trigger Engage</span><span className="block text-[11px] text-slate-500">Messaging automation</span></span>
                <button type="button" aria-label="Close sidebar" className="ml-auto rounded-lg p-2 text-slate-500 hover:bg-white/5 lg:hidden" onClick={() => setMobileOpen(false)}>×</button>
            </div>

            <nav className="flex-1 overflow-y-auto px-3 py-5">
                <p className="px-3 pb-2 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-600">Workspace</p>
                <div className="space-y-1">{navigation.map((item) => <Link key={item.href} href={item.href} onClick={() => setMobileOpen(false)} className={`group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${active(item) ? 'bg-emerald-400/10 text-emerald-300 ring-1 ring-inset ring-emerald-400/10' : 'text-slate-400 hover:bg-white/[0.04] hover:text-slate-100'}`}><NavIcon name={item.icon} active={active(item)} /><span>{item.label}</span>{active(item) && <span className="ml-auto size-1.5 rounded-full bg-emerald-400" />}</Link>)}</div>
            </nav>

            <div className="border-t border-white/[0.07] p-3">
                <div className="rounded-xl border border-white/[0.07] bg-white/[0.025] p-3"><div className="flex items-center gap-2"><span className="grid size-8 place-items-center rounded-lg bg-violet-400/15 text-xs font-bold text-violet-200">{workspace.name.slice(0, 2).toUpperCase()}</span><div className="min-w-0"><div className="truncate text-sm font-medium text-slate-200">{workspace.name}</div><div className="truncate text-[11px] text-slate-600">{workspace.public_id}</div></div></div><div className="mt-3 flex items-center justify-between border-t border-white/[0.06] pt-2 text-[11px] text-slate-500"><span>{workspace.timezone}</span><span className="flex items-center gap-1.5 text-emerald-400"><span className="size-1.5 rounded-full bg-emerald-400" />Active</span></div></div>
            </div>
        </aside>

        <div className="lg:pl-64">
            <header className="sticky top-0 z-30 flex h-[72px] items-center border-b border-white/[0.07] bg-[#080d19]/90 px-4 backdrop-blur-xl sm:px-6 lg:px-8">
                <button type="button" aria-label="Open navigation" className="mr-3 rounded-lg border border-white/10 p-2 text-slate-400 hover:bg-white/5 lg:hidden" onClick={() => setMobileOpen(true)}><NavIcon name="menu" /></button>
                <div><div className="text-sm font-semibold text-slate-200">{title}</div><div className="mt-0.5 hidden text-xs text-slate-600 sm:block">{workspace.name}</div></div>
                <div className="ml-auto flex items-center gap-3"><span className="hidden rounded-full border border-emerald-400/15 bg-emerald-400/5 px-2.5 py-1 text-[11px] font-medium text-emerald-300 sm:inline-flex">Production ready</span><span className="grid size-8 place-items-center rounded-full border border-white/10 bg-white/5 text-xs font-semibold text-slate-300">{workspace.name.charAt(0).toUpperCase()}</span></div>
            </header>

            <main className="mx-auto max-w-[1500px] px-4 py-7 sm:px-6 lg:px-8 lg:py-9">
                {flash?.success && <div className="mb-6 rounded-xl border border-emerald-400/25 bg-emerald-400/8 px-4 py-3 text-sm text-emerald-200">{flash.success}</div>}
                {flash?.error && <div className="mb-6 rounded-xl border border-rose-400/25 bg-rose-400/8 px-4 py-3 text-sm text-rose-200">{flash.error}</div>}
                {children}
            </main>
        </div>
    </div>;
}

function NavIcon({ name, active = false }) {
    const paths = {
        overview: <><rect x="3" y="3" width="7" height="7" rx="2" /><rect x="14" y="3" width="7" height="7" rx="2" /><rect x="3" y="14" width="7" height="7" rx="2" /><rect x="14" y="14" width="7" height="7" rx="2" /></>,
        analytics: <><path d="M3 3v18h18" /><path d="M7 15l3-4 3 3 5-6" /></>,
        people: <><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0 1 16 0" /></>,
        automations: <><path d="M4 6h10" /><path d="M18 6h2" /><path d="M10 12h10" /><path d="M4 12h2" /><path d="M4 18h10" /><path d="M18 18h2" /><circle cx="16" cy="6" r="2" /><circle cx="8" cy="12" r="2" /><circle cx="16" cy="18" r="2" /></>,
        events: <><path d="M13 2 4.1 12.7a1 1 0 0 0 .8 1.6H11l-1 7.7 8.9-10.7a1 1 0 0 0-.8-1.6H12L13 2Z" /></>,
        segments: <><circle cx="8" cy="8" r="3" /><circle cx="17" cy="7" r="2" /><path d="M2.5 20a5.5 5.5 0 0 1 11 0M13 15.5a4.5 4.5 0 0 1 8.5 2" /></>,
        templates: <><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z" /><path d="M14 2v6h6" /><path d="M8 13h8M8 17h6" /></>,
        channels: <><path d="M4 4h16v12H5.2L4 17.2V4Z" /><path d="m7 8 5 3 5-3" /><path d="M8 20h8" /></>,
        broadcasts: <><path d="m3 11 16-7v16L3 13v-2Z" /><path d="M7 14.8V20h4v-3.5" /></>,
        runs: <><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></>,
        menu: <><path d="M4 7h16M4 12h16M4 17h16" /></>,
    };
    return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" className={`size-5 shrink-0 ${active ? 'text-emerald-300' : 'text-slate-500'}`}>{paths[name]}</svg>;
}

export function PageHeader({ eyebrow, title, description, action }) {
    return <div className="mb-7 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div>{eyebrow && <p className="text-xs font-semibold uppercase tracking-[0.16em] text-emerald-400">{eyebrow}</p>}<h1 className="mt-1 text-2xl font-bold tracking-tight text-white sm:text-3xl">{title}</h1>{description && <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-400">{description}</p>}</div>{action}</div>;
}

export function EmptyState({ title, description, action }) {
    return <div className="rounded-2xl border border-dashed border-white/10 px-6 py-12 text-center"><div className="mx-auto grid size-11 place-items-center rounded-2xl bg-white/5 text-xl text-slate-500">+</div><h3 className="mt-4 font-semibold text-slate-200">{title}</h3><p className="mx-auto mt-2 max-w-sm text-sm leading-6 text-slate-500">{description}</p>{action && <div className="mt-5">{action}</div>}</div>;
}

export function FieldError({ message }) {
    return message ? <p className="mt-1 text-xs text-rose-300">{message}</p> : null;
}

export const inputClass = 'mt-1 w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-emerald-400/60 focus:ring-2 focus:ring-emerald-400/10';
export const buttonClass = 'inline-flex items-center justify-center rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:opacity-50';
export const secondaryButtonClass = 'inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-slate-200 transition hover:bg-white/10 disabled:opacity-50';
export const panelClass = 'rounded-2xl border border-white/[0.08] bg-white/[0.03] p-6 shadow-2xl shadow-black/10';
