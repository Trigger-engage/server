// Copy for the send confirmation. Lives in lib/ (not a page module) because
// both the Broadcasts index and the composer use it.
//
// The headline number is total - sendable — the only overlap-safe figure the
// server provides. no_destination and suppressed can overlap on the same
// person (an unsubscribed profile with no email), so they are rendered as
// qualifiers, never summed.
export function sendConfirmText(broadcast, verb = 'Send') {
    const audience = broadcast.audience;
    const segmentName = broadcast.segment?.name ?? 'selected';
    const finality = "Sending starts immediately and can't be undone.";

    if (!audience) {
        return `${verb} “${broadcast.name}” to the current ${segmentName} audience? ${finality}`;
    }

    if (audience.sendable === 0) {
        return `No one in ${segmentName} can receive this ${broadcast.channel} broadcast — `
            + `all ${audience.total} ${audience.total === 1 ? 'person is' : 'people are'} missing a destination or unsubscribed. Send anyway?`;
    }

    const skipped = audience.total - audience.sendable;
    let skippedSentence = '';
    if (skipped > 0) {
        const noun = broadcast.channel === 'sms' ? 'phone number' : broadcast.channel === 'push' ? 'push destination' : 'email address';
        const causes = [];
        if (audience.no_destination > 0) causes.push(`no ${noun}`);
        if (audience.suppressed > 0) causes.push('unsubscribed or suppressed');
        skippedSentence = ` ${skipped} will be skipped (${causes.join(', ')}).`;
    }

    return `${verb} “${broadcast.name}” to ${audience.sendable} of ${audience.total} people in ${segmentName}?${skippedSentence} ${finality}`;
}
