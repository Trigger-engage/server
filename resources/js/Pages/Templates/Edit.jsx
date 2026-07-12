import { Link, useForm } from '@inertiajs/react';
import Layout, { FieldError, buttonClass } from '../../components/Layout';
import MessageComposer from '../../components/MessageComposer';
import { engagePath } from '../../lib/engagePath';

export default function EditTemplate({ workspace, template, preview, defaultSettings }) {
    const form = useForm({
        channel: template.channel,
        name: template.name,
        subject: template.subject ?? '',
        preheader: template.preheader ?? '',
        body: template.body,
        layout: template.layout ?? 'mytherapist',
        from_name: template.from_name ?? '',
        from_address: template.from_address ?? '',
        settings: { ...defaultSettings, ...(template.settings ?? {}) },
    });

    const save = (event) => {
        event.preventDefault();
        form.put(engagePath(`templates/${template.id}`), { preserveScroll: true });
    };

    return <Layout title={`Template · ${template.name}`} workspace={workspace}>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div><Link href={engagePath('templates')} className="text-sm text-slate-400 hover:text-white">← Back to templates</Link><h1 className="mt-4 text-3xl font-bold tracking-tight">Customize {template.name}</h1><p className="mt-2 text-sm text-slate-400">Edit content and branding, then verify the exact email output before saving.</p></div>
            <span className="rounded-full bg-white/10 px-3 py-1 text-xs uppercase tracking-wider text-slate-300">{template.channel}</span>
        </div>

        <form onSubmit={save} className="mt-8">
            <MessageComposer
                channel={form.data.channel}
                name={form.data.name}
                onNameChange={(value) => form.setData('name', value)}
                data={form.data}
                setField={form.setData}
                errors={form.errors}
                defaultSettings={defaultSettings}
                initialPreview={preview}
                footer={({ previewError }) => <>
                    <FieldError message={Object.values(form.errors)[0] || previewError} />
                    <div className="flex justify-end"><button className={buttonClass} disabled={form.processing || Boolean(previewError)}>{form.processing ? 'Saving…' : 'Save template'}</button></div>
                </>}
            />
        </form>
    </Layout>;
}
