<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

/**
 * Seeds the Mytherapist.ng workspace with the five launch lifecycle journeys
 * from the "Engagement and Lifecycle Messaging Recommendation":
 *
 *   1. New user activation        user_registered      → appointment_booked
 *   2. Interrupted booking        booking_started      → appointment_booked
 *   3. Booking payment recovery   booking_payment_failed → appointment_booked
 *   4. First-session continuity   first_session_completed → next_session_booked | wellness_step_completed
 *   5. Therapist readiness        therapist_approved   → availability_added
 *   6. Wellness Step support      wellness_step_assigned → wellness_step_completed
 *
 * Copy comes verbatim from the recommendation doc. Transactional truth
 * (verification links, payment/refund confirmations, appointment reminders,
 * the Wellness Step assignment push) stays in Laravel — these journeys only
 * fill the gaps around it, so nothing here duplicates a Laravel notification.
 *
 * Personalization uses safe fields only (first names, appointment state,
 * profile score). No mood values, journal text, or care content ever reaches
 * these templates.
 */
class MytherapistLifecycleSeeder extends Seeder
{
    protected const EVENTS = [
        // User lifecycle
        'user_registered', 'email_verified', 'assessment_completed',
        'therapist_profile_viewed', 'booking_started', 'booking_payment_started',
        'booking_payment_failed', 'appointment_booked', 'appointment_accepted',
        'appointment_confirmed', 'appointment_rescheduled', 'appointment_cancelled',
        'appointment_completed', 'appointment_missed', 'session_rated',
        'next_session_booked', 'first_session_booked', 'first_session_completed',
        'first_session_rated', 'wallet_funded',
        // Wellness
        'wellness_step_assigned', 'wellness_step_viewed', 'wellness_step_completed',
        'mood_checkin_completed', 'breathing_session_completed', 'meditation_completed',
        'care_recap_generated', 'care_recap_viewed',
        // Therapist lifecycle
        'therapist_registered', 'qualification_submitted', 'therapist_approved',
        'profile_score_changed', 'availability_added', 'appointment_request_received',
        'appointment_request_responded', 'time_off_scheduled', 'lms_started', 'lms_passed',
    ];

