import { Link } from '@inertiajs/react';
import { engagePath } from '../lib/engagePath';

// Where a message came from: the automation run that sent it, or a broadcast.
export default function MessageSource({ source }) {
    if (!source) return <span className="text-slate-400">—</span>;
    if (source.type === 'broadcast') {
        return <Link href={engagePath(`broadcasts/${source.broadcast.id}`)} className="text-slate-300 hover:text-emerald-300">
            <span className="block text-[11px] uppercase tracking-wider text-slate-400">Broadcast</span>{source.broadcast.name}
        </Link>;
    }
    return <div>
        <span className="block text-[11px] uppercase tracking-wider text-slate-400">Automation</span>
        {source.automation
            ? <Link href={engagePath(`automations/${source.automation.id}`)} className="text-slate-300 hover:text-emerald-300">{source.automation.name}</Link>
            : <span className="text-slate-400">Deleted automation</span>}
        <Link href={engagePath(`runs/${source.run_id}`)} className="ml-2 text-slate-400 hover:text-emerald-300">Run #{source.run_id}</Link>
    </div>;
}
