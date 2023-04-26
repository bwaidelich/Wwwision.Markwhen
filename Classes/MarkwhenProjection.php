<?php
declare(strict_types=1);

namespace Wwwision\Markwhen;

use DateTimeImmutable;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Event\NodeAggregateWasDisabled;
use Neos\ContentRepository\Core\Feature\NodeDisabling\Event\NodeAggregateWasEnabled;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\RootWorkspaceWasCreated;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\EventStore\Model\Event;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\EventStreamInterface;
use Neos\Utility\Files;

/**
 * This class contains the projection logic, that is invoked for every event
 * {@see MarkwhenProjection::catchUp()}
 *
 * For the sake of simplicity, it persists its state into a JSON file in the path that is specified in the constructor
 *
 * *NOTE:* This projection is not thread-safe because we use a simple state persistence without locking
 *
 * @implements ProjectionInterface<MarkwhenProjectionState>
 */
final class MarkwhenProjection implements ProjectionInterface {

    private ?MarkwhenProjectionState $state = null;

    public function __construct(
        private readonly string $path,
        private readonly EventNormalizer $eventNormalizer,
    ) {}

    /**
     * Usually the setUp method is used to create/update database tables.
     * In this case (since we store the state in a file) we just make sure that the target folder exists
     */
    public function setUp(): void {
        Files::createDirectoryRecursively(basename($this->path));
        $this->reset();
    }

    /**
     * We are only interested in a couple of events.
     * This is mainly a performance optimization that prevents a catchup if no handled event was published
     */
    public function canHandle(Event $event): bool {
        $handledEventTypes = [
            'RootWorkspaceWasCreated',
            'NodeAggregateWithNodeWasCreated',
            'NodePropertiesWereSet',
            'NodeReferencesWereSet',
            'NodeAggregateWasDisabled',
            'NodeAggregateWasEnabled',
            'NodeAggregateWasRemoved',
        ];
        return in_array($event->type->value, $handledEventTypes, true);
    }

    /**
     * This method will be called by the framework, whenever an (supported) event is published (@see ContentRepository::catchUpProjection()}
     */
    public function catchUp(EventStreamInterface $eventStream, ContentRepository $contentRepository): void {
        // create a copy of the state that we can mutate
        $state = $this->state();
        // the state contains the last seen "sequenceNumber" – we filter the $eventStream to iterate only
        // through events _after_ that sequence number
        foreach ($eventStream->withMinimumSequenceNumber($state->sequenceNumber->next()) as $eventEnvelope) {
            // we could work with the raw serialized \Neos\EventStore\Model\Event instance
            // But denormalizing it to the actual domain event instance, we gain some type safety:
            $event = $this->eventNormalizer->denormalize($eventEnvelope->event);

            // for each event...
            $state = match ($event::class) {
                // this case is special: we can use the `RootWorkspaceWasCreated` to remember the current content stream of the "live" workspace, so that we can ignore events from other event streams lateron
                RootWorkspaceWasCreated::class => $state->withLiveContentStreamId(liveContentStreamId: $event->newContentStreamId),
                // for the other events we add the event to our state ({@see addNodeEvent()}}
                NodeAggregateWithNodeWasCreated::class,
                NodePropertiesWereSet::class,
                NodeAggregateWasDisabled::class,
                NodeAggregateWasEnabled::class,
                NodeAggregateWasRemoved::class
                    => $this->addNodeEvent($state, $event->contentStreamId, $event->nodeAggregateId, $eventEnvelope),
                // we only need this special case because the nodeAggregateId is called differently here :-/
                NodeReferencesWereSet::class => $this->addNodeEvent($state, $event->contentStreamId, $event->sourceNodeAggregateId, $eventEnvelope),
                default => $state,
            };
            // update the last seen sequence number
            $state = $state->withSequenceNumber($eventEnvelope->sequenceNumber);
        }
        // finally, persist the state
        $this->saveState($state);
    }

    /**
     * Adds the given event to the in-memory $state if it is an event of the live content stream
     */
    private function addNodeEvent(MarkwhenProjectionState $state, ContentStreamId $contentStreamId, NodeAggregateId $nodeAggregateId, EventEnvelope $eventEnvelope): MarkwhenProjectionState {
        // if it's not an event within the live content stream, we just return the un-altered state
        if ($state->liveContentStreamId === null || !$state->liveContentStreamId->equals($contentStreamId)) {
            return $state;
        }
        // otherwise we extract the initiating timestamp from the event (with a fallback to the `recordedAt` timestamp, if that metadata does not exist)
        $dateTime = $eventEnvelope->event->metadata->has('initiatingTimestamp') ? DateTimeImmutable::createFromFormat(DATE_W3C, $eventEnvelope->event->metadata->get('initiatingTimestamp')) : $eventEnvelope->recordedAt;
        return $state->withAddedNodeEvent($nodeAggregateId, $dateTime, $eventEnvelope->event->type);
    }

    /**
     * This method can be used to determine the last seen sequence number from the "outside"
     */
    public function getSequenceNumber(): SequenceNumber {
        return $this->loadState()->sequenceNumber;
    }

    /**
     * With this method will return the persisted state
     * It is called by ContentRepository->projectionState(MarkwhenProjectionState::class)
     * @see \Wwwision\Markwhen\Command\MarkwhenCommandController::renderCommand()
     */
    public function getState(): MarkwhenProjectionState
    {
        return $this->state();
    }

    /**
     * This method will reset the state by persisting an empty JSON file
     */
    public function reset(): void
    {
        $this->saveState(MarkwhenProjectionState::reset());
    }

    // -----------------

    private function saveState(MarkwhenProjectionState $state): void
    {
        try {
            file_put_contents($this->path, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to encode state to JSON: %s', $e->getMessage()), 1682240941, $e);
        }
        $this->state = $state;
    }

    private function state(): MarkwhenProjectionState
    {
        return $this->state ?: $this->loadState();
    }

    private function loadState(): MarkwhenProjectionState
    {
        try {
            $serializedState = json_decode(file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Failed to decode JSON to state: %s', $e->getMessage()), 1682240966, $e);
        }
        return MarkwhenProjectionState::fromArray($serializedState);
    }

}
