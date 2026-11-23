<?php

namespace TriggerEngage\Server\Events;

use Illuminate\Foundation\Events\Dispatchable;
use TriggerEngage\Server\Models\Person;

/**
 * Expo reported a person's push token as DeviceNotRegistered (the app was
 * uninstalled or its data cleared), and the token was dropped from the
 * person's profile.
 *
 * Embedded hosts that keep their own token list should listen for it and
 * delete the token there too. Otherwise the host's next profile sync writes
 * the dead token back and every later push to it fails again.
 */
class ExpoPushTokenForgotten
{
    use Dispatchable;

    public function __construct(
        public Person $person,
        public string $token,
    ) {}
}
