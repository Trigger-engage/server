# Subscriptions

People can stop messages at two levels. **Channel** unsubscribes stop everything on email, SMS
or push. **Topics** stop one kind of message, such as "Product news", and leave the rest alone.
Every send checks both: the channel first, then the topic of the template or broadcast.

## Topics

Create topics on the **Subscriptions** page. Each has a name, a key (how the API names it), an
optional description (shown to people on their preference page), and who gets it by default:

| Default | Who gets it |
|---|---|
| Everyone, until they opt out | Everyone without an opt-out on record. Good for reminders and updates people expect. |
| Only people who opt in | Only people who said yes. Good for research invitations or anything occasional. |

Pick a topic on a template (the composer's **Subscription topic** field) or a broadcast. A new
broadcast starts with its template's topic. A message with no topic is only stopped by a channel
unsubscribe, so leave transactional messages (receipts, session links) without one.

When someone is skipped, the run step says "person unsubscribed from Care reminders" and the
broadcast report lists them under "Opted out of this topic". The broadcast send confirmation
counts them before you send.

## A topic can follow a profile property

If your app already stores the choice, as a true/false property it syncs onto the profile (for
example `email_communication`), name that property on the topic. The property then decides:
`true` is subscribed, `false` is not, and missing falls back to the topic's default.

A change made in Trigger Engage (the preference page, the unsubscribe link, the API or the person
page) writes the property and fires `TriggerEngage\Server\Events\SubscriptionChanged`. An
embedded host should listen for it and save the choice in its own settings; otherwise its next
profile sync writes the old value back.

## What people see

Every email links to an unsubscribe page in your brand. For a message with a topic it offers to
stop that topic, or every email instead, and confirms with an undo. It links to a **preference
page** listing every topic and every channel the person can be reached on.

Emails also carry `List-Unsubscribe` and `List-Unsubscribe-Post` headers, so Gmail, Yahoo and
Apple Mail show their own Unsubscribe button (RFC 8058 one-click). It stops the message's topic,
or the channel when there is none.

A bounce or a spam complaint can't be lifted from a public page. Your team can resubscribe the
address from the person page after a warning.

## The API

`GET` and `PUT /api/v1/people/{external_id}/subscriptions` read and change the same state. See
the [API reference](../API.md#subscriptions).
