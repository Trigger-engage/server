import { Link, useForm } from '@inertiajs/react';
import Layout, { FieldError, buttonClass, inputClass, panelClass } from '../components/Layout';

function Label({ children }) {
    return <label className="block text-xs font-medium uppercase tracking-wider text-slate-400">{children}</label>;
}

export default function Dashboard({ workspace, events, templates, channels, automations, metrics, recentRuns }) {
    const eventForm = useForm({ name: '' });
    const templateForm = useForm({ channel: 'email', name: '', subject: '', body: '<h1>Hello {{ person.first_name }},</h1>\n<p>Write your message here.</p>', layout: 'mytherapist', preheader: '', from_name: '', from_address: '' });
    const channelForm = useForm({ type: 'email', name: '', driver: 'log', is_default: true, host: '', port: 587, username: '', password: '', encryption: 'tls', base_url: '', api_key: '', secret_key: '', sender_id: '', route: 'dnd', app_id: '', webhook_token: '' });
    const automationForm = useForm({ name: '', trigger_event_id: events[0]?.id ?? '', reentry_policy: 'every_time' });

    const submit = (form, url, resetFields = []) => (event) => {
        event.preventDefault();
        form.post(url, { onSuccess: () => form.reset(...resetFields) });
    };

    return (
        <Layout title="Workspace" workspace={workspace}>
            <div className="mb-10 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p className="text-sm font-medium text-emerald-300">v0.1 workspace</p>
                    <h1 className="mt-1 text-3xl font-bold tracking-tight">Build a messaging automation</h1>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Define a trigger, configure delivery channels, then assemble delays, event waits, timeout paths, and sends.</p>
                </div>
                <div className="grid grid-cols-3 gap-3 text-center text-xs">
                    <Stat value={automations.length} label="Automations" />
                    <Stat value={templates.length} label="Templates" />
                    <Stat value={channels.length} label="Channels" />
                </div>
            </div>

            <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4"><Stat value={metrics.runs_30d} label="Runs · 30d" /><Stat value={metrics.messages_30d} label="Messages · 30d" /><Stat value={metrics.delivered_30d} label="Delivered · 30d" /><Stat value={metrics.failed_30d} label="Failed · 30d" /></div>

            <section className={panelClass}>
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="text-lg font-semibold">Automations</h2>
                        <p className="mt-1 text-sm text-slate-400">Draft, publish, and inspect immutable versions.</p>
                    </div>
                </div>

                <form onSubmit={submit(automationForm, '/app/automations')} className="mt-5 grid gap-4 rounded-xl border border-white/10 bg-slate-900/70 p-4 md:grid-cols-[1.4fr_1fr_1fr_auto] md:items-end">
                    <div><Label>Name</Label><input className={inputClass} value={automationForm.data.name} onChange={(e) => automationForm.setData('name', e.target.value)} placeholder="Welcome sequence" /><FieldError message={automationForm.errors.name} /></div>
                    <div><Label>Trigger event</Label><select className={inputClass} value={automationForm.data.trigger_event_id} onChange={(e) => automationForm.setData('trigger_event_id', e.target.value)}><option value="">Select event</option>{events.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select><FieldError message={automationForm.errors.trigger_event_id} /></div>
                    <div><Label>Re-entry</Label><select className={inputClass} value={automationForm.data.reentry_policy} onChange={(e) => automationForm.setData('reentry_policy', e.target.value)}><option value="every_time">Every event</option><option value="one_active_run_per_person">One active run</option><option value="once_ever_per_person">Once per person</option></select></div>
                    <button className={buttonClass} disabled={automationForm.processing || !events.length}>Create draft</button>
                </form>

                <div className="mt-5 divide-y divide-white/10 rounded-xl border border-white/10">
                    {automations.length === 0 && <p className="p-6 text-sm text-slate-500">No automations yet. Create an event below, then start a draft.</p>}
                    {automations.map((automation) => (
                        <Link key={automation.id} href={`/app/automations/${automation.id}`} className="flex items-center justify-between gap-4 p-4 transition hover:bg-white/[0.035]">
                            <div><div className="font-medium">{automation.name}</div><div className="mt-1 text-xs text-slate-500">{automation.trigger_event?.name} · {automation.reentry_policy.replaceAll('_', ' ')} · {automation.runs_count} runs</div></div>
                            <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${automation.status === 'active' ? 'bg-emerald-400/15 text-emerald-300' : 'bg-amber-400/15 text-amber-200'}`}>{automation.status}</span>
                        </Link>
                    ))}
                </div>
            </section>

            <section className={`${panelClass} mt-6`}><h2 className="font-semibold">Recent runs</h2><div className="mt-4 divide-y divide-white/10">{recentRuns.length === 0 && <p className="py-4 text-sm text-slate-500">No runs yet.</p>}{recentRuns.map((run) => <Link key={run.id} href={`/app/runs/${run.id}`} className="flex items-center justify-between py-3 text-sm hover:text-emerald-300"><span>#{run.id} · {run.automation.name} · {run.person.external_id}</span><span className="text-xs text-slate-500">{run.status}</span></Link>)}</div></section>

            <div className="mt-6 grid gap-6 lg:grid-cols-3">
                <section className={panelClass}>
                    <h2 className="font-semibold">1. Event definition</h2><p className="mt-1 text-sm text-slate-500">Events also register automatically on ingestion.</p>
                    <form onSubmit={submit(eventForm, '/app/events', ['name'])} className="mt-5 space-y-4">
                        <div><Label>Event name</Label><input className={inputClass} value={eventForm.data.name} onChange={(e) => eventForm.setData('name', e.target.value)} placeholder="customer_sign_up" /><FieldError message={eventForm.errors.name} /></div>
                        <button className={buttonClass} disabled={eventForm.processing}>Save event</button>
                    </form>
                    <div className="mt-5 flex flex-wrap gap-2">{events.map((item) => <span key={item.id} className="rounded-md bg-white/5 px-2 py-1 text-xs text-slate-400">{item.name}</span>)}</div>
                </section>

                <section className={panelClass}>
                    <h2 className="font-semibold">2. Message template</h2><p className="mt-1 text-sm text-slate-500">Use {'{{ person.* }}'} and {'{{ event.* }}'} variables.</p>
                    <form onSubmit={submit(templateForm, '/app/templates')} className="mt-5 space-y-3">
                        <div><Label>Channel</Label><select className={inputClass} value={templateForm.data.channel} onChange={(e) => templateForm.setData('channel', e.target.value)}><option value="email">Email</option><option value="sms">SMS</option><option value="push">Push</option></select></div>
                        <div><Label>Name</Label><input className={inputClass} value={templateForm.data.name} onChange={(e) => templateForm.setData('name', e.target.value)} /></div>
                        <div><Label>Subject</Label><input className={inputClass} value={templateForm.data.subject} onChange={(e) => templateForm.setData('subject', e.target.value)} /></div>
                        <div><Label>{templateForm.data.channel === 'email' ? 'Starter HTML body' : 'Message body'}</Label><textarea rows="4" className={inputClass} value={templateForm.data.body} onChange={(e) => templateForm.setData('body', e.target.value)} /></div>
                        <FieldError message={templateForm.errors.body || templateForm.errors.subject || templateForm.errors.name} />
                        <button className={buttonClass} disabled={templateForm.processing}>Create template</button>
                    </form>
                    <div className="mt-5 divide-y divide-white/10 rounded-lg border border-white/10">{templates.length === 0 && <p className="p-3 text-xs text-slate-500">No templates yet.</p>}{templates.map((template) => <Link key={template.id} href={`/app/templates/${template.id}/edit`} className="flex items-center justify-between gap-3 p-3 text-sm hover:bg-white/5"><span><span className="font-medium text-slate-200">{template.name}</span><span className="mt-0.5 block text-xs text-slate-500">{template.channel}{template.channel === 'email' && ` · ${template.layout === 'plain' ? 'plain' : 'branded'}`}</span></span><span className="text-xs text-emerald-300">Customize →</span></Link>)}</div>
                </section>

                <section className={panelClass}>
                    <h2 className="font-semibold">3. Delivery channel</h2><p className="mt-1 text-sm text-slate-500">SMTP, Termii, OneSignal, or local logging.</p>
                    <form onSubmit={submit(channelForm, '/app/channels', ['name', 'host', 'username', 'password'])} className="mt-5 space-y-3">
                        <div><Label>Type</Label><select className={inputClass} value={channelForm.data.type} onChange={(e) => { const type = e.target.value; channelForm.setData({ ...channelForm.data, type, driver: type === 'sms' ? 'termii' : type === 'push' ? 'onesignal' : 'smtp' }); }}><option value="email">Email</option><option value="sms">SMS</option><option value="push">Push</option></select></div>
                        <div><Label>Name</Label><input className={inputClass} value={channelForm.data.name} onChange={(e) => channelForm.setData('name', e.target.value)} /></div>
                        <div><Label>Driver</Label><select className={inputClass} value={channelForm.data.driver} onChange={(e) => channelForm.setData('driver', e.target.value)}><option value="log">Log (development)</option>{channelForm.data.type === 'email' && <option value="smtp">SMTP</option>}{channelForm.data.type === 'sms' && <option value="termii">Termii</option>}{channelForm.data.type === 'push' && <option value="onesignal">OneSignal</option>}</select></div>
                        {channelForm.data.driver === 'smtp' && <div className="grid grid-cols-3 gap-2"><div className="col-span-2"><Label>Host</Label><input className={inputClass} value={channelForm.data.host} onChange={(e) => channelForm.setData('host', e.target.value)} /></div><div><Label>Port</Label><input type="number" className={inputClass} value={channelForm.data.port} onChange={(e) => channelForm.setData('port', e.target.value)} /></div><div className="col-span-3"><Label>Username</Label><input className={inputClass} value={channelForm.data.username} onChange={(e) => channelForm.setData('username', e.target.value)} /></div><div className="col-span-3"><Label>Password</Label><input type="password" className={inputClass} value={channelForm.data.password} onChange={(e) => channelForm.setData('password', e.target.value)} /></div></div>}
                        {channelForm.data.driver === 'termii' && <div className="space-y-2"><input className={inputClass} placeholder="Regional base URL" value={channelForm.data.base_url} onChange={(e) => channelForm.setData('base_url', e.target.value)} /><input type="password" className={inputClass} placeholder="API key" value={channelForm.data.api_key} onChange={(e) => channelForm.setData('api_key', e.target.value)} /><input type="password" className={inputClass} placeholder="Webhook secret key" value={channelForm.data.secret_key} onChange={(e) => channelForm.setData('secret_key', e.target.value)} /><input className={inputClass} placeholder="Sender ID" value={channelForm.data.sender_id} onChange={(e) => channelForm.setData('sender_id', e.target.value)} /></div>}
                        {channelForm.data.driver === 'onesignal' && <div className="space-y-2"><input className={inputClass} placeholder="App ID" value={channelForm.data.app_id} onChange={(e) => channelForm.setData('app_id', e.target.value)} /><input type="password" className={inputClass} placeholder="REST API key" value={channelForm.data.api_key} onChange={(e) => channelForm.setData('api_key', e.target.value)} /><input type="password" className={inputClass} placeholder="Event Stream bearer token" value={channelForm.data.webhook_token} onChange={(e) => channelForm.setData('webhook_token', e.target.value)} /></div>}
                        <label className="flex items-center gap-2 text-sm text-slate-400"><input type="checkbox" checked={channelForm.data.is_default} onChange={(e) => channelForm.setData('is_default', e.target.checked)} /> Default email channel</label>
                        <FieldError message={channelForm.errors.host || channelForm.errors.name} />
                        <button className={buttonClass} disabled={channelForm.processing}>Create channel</button>
                    </form>
                </section>
            </div>
        </Layout>
    );
}

function Stat({ value, label }) {
    return <div className="rounded-xl border border-white/10 bg-white/5 px-3 py-2"><div className="text-lg font-bold text-white">{value}</div><div className="text-slate-500">{label}</div></div>;
}
