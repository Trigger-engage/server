export function engagePath(path = '') {
    const configured = document.querySelector('meta[name="trigger-engage-base"]')?.content ?? '/app';
    const base = configured === '/' ? '' : configured.replace(/\/$/, '');
    const suffix = path ? `/${String(path).replace(/^\//, '')}` : '';

    return `${base}${suffix}` || '/';
}