    public function run(): void
    {
        if (Workspace::where('name', 'Mytherapist.ng')->exists()) {
            $this->command?->warn('A "Mytherapist.ng" workspace already exists — delete it first to re-seed.');

            return;
        }

        $workspace = Workspace::create([
            'name' => 'Mytherapist.ng',
            'timezone' => 'Africa/Lagos',
        ]);

        [, $plaintext] = ApiKey::issue($workspace, 'laravel-backend');

        $events = [];
        foreach (self::EVENTS as $name) {
            $events[$name] = Event::create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'first_seen_at' => now(),
            ]);
        }

        $email = $workspace->channels()->create([
            'type' => 'email',
            'driver' => 'log',
            'name' => 'Email (log — switch to SMTP/ZeptoMail in Channels before launch)',
            'is_default' => true,
        ]);

        $push = $workspace->channels()->create([
            'type' => 'push',
            'driver' => 'onesignal',
            'name' => 'OneSignal (add app_id + api_key before launch)',
            'credentials' => ['app_id' => '', 'api_key' => ''],
            'is_default' => true,
        ]);

        $templates = $this->templates($workspace);

        $this->newUserActivation($workspace, $events, $templates, $email);
        $this->interruptedBooking($workspace, $events, $templates, $push);
        $this->paymentRecovery($workspace, $events, $templates, $email);
        $this->firstSessionContinuity($workspace, $events, $templates, $email, $push);
        $this->therapistReadiness($workspace, $events, $templates, $email);
        $this->wellnessStepSupport($workspace, $events, $templates, $push);

        $this->command?->info('Mytherapist.ng lifecycle workspace seeded. Backend .env values:');
        $this->command?->table(['Setting', 'Value'], [
            ['TRIGGER_ENGAGE_WORKSPACE_ID', $workspace->public_id],
            ['TRIGGER_ENGAGE_API_KEY', $plaintext],
        ]);
    }

    /** @return array<string, Template> */
    protected function templates(Workspace $workspace): array
    {
        $from = ['from_name' => 'Mytherapist.ng', 'from_address' => 'care@mytherapist.ng'];
        $appLink = 'https://mytherapistng.app.link';
        $partnersLink = 'https://partners.mytherapist.ng';

        $make = fn (array $attributes) => $workspace->templates()->create($attributes);

        return [
            'welcome' => $make([
                'channel' => 'email',
                'name' => 'Welcome after registration',
                'subject' => 'You do not have to figure everything out at once',
                'preheader' => 'Start when you are ready — one step at a time.',
                'body' => <<<HTML
<h1>Welcome to Mytherapist.ng</h1>
<p>Hi {{ person.first_name }}, welcome to Mytherapist.ng. Finding support can feel like a big step, and you do not have to rush through it.</p>
<p>You can start by telling us what matters to you, then explore therapists who may be a good fit when you are ready.</p>
<p><a class="te-button" href="{$appLink}">Find a therapist</a></p>
HTML,
            ] + $from),

            'verify_reminder' => $make([
                'channel' => 'email',
                'name' => 'Email verification reminder',
                'subject' => 'Confirm your email to continue',
                'preheader' => 'Your account is almost ready.',
                'body' => <<<HTML
<h1>Your account is almost ready</h1>
<p>Hi {{ person.first_name }}, your account is almost ready. Confirm your email so you can view therapists, save your preferences, and book a session whenever you are ready.</p>
<p>The confirmation link is in the verification email we sent you — or you can request a new one from the app.</p>
<p><a class="te-button" href="{$appLink}">Confirm email</a></p>
HTML,
            ] + $from),

            'discovery' => $make([
                'channel' => 'email',
                'name' => 'Therapist discovery guidance',
                'subject' => 'Start with what matters to you',
                'preheader' => 'You do not need to know the exact kind of therapy you need.',
                'body' => <<<HTML
<h1>Start with what matters to you</h1>
<p>You do not need to know the exact kind of therapy you need before starting. You can begin with what you would like support with, then compare therapists based on experience, approach, and availability.</p>
<p><a class="te-button" href="{$appLink}">Explore therapists</a></p>
HTML,
            ] + $from),

            'booking_incomplete_push' => $make([
                'channel' => 'push',
                'name' => 'Interrupted booking nudge',
                'subject' => 'Your booking is not complete yet',
                'body' => 'Your appointment has not been confirmed yet. You can return to check whether your selected time is still available, or choose another option that works better for you.',
            ]),

            'payment_recovery' => $make([
                'channel' => 'email',
                'name' => 'Booking payment recovery',
                'subject' => 'Your appointment has not been confirmed yet',
                'preheader' => 'You can try again or choose another payment method.',
                'body' => <<<HTML
<h1>Your appointment has not been confirmed yet</h1>
<p>Hi {{ person.first_name }}, your payment was not completed, so your appointment has not been confirmed.</p>
<p>You can try again, choose another payment method, or contact support if you need help.</p>
<p><a class="te-button" href="{$appLink}">Try payment again</a></p>
HTML,
            ] + $from),

            'reflection_push' => $make([
                'channel' => 'push',
                'name' => 'Post-session reflection',
                'subject' => 'Take a quiet moment after your session',
                'body' => 'After your session, you can add a private note, review any next steps, or simply return later when you feel ready.',
            ]),

            'plan_next_session' => $make([
                'channel' => 'email',
                'name' => 'Next-session planning',
                'subject' => 'Would you like to plan your next session?',
                'preheader' => 'There is no pressure to decide today.',
                'body' => <<<HTML
<h1>Would you like to plan your next session?</h1>
<p>Hi {{ person.first_name }}, if continuing with {{ event.therapist_first_name }} feels useful now, you can check their availability and choose another time.</p>
<p>There is no pressure to decide today.</p>
<p><a class="te-button" href="{$appLink}">View availability</a></p>
HTML,
            ] + $from),

            'profile_completeness' => $make([
                'channel' => 'email',
                'name' => 'Therapist profile completeness',
                'subject' => 'Your profile is {{ person.profile_score }}% complete',
                'preheader' => 'A small addition can help users understand your experience.',
                'body' => <<<HTML
<h1>Your profile is almost ready</h1>
<p>Hi {{ person.first_name }}, your profile is {{ person.profile_score }}% complete.</p>
<p>Adding {{ person.highest_value_missing_item }} can help users better understand your experience, approach, and whether you may be a good fit for them.</p>
<p><a class="te-button" href="{$partnersLink}">Improve profile</a></p>
HTML,
            ] + $from),

            'wellness_step_reminder_push' => $make([
                'channel' => 'push',
                'name' => 'Wellness Step reminder',
                'subject' => 'A Wellness Step is available',
                'body' => 'If today feels like a good time, you can return to your Wellness Step in Mytherapist.ng.',
            ]),
        ];
    }

    protected function newUserActivation(Workspace $workspace, array $events, array $templates, $email): void
    {
        $automation = $workspace->automations()->create([
            'name' => 'New user activation',
            'trigger_event_id' => $events['user_registered']->id,
            'reentry_policy' => 'once_ever_per_person',
        ]);

        // Laravel sends the transactional verification email at registration;
        // wait 10 minutes so this welcome never races it into the inbox.
        //
        // The event wait only sees occurrences recorded after it registers, so
        // a user who verifies during the settle delay would time out here.
        // The timed_out branch therefore re-checks the synced
        // person.email_verified attribute before sending the nudge.
        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'settle', 'type' => 'delay', 'config' => ['minutes' => 10]],
                ['id' => 'welcome', 'type' => 'send_email', 'config' => [
                    'template_id' => $templates['welcome']->id,
                    'channel_id' => $email->id,
                ]],
                ['id' => 'await_verify', 'type' => 'wait_for_event', 'config' => [
                    'event_id' => $events['email_verified']->id,
                    'timeout_hours' => 20,
                ]],
                ['id' => 'already_verified', 'type' => 'branch', 'config' => [
                    'field' => 'person.email_verified',
                    'operator' => 'equals',
                    'value' => true,
                ]],
                ['id' => 'next_morning', 'type' => 'delay', 'config' => ['days' => 1]],
                ['id' => 'ten_am', 'type' => 'delay', 'config' => ['until_time' => '10:00']],
                ['id' => 'discovery', 'type' => 'send_email', 'config' => [
                    'template_id' => $templates['discovery']->id,
                    'channel_id' => $email->id,
                ]],
                ['id' => 'verify_nudge', 'type' => 'send_email', 'config' => [
                    'template_id' => $templates['verify_reminder']->id,
                    'channel_id' => $email->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'settle'],
                ['from' => 'settle', 'to' => 'welcome'],
                ['from' => 'welcome', 'to' => 'await_verify'],
                ['from' => 'await_verify', 'to' => 'next_morning', 'branch' => 'matched'],
                ['from' => 'await_verify', 'to' => 'already_verified', 'branch' => 'timed_out'],
                ['from' => 'already_verified', 'to' => 'ten_am', 'branch' => 'true'],
                ['from' => 'already_verified', 'to' => 'verify_nudge', 'branch' => 'false'],
                ['from' => 'next_morning', 'to' => 'ten_am'],
                ['from' => 'ten_am', 'to' => 'discovery'],
                ['from' => 'discovery', 'to' => 'done'],
                ['from' => 'verify_nudge', 'to' => 'done'],
            ],
            'goals' => [
                ['id' => 'goal_booked', 'event_id' => $events['appointment_booked']->id, 'match_rules' => []],
            ],
        ]);
    }

    protected function interruptedBooking(Workspace $workspace, array $events, array $templates, $push): void
    {
        $automation = $workspace->automations()->create([
            'name' => 'Interrupted booking recovery',
            'trigger_event_id' => $events['booking_started']->id,
            'reentry_policy' => 'one_active_run_per_person',
        ]);

        // Most bookings complete within seconds; the goal cancels this run
        // before the 30-minute delay elapses. Only genuinely interrupted
        // bookings ever see the push.
        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait30', 'type' => 'delay', 'config' => ['minutes' => 30]],
                ['id' => 'nudge', 'type' => 'send_push', 'config' => [
                    'template_id' => $templates['booking_incomplete_push']->id,
                    'channel_id' => $push->id,
                    'retry_attempts' => 1,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait30'],
                ['from' => 'wait30', 'to' => 'nudge'],
                ['from' => 'nudge', 'to' => 'done'],
            ],
            'goals' => [
                ['id' => 'goal_booked', 'event_id' => $events['appointment_booked']->id, 'match_rules' => []],
            ],
        ]);
    }

    protected function paymentRecovery(Workspace $workspace, array $events, array $templates, $email): void
    {
        $automation = $workspace->automations()->create([
            'name' => 'Booking payment recovery',
            'trigger_event_id' => $events['booking_payment_failed']->id,
            'reentry_policy' => 'one_active_run_per_person',
        ]);

        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait30', 'type' => 'delay', 'config' => ['minutes' => 30]],
                ['id' => 'recover', 'type' => 'send_email', 'config' => [
                    'template_id' => $templates['payment_recovery']->id,
                    'channel_id' => $email->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait30'],
                ['from' => 'wait30', 'to' => 'recover'],
                ['from' => 'recover', 'to' => 'done'],
            ],
            'goals' => [
                ['id' => 'goal_booked', 'event_id' => $events['appointment_booked']->id, 'match_rules' => []],
            ],
        ]);
    }

    protected function firstSessionContinuity(Workspace $workspace, array $events, array $templates, $email, $push): void
    {
        $automation = $workspace->automations()->create([
            'name' => 'First-session continuity',
            'trigger_event_id' => $events['first_session_completed']->id,
            'reentry_policy' => 'once_ever_per_person',
        ]);

        // The reflection push waits 2 hours so Laravel's rating prompt has
        // room to work first; the planning email lands ~5 days later at 10:00.
        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'breathe', 'type' => 'delay', 'config' => ['hours' => 2]],
                ['id' => 'reflect', 'type' => 'send_push', 'config' => [
                    'template_id' => $templates['reflection_push']->id,
                    'channel_id' => $push->id,
                    'retry_attempts' => 1,
                ]],
                ['id' => 'few_days', 'type' => 'delay', 'config' => ['days' => 4]],
                ['id' => 'ten_am', 'type' => 'delay', 'config' => ['until_time' => '10:00']],
                ['id' => 'plan', 'type' => 'send_email', 'config' => [
                    'template_id' => $templates['plan_next_session']->id,
                    'channel_id' => $email->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'breathe'],
                ['from' => 'breathe', 'to' => 'reflect'],
                ['from' => 'reflect', 'to' => 'few_days'],
                ['from' => 'few_days', 'to' => 'ten_am'],
                ['from' => 'ten_am', 'to' => 'plan'],
                ['from' => 'plan', 'to' => 'done'],
            ],
            'goals' => [
                ['id' => 'goal_next_session', 'event_id' => $events['next_session_booked']->id, 'match_rules' => []],
                ['id' => 'goal_wellness_step', 'event_id' => $events['wellness_step_completed']->id, 'match_rules' => []],
            ],
        ]);
    }

    protected function therapistReadiness(Workspace $workspace, array $events, array $templates, $email): void
    {
        $automation = $workspace->automations()->create([
            'name' => 'Therapist readiness',
            'trigger_event_id' => $events['therapist_approved']->id,
            'reentry_policy' => 'once_ever_per_person',
        ]);

        // Laravel already sends the approval email; this journey only follows
        // up on profile completeness two days later, and stops as soon as the
        // therapist adds availability.
        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'two_days', 'type' => 'delay', 'config' => ['days' => 2]],
                ['id' => 'ten_am', 'type' => 'delay', 'config' => ['until_time' => '10:00']],
                ['id' => 'incomplete', 'type' => 'branch', 'config' => [
                    'field' => 'person.profile_score',
                    'operator' => 'lt',
                    'value' => 100,
                ]],
                ['id' => 'improve', 'type' => 'send_email', 'config' => [
                    'template_id' => $templates['profile_completeness']->id,
                    'channel_id' => $email->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'two_days'],
                ['from' => 'two_days', 'to' => 'ten_am'],
                ['from' => 'ten_am', 'to' => 'incomplete'],
                ['from' => 'incomplete', 'to' => 'improve', 'branch' => 'true'],
                ['from' => 'incomplete', 'to' => 'done', 'branch' => 'false'],
                ['from' => 'improve', 'to' => 'done'],
            ],
            'goals' => [
                ['id' => 'goal_available', 'event_id' => $events['availability_added']->id, 'match_rules' => []],
            ],
        ]);
    }

    protected function wellnessStepSupport(Workspace $workspace, array $events, array $templates, $push): void
    {
        $automation = $workspace->automations()->create([
            'name' => 'Wellness Step support',
            'trigger_event_id' => $events['wellness_step_assigned']->id,
            'reentry_policy' => 'every_time',
        ]);

        // Laravel sends the assignment notification immediately; this journey
        // only adds the gentle two-day reminder, correlated to the exact step
        // so completing it cancels only its own reminder.
        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'two_days', 'type' => 'delay', 'config' => ['days' => 2]],
                ['id' => 'ten_am', 'type' => 'delay', 'config' => ['until_time' => '10:00']],
                ['id' => 'remind', 'type' => 'send_push', 'config' => [
                    'template_id' => $templates['wellness_step_reminder_push']->id,
                    'channel_id' => $push->id,
                    'retry_attempts' => 1,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'two_days'],
                ['from' => 'two_days', 'to' => 'ten_am'],
                ['from' => 'ten_am', 'to' => 'remind'],
                ['from' => 'remind', 'to' => 'done'],
            ],
            'goals' => [
                ['id' => 'goal_completed', 'event_id' => $events['wellness_step_completed']->id, 'match_rules' => [
                    [
                        'incoming_field' => 'wellness_step_id',
                        'operator' => 'equals',
                        'source' => 'trigger_event',
                        'source_field' => 'wellness_step_id',
                    ],
                ]],
            ],
        ]);
    }
}
