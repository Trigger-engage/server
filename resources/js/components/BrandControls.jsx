import { inputClass, panelClass } from './Layout';

export const colorFields = [
    ['background_color', 'Page'], ['card_color', 'Card'], ['heading_color', 'Heading'],
    ['text_color', 'Body text'], ['muted_color', 'Muted text'], ['footer_color', 'Footer'],
    ['footer_divider_color', 'Footer divider'], ['accent_color', 'Accent'],
];

const socialFields = [
    ['instagram_url', 'Instagram'], ['youtube_url', 'YouTube'], ['facebook_url', 'Facebook'],
    ['tiktok_url', 'TikTok'], ['linkedin_url', 'LinkedIn'], ['x_url', 'X / Twitter'],
];

/**
 * The branded-email controls: identity, colours, footer and links. Used by the
 * workspace Brand page and, for per-message overrides, by the composer.
 */
export default function BrandControls({ settings, updateSetting, title = 'Brand', description, actions = null }) {
    return <>
        <section className={panelClass}>
            <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-lg font-semibold">{title}</h2><p className="mt-1 text-sm text-slate-500">{description}</p></div>{actions}</div>
            <div className="mt-5 grid gap-4 sm:grid-cols-2"><Field label="Brand name"><input className={inputClass} value={settings.brand_name ?? ''} onChange={(e) => updateSetting('brand_name', e.target.value)} /></Field><Field label="Brand suffix"><input className={inputClass} value={settings.brand_suffix ?? ''} onChange={(e) => updateSetting('brand_suffix', e.target.value)} /></Field><Field label="Tagline"><input className={inputClass} value={settings.tagline ?? ''} onChange={(e) => updateSetting('tagline', e.target.value)} /></Field><Field label="Support email"><input type="email" className={inputClass} value={settings.support_email ?? ''} onChange={(e) => updateSetting('support_email', e.target.value)} /></Field><Field label="Logo URL"><input type="url" className={inputClass} value={settings.logo_url ?? ''} onChange={(e) => updateSetting('logo_url', e.target.value)} /></Field><Field label="Website URL"><input type="url" className={inputClass} value={settings.website_url ?? ''} onChange={(e) => updateSetting('website_url', e.target.value)} /></Field></div>
        </section>

        <section className={panelClass}>
            <h2 className="text-lg font-semibold">Colors</h2><div className="mt-5 grid gap-3 sm:grid-cols-2">{colorFields.map(([key, label]) => <label key={key} className="flex items-center justify-between gap-3 rounded-lg border border-white/10 bg-slate-950/50 p-3 text-sm text-slate-300"><span>{label}<span className="ml-2 font-mono text-xs text-slate-500">{settings[key]}</span></span><input aria-label={`${label} color`} type="color" className="h-9 w-12 cursor-pointer rounded border-0 bg-transparent" value={settings[key] ?? '#000000'} onChange={(e) => updateSetting(key, e.target.value.toUpperCase())} /></label>)}</div>
        </section>

        <section className={panelClass}>
            <h2 className="text-lg font-semibold">Footer and app links</h2>
            <div className="mt-5 space-y-4"><Switch checked={settings.show_app_badges} onChange={(value) => updateSetting('show_app_badges', value)}>Show App Store and Google Play badges</Switch>{settings.show_app_badges && <div className="grid gap-4 sm:grid-cols-2"><Field label="Badge caption"><input className={inputClass} value={settings.badge_caption ?? ''} onChange={(e) => updateSetting('badge_caption', e.target.value)} /></Field><div /><Field label="App Store URL"><input type="url" className={inputClass} value={settings.app_store_url ?? ''} onChange={(e) => updateSetting('app_store_url', e.target.value)} /></Field><Field label="Google Play URL"><input type="url" className={inputClass} value={settings.play_store_url ?? ''} onChange={(e) => updateSetting('play_store_url', e.target.value)} /></Field></div>}<Field label="Why the recipient received this"><textarea rows="2" className={inputClass} value={settings.footer_note ?? ''} onChange={(e) => updateSetting('footer_note', e.target.value)} /></Field><Field label="Crisis support copy"><textarea rows="3" className={inputClass} value={settings.crisis_text ?? ''} onChange={(e) => updateSetting('crisis_text', e.target.value)} /></Field><Field label="Company line"><input className={inputClass} value={settings.company_line ?? ''} onChange={(e) => updateSetting('company_line', e.target.value)} /></Field></div>
            <details className="mt-5 rounded-lg border border-white/10 p-4"><summary className="cursor-pointer text-sm font-medium text-slate-300">Social links</summary><div className="mt-4"><Switch checked={settings.show_social_links} onChange={(value) => updateSetting('show_social_links', value)}>Show social icons</Switch>{settings.show_social_links && <div className="mt-4 grid gap-3 sm:grid-cols-2">{socialFields.map(([key, label]) => <Field key={key} label={label}><input type="url" className={inputClass} value={settings[key] ?? ''} onChange={(e) => updateSetting(key, e.target.value)} /></Field>)}</div>}</div></details>
        </section>
    </>;
}

/** Brand keys whose value differs from the brand they inherit. */
export function brandOverrides(settings, brand) {
    return Object.keys(brand).filter((key) => {
        const value = settings?.[key];
        if (value === undefined) return false;
        if (typeof value === 'string' && typeof brand[key] === 'string') {
            return String(brand[key]).startsWith('#') ? value.trim().toUpperCase() !== brand[key].toUpperCase() : value.trim() !== brand[key].trim();
        }
        return value !== brand[key];
    });
}

function Field({ label, children }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{label}{children}</label>;
}

function Switch({ checked, onChange, children }) {
    return <label className="flex items-center gap-3 text-sm text-slate-300"><input type="checkbox" className="size-4 accent-emerald-400" checked={Boolean(checked)} onChange={(e) => onChange(e.target.checked)} />{children}</label>;
}
