<?php

namespace TriggerEngage\Server\Events;

use Illuminate\Foundation\Events\Dispatchable;
use TriggerEngage\Server\Models\Person;

/**
 * A person opted in or out of a topic or a whole channel. Embedded hosts can
 * listen for it to mirror the change into their own settings. For a topic that
 * follows a profile property this matters: the host's next profile sync would
 * otherwise write its old value back.
 */
class SubscriptionChanged
{
    use Dispatchable;

    /**
     * @param  'topic'|'channel'  $scope
     * @param  string  $target  the topic key, or email | sms | push
     * @param  string  $source  preference_center | unsubscribe_link | one_click | api | dashboard
     */
    public function __construct(
        public Person $person,
        public string $scope,
        public string $target,
        public bool $subscribed,
        public string $source,
        public ?string $property = null,
    ) {}
}
