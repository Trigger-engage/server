import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import BrandControls, { brandOverrides } from '../../components/BrandControls';
import Layout, { FieldError, PageHeader, buttonClass, panelClass, secondaryButtonClass } from '../../components/Layout';
import { engagePath } from '../../lib/engagePath';

const SAMPLE_BODY = '<h1>Hi {{ person.first_name }},</h1><p>This is how every branded email from your workspace looks: the logo, colours and footer below come from this page.</p><p>A template can still change any of it for itself.</p>';

const KEY_LABELS = {
    brand_name: 'brand name', brand_suffix: 'brand suffix', tagline: 'tagline', logo_url: 'logo', website_url: 'website',
    support_email: 'support email', footer_note: 'footer note', crisis_text: 'crisis copy', company_line: 'company line',
    show_app_badges: 'app badges', show_social_links: 'social links', badge_caption: 'badge caption',
};

export default function Edit({ workspace, brand, defaults, customized, overriding, templateCount }) {
    const form = useForm({ brand: { ...brand } });
    const updateSetting = (key, value) => form.setData('brand', { ...form.data.brand, [key]: value });
    const changedFromDefaults = brandOverrides(form.data.brand, defaults).length;
    const preview = usePreview(form.data.brand);

    const save = (event) => {
        event.preventDefault();
        form.put(engagePath('brand'), { preserveScroll: true });
    };
    const reset = () => {
        if (window.confirm('Reset the brand to the defaults? Templates that changed part of it keep their own changes.')) {
            router.delete(engagePath('brand'), { preserveScroll: true, onSuccess: () => form.setData('brand', { ...defaults }) });
        }
    };

    return <Layout title="Brand" workspace={workspace}>
        <PageHeader eyebrow="Workspace" title="Brand"
            description={`One look for every branded email. ${templateCount} email ${templateCount === 1 ? 'template follows' : 'templates follow'} it, except for the settings a template changes itself.`} />

        <form onSubmit={save} className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(420px,0.9fr)]">
            <div className="space-y-6">
                <BrandControls settings={form.data.brand} updateSetting={updateSetting} title="Identity"
                    description="Logo, name and links shown at the top and bottom of every branded email." />

                {overriding.length > 0 && <section className={panelClass}>
                    <h2 className="font-semibold">Templates with their own settings</h2>
                    <p className="mt-1 text-sm text-slate-400">These keep their own value for the settings listed, whatever you set here.</p>
                    <ul className="mt-4 divide-y divide-white/[0.07] text-sm">
                        {overriding.map((template) => <li key={template.id} className="flex flex-col gap-1 py-3 sm:flex-row sm:items-center sm:justify-between">
                            <Link href={engagePath(`templates/${template.id}/edit`)} className="text-slate-200 hover:text-emerald-300">{template.name}</Link>
                            <span className="text-xs text-slate-400">{template.keys.map((key) => KEY_LABELS[key] ?? key.replace(/_/g, ' ')).join(', ')}</span>
                        </li>)}
                    </ul>
                </section>}

                <div className={`${panelClass} flex flex-wrap items-center justify-between gap-3`}>
                    <p className="text-sm text-slate-400">{changedFromDefaults === 0 ? 'Using the default brand.' : `${changedFromDefaults} ${changedFromDefaults === 1 ? 'setting differs' : 'settings differ'} from the defaults.`}</p>
                    <div className="flex gap-3">
                        {customized && <button type="button" className={secondaryButtonClass} onClick={reset}>Reset to defaults</button>}
                        <button className={buttonClass} disabled={form.processing || !form.isDirty}>{form.processing ? 'Saving…' : 'Save brand'}</button>
                    </div>
                    <FieldError message={Object.values(form.errors)[0]} />
                </div>
            </div>

            <aside className="xl:sticky xl:top-24 xl:self-start">
                <section className={`${panelClass} overflow-hidden p-0`}>
                    <div className="flex items-center justify-between border-b border-white/10 px-4 py-3">
                        <div className="text-xs font-semibold uppercase tracking-wider text-slate-400">Live preview</div>
                        <span className={`text-xs ${preview.error ? 'text-rose-300' : preview.loading ? 'text-amber-300' : 'text-emerald-300'}`}>{preview.error ? 'Invalid' : preview.loading ? 'Rendering…' : 'Up to date'}</span>
                    </div>
                    {preview.error && <p role="alert" className="px-4 py-3 text-xs text-rose-300">{preview.error}</p>}
                    <iframe title="Brand preview" sandbox="" className="h-[760px] w-full bg-white" srcDoc={preview.html} />
                </section>
            </aside>
        </form>
    </Layout>;
}

// Renders a sample email through the real layout, debounced, with the brand
// being edited. The template preview endpoint does the rendering.
function usePreview(brand) {
    const [state, setState] = useState({ html: '', loading: true, error: '' });
    const payload = useMemo(() => JSON.stringify({
        channel: 'email', name: 'Brand preview', subject: 'A message from you', body: SAMPLE_BODY, layout: 'mytherapist', settings: brand,
    }), [brand]);

    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setState((current) => ({ ...current, loading: true }));
            try {
                const response = await fetch(`${window.location.origin}${engagePath('templates/preview')}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                    body: payload,
                });
                const result = await response.json();
                if (!response.ok) throw new Error(Object.values(result?.errors ?? {})[0]?.[0] ?? 'The preview could not be rendered.');
                setState({ html: result.html, loading: false, error: '' });
            } catch (error) {
                if (error.name !== 'AbortError') setState((current) => ({ ...current, loading: false, error: error.message }));
            }
        }, 350);

        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [payload]);

    return state;
}
