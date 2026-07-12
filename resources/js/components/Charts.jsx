import { useId, useState } from 'react';

const fmtDate = (iso) => { const [, m, d] = iso.split('-'); return `${Number(m)}/${Number(d)}`; };
const niceMax = (value) => { if (value <= 5) return Math.max(1, value); const pow = Math.pow(10, String(Math.round(value)).length - 1); return Math.ceil(value / pow) * pow; };

/**
 * Area + optional overlay line, with a hover crosshair. Pure inline SVG so it
 * renders anywhere with no chart dependency; the viewBox scales to any width.
 */
export function TrendChart({ primary, secondary, primaryLabel = 'Primary', secondaryLabel, accent = '#34d399', accentSecondary = '#a78bfa', height = 240 }) {
    const gradientId = useId();
    const [hover, setHover] = useState(null);
    const W = 720; const H = height; const PAD = { t: 16, r: 16, b: 28, l: 40 };
    const iw = W - PAD.l - PAD.r; const ih = H - PAD.t - PAD.b;
    const n = primary.length;
    const max = niceMax(Math.max(1, ...primary.map((d) => d.value), ...(secondary ? secondary.map((d) => d.value) : [])));
    const x = (i) => PAD.l + (n <= 1 ? iw / 2 : (i / (n - 1)) * iw);
    const y = (v) => PAD.t + ih - (v / max) * ih;
    const path = (series) => series.map((d, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(d.value).toFixed(1)}`).join(' ');
    const area = `${path(primary)} L${x(n - 1).toFixed(1)},${(PAD.t + ih).toFixed(1)} L${x(0).toFixed(1)},${(PAD.t + ih).toFixed(1)} Z`;
    const gridValues = [0, 0.5, 1].map((f) => Math.round(max * f));
    const tickEvery = Math.max(1, Math.ceil(n / 6));

    const onMove = (event) => {
        const rect = event.currentTarget.getBoundingClientRect();
        const ratio = (event.clientX - rect.left) / rect.width;
        setHover(Math.min(n - 1, Math.max(0, Math.round(ratio * (n - 1)))));
    };

    return (
        <div className="relative">
            <svg viewBox={`0 0 ${W} ${H}`} className="w-full" role="img" aria-label={`${primaryLabel} over time`} onMouseMove={onMove} onMouseLeave={() => setHover(null)} style={{ overflow: 'visible' }}>
                <defs><linearGradient id={gradientId} x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor={accent} stopOpacity="0.28" /><stop offset="100%" stopColor={accent} stopOpacity="0" /></linearGradient></defs>
                {gridValues.map((value, i) => <g key={i}><line x1={PAD.l} x2={W - PAD.r} y1={y(value)} y2={y(value)} stroke="#ffffff" strokeOpacity="0.06" /><text x={PAD.l - 8} y={y(value) + 4} textAnchor="end" fontSize="11" fill="#64748b">{value}</text></g>)}
                <path d={area} fill={`url(#${gradientId})`} />
                <path d={path(primary)} fill="none" stroke={accent} strokeWidth="2" strokeLinejoin="round" />
                {secondary && <path d={path(secondary)} fill="none" stroke={accentSecondary} strokeWidth="2" strokeDasharray="4 3" strokeLinejoin="round" />}
                {primary.map((d, i) => i % tickEvery === 0 && <text key={i} x={x(i)} y={H - 8} textAnchor="middle" fontSize="11" fill="#64748b">{fmtDate(d.date)}</text>)}
                {hover !== null && <g><line x1={x(hover)} x2={x(hover)} y1={PAD.t} y2={PAD.t + ih} stroke="#ffffff" strokeOpacity="0.18" /><circle cx={x(hover)} cy={y(primary[hover].value)} r="3.5" fill={accent} />{secondary && <circle cx={x(hover)} cy={y(secondary[hover].value)} r="3.5" fill={accentSecondary} />}</g>}
            </svg>
            <div className="mt-2 flex items-center gap-4 text-xs text-slate-400">
                <span className="flex items-center gap-1.5"><span className="inline-block h-2 w-2 rounded-full" style={{ background: accent }} />{primaryLabel}</span>
                {secondaryLabel && <span className="flex items-center gap-1.5"><span className="inline-block h-2 w-3 rounded-full" style={{ background: accentSecondary }} />{secondaryLabel}</span>}
            </div>
            {hover !== null && <div className="pointer-events-none absolute -top-2 rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-xs shadow-xl" style={{ left: `${(x(hover) / W) * 100}%`, transform: 'translate(-50%,-100%)' }}>
                <div className="font-medium text-slate-200">{fmtDate(primary[hover].date)}</div>
                <div style={{ color: accent }}>{primaryLabel}: {primary[hover].value}</div>
                {secondary && <div style={{ color: accentSecondary }}>{secondaryLabel}: {secondary[hover].value}</div>}
            </div>}
        </div>
    );
}

/** Horizontal funnel/rate bars (sent → delivered → opened → clicked). */
export function FunnelBars({ stages }) {
    return (
        <div className="space-y-3">
            {stages.map((stage) => (
                <div key={stage.stage}>
                    <div className="mb-1 flex items-center justify-between text-xs"><span className="text-slate-300">{stage.stage}</span><span className="text-slate-500">{stage.value.toLocaleString()} · {stage.rate}%</span></div>
                    <div className="h-2.5 overflow-hidden rounded-full bg-white/[0.06]"><div className="h-full rounded-full bg-gradient-to-r from-emerald-400 to-emerald-300" style={{ width: `${Math.max(2, stage.rate)}%` }} /></div>
                </div>
            ))}
        </div>
    );
}

/** Per-channel stacked delivered/failed bars. */
export function ChannelBars({ channels }) {
    if (channels.length === 0) return <p className="text-sm text-slate-500">No messages sent in this window.</p>;
    const max = Math.max(1, ...channels.map((c) => c.total));
    return (
        <div className="space-y-4">
            {channels.map((channel) => (
                <div key={channel.channel}>
                    <div className="mb-1 flex items-center justify-between text-xs"><span className="font-medium capitalize text-slate-200">{channel.channel}</span><span className="text-slate-500">{channel.total.toLocaleString()} sent</span></div>
                    <div className="flex h-2.5 overflow-hidden rounded-full bg-white/[0.06]" style={{ width: `${Math.max(6, (channel.total / max) * 100)}%` }}>
                        <div className="h-full bg-emerald-400" style={{ width: `${(channel.delivered / channel.total) * 100}%` }} title={`${channel.delivered} delivered`} />
                        <div className="h-full bg-rose-400" style={{ width: `${(channel.failed / channel.total) * 100}%` }} title={`${channel.failed} failed`} />
                    </div>
                </div>
            ))}
        </div>
    );
}
