<?php

namespace TriggerEngage\Server\Mcp;

use TriggerEngage\Server\Mcp\Tools\Analytics\GetAnalytics;
use TriggerEngage\Server\Mcp\Tools\Automations\GetAutomation;
use TriggerEngage\Server\Mcp\Tools\Automations\ListAutomations;
use TriggerEngage\Server\Mcp\Tools\Broadcasts\GetBroadcast;
use TriggerEngage\Server\Mcp\Tools\Broadcasts\ListBroadcasts;
use TriggerEngage\Server\Mcp\Tools\Diagnostics\DiagnoseDelivery;
use TriggerEngage\Server\Mcp\Tools\Drafts\CreateAutomationDraft;
use TriggerEngage\Server\Mcp\Tools\Drafts\CreateBroadcastDraft;
use TriggerEngage\Server\Mcp\Tools\Drafts\CreateSegment;
use TriggerEngage\Server\Mcp\Tools\Drafts\CreateTemplate;
use TriggerEngage\Server\Mcp\Tools\Drafts\DiscardAutomationDraft;
use TriggerEngage\Server\Mcp\Tools\Drafts\DuplicateTemplate;
use TriggerEngage\Server\Mcp\Tools\Drafts\SendTest;
use TriggerEngage\Server\Mcp\Tools\Drafts\UpdateAutomationDraft;
use TriggerEngage\Server\Mcp\Tools\Drafts\UpdateBroadcastDraft;
use TriggerEngage\Server\Mcp\Tools\Drafts\UpdateSegment;
use TriggerEngage\Server\Mcp\Tools\Drafts\UpdateTemplate;
use TriggerEngage\Server\Mcp\Tools\Messages\GetMessage;
use TriggerEngage\Server\Mcp\Tools\Messages\SearchMessages;
use TriggerEngage\Server\Mcp\Tools\People\GetPerson;
use TriggerEngage\Server\Mcp\Tools\People\SearchPeople;
use TriggerEngage\Server\Mcp\Tools\Runs\GetRun;
use TriggerEngage\Server\Mcp\Tools\Runs\ListRuns;
use TriggerEngage\Server\Mcp\Tools\Segments\GetSegment;
use TriggerEngage\Server\Mcp\Tools\Segments\ListSegments;
use TriggerEngage\Server\Mcp\Tools\Segments\PreviewSegment;
use TriggerEngage\Server\Mcp\Tools\Templates\GetTemplate;
use TriggerEngage\Server\Mcp\Tools\Templates\ListTemplates;
use TriggerEngage\Server\Mcp\Tools\Templates\RenderTemplate;
use TriggerEngage\Server\Mcp\Tools\Workspace\GetWorkspace;
use TriggerEngage\Server\Mcp\Tools\Workspace\ListAttributes;
use TriggerEngage\Server\Mcp\Tools\Workspace\ListChannels;
use TriggerEngage\Server\Mcp\Tools\Workspace\ListEvents;
use TriggerEngage\Server\Mcp\Tools\Workspace\ListSubscriptionTopics;

/**
 * Every tool the server offers, in the order clients list them. Hosts can
 * switch tools off by name with `trigger-engage-server.mcp.disabled_tools`.
 */
class ToolRegistry
{
    /** @var array<int, class-string<Tool>> */
    public const TOOLS = [
        GetWorkspace::class,
        ListEvents::class,
        ListAttributes::class,
        ListChannels::class,
        ListSubscriptionTopics::class,
        SearchPeople::class,
        GetPerson::class,
        ListSegments::class,
        GetSegment::class,
        PreviewSegment::class,
        ListTemplates::class,
        GetTemplate::class,
        RenderTemplate::class,
        ListAutomations::class,
        GetAutomation::class,
        ListRuns::class,
        GetRun::class,
        SearchMessages::class,
        GetMessage::class,
        ListBroadcasts::class,
        GetBroadcast::class,
        GetAnalytics::class,
        DiagnoseDelivery::class,
        // Drafting: offered only when the caller may write. Nothing here
        // reaches a person; someone publishes or sends from the dashboard.
        CreateTemplate::class,
        UpdateTemplate::class,
        DuplicateTemplate::class,
        CreateSegment::class,
        UpdateSegment::class,
        CreateBroadcastDraft::class,
        UpdateBroadcastDraft::class,
        CreateAutomationDraft::class,
        UpdateAutomationDraft::class,
        DiscardAutomationDraft::class,
        SendTest::class,
    ];

    /** @var array<int, Tool>|null */
    protected ?array $tools = null;

    /** @return array<int, Tool> */
    public function tools(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $disabled = config('trigger-engage-server.mcp.disabled_tools', []);
        $disabled = is_string($disabled) ? explode(',', $disabled) : (array) $disabled;
        $disabled = array_filter(array_map('trim', $disabled));

        return $this->tools = array_values(array_filter(
            array_map(fn (string $class) => app($class), self::TOOLS),
            fn (Tool $tool) => ! in_array($tool->name(), $disabled, true),
        ));
    }

    /**
     * The tools this caller may see and call: every reading tool, and the
     * writing ones only when the Context allows writes.
     *
     * @return array<int, Tool>
     */
    public function available(Context $context): array
    {
        return array_values(array_filter($this->tools(), fn (Tool $tool) => $context->canWrite || ! $tool->writes()));
    }

    public function find(string $name, ?Context $context = null): ?Tool
    {
        foreach ($context ? $this->available($context) : $this->tools() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
