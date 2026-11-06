const CHANNEL_LABELS = { email: 'Email', sms: 'SMS', push: 'Push' };

export function channelLabel(channel) {
    return CHANNEL_LABELS[channel] ?? channel;
}

// What the provider reported after the send, in the order it happens. Each
// is only present when a webhook or receipt recorded it.
export function deliverySignals(message) {
    return [
        message.delivered_at && 'Delivered',
        message.opened_at && 'Opened',
        message.clicked_at && 'Clicked',
        message.bounced_at && 'Bounced',
    ].filter(Boolean);
}

// The stored email is a full HTML document with a live unsubscribe link.
// Opening every link as a new window, which the sandbox then blocks, keeps a
// click in the preview from navigating anywhere.
export function previewDocument(html) {
    const base = '<base target="_blank">';
    if (!html) return '';
    return /<head(\s[^>]*)?>/i.test(html) ? html.replace(/<head(\s[^>]*)?>/i, (head) => `${head}${base}`) : `${base}${html}`;
}
