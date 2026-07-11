import { Head, Link, usePage } from '@inertiajs/react';

export default function Layout({ title, workspace, children }) {
    const { flash } = usePage().props;

    return (
        <div className="min-h-screen bg-slate-950 text-slate-100">
            <Head title={title} />
            <header className="border-b border-white/10 bg-slate-950/90 backdrop-blur">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                    <Link href="/app" className="flex items-center gap-3">
                        <span className="grid size-9 place-items-center rounded-xl bg-emerald-400 font-black text-slate-950">TE</span>
                        <span>
                            <span className="block font-semibold tracking-tight">Trigger Engage</span>
                            <span className="block text-xs text-slate-400">{workspace.name}</span>
                        </span>
                    </Link>
                    <div className="text-right text-xs text-slate-500">
                        <div>{workspace.public_id}</div>
                        <div>{workspace.timezone}</div>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-7xl px-6 py-10">
                {flash?.success && (
                    <div className="mb-6 rounded-xl border border-emerald-400/30 bg-emerald-400/10 px-4 py-3 text-sm text-emerald-200">
                        {flash.success}
                    </div>
                )}
                {children}
            </main>
        </div>
    );
}

export function FieldError({ message }) {
    return message ? <p className="mt-1 text-xs text-rose-300">{message}</p> : null;
}

export const inputClass = 'mt-1 w-full rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-emerald-400/60 focus:ring-2 focus:ring-emerald-400/10';
export const buttonClass = 'inline-flex items-center justify-center rounded-lg bg-emerald-400 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:opacity-50';
export const secondaryButtonClass = 'inline-flex items-center justify-center rounded-lg border border-white/10 bg-white/5 px-4 py-2 text-sm font-medium text-slate-200 transition hover:bg-white/10 disabled:opacity-50';
export const panelClass = 'rounded-2xl border border-white/10 bg-white/[0.035] p-6 shadow-2xl shadow-black/10';
