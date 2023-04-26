<?php
declare(strict_types=1);

namespace Wwwision\Markwhen;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamId;
use Neos\EventStore\Model\Event\EventType;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Annotations as Flow;

/**
 * The state of the {@see MarkwhenProjection}
 *
 * This is implemented as an immutable class, so most methods will return a new instance
 */
#[Flow\Proxy(false)]
final class MarkwhenProjectionState implements ProjectionStateInterface, JsonSerializable {

    private function __construct(
        public readonly SequenceNumber $sequenceNumber,
        public readonly ?ContentStreamId $liveContentStreamId,
        public readonly array $nodeEvents,
    ) {}

    public static function reset(): self
    {
        return new self(SequenceNumber::none(), null, []);
    }

    public static function fromArray(array $array): self
    {
        return new self(
            SequenceNumber::fromInteger($array['sequenceNumber'] ?? 0),
            isset($array['liveContentStreamId']) ? ContentStreamId::fromString($array['liveContentStreamId']) : null,
            $array['nodeEvents'] ?? [],
        );
    }

    public function withSequenceNumber(SequenceNumber $sequenceNumber): self
    {
        return new self($sequenceNumber, $this->liveContentStreamId, $this->nodeEvents);
    }

    public function withLiveContentStreamId(ContentStreamId $liveContentStreamId): self
    {
        return new self($this->sequenceNumber, $liveContentStreamId, $this->nodeEvents);
    }

    public function withAddedNodeEvent(NodeAggregateId $nodeAggregateId, \DateTimeImmutable $dateTime, EventType $eventType): self
    {
        $nodeEvents = $this->nodeEvents;
        if (!isset($nodeEvents[$nodeAggregateId->value])) {
            $nodeEvents[$nodeAggregateId->value] = [];
        }
        $nodeEvents[$nodeAggregateId->value][] = [
            'timestamp' => $dateTime->format(DATE_W3C),
            'type' => $eventType->value,
        ];
        return new self(
            $this->sequenceNumber,
            $this->liveContentStreamId,
            $nodeEvents,
        );
    }

    /**
     * Renders the current state into a syntax that can be interpreted by markwhen.com
     */
    public function renderMarkwhen(): string
    {
        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s\Z');
        $markwhen = "title: Neos Content Repository\n\n#creation: #49a74f\n#modification: #e6c833\n#deletion: #c83737\n\n";
        foreach ($this->nodeEvents as $nodeId => $events) {
            $firstEvent = $events[0];
            $lastEvent = $events[array_key_last($events)];
            $markwhen .= "  group $nodeId\n";
            $startTimestamp = DateTimeImmutable::createFromFormat(DATE_W3C, $firstEvent['timestamp'], new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $endTimestamp = $lastEvent['type'] !== 'NodeAggregateWasRemoved' ? $now : DateTimeImmutable::createFromFormat(DATE_W3C, $lastEvent['timestamp'], new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            $markwhen .= "$startTimestamp-$endTimestamp:\n";
            foreach ($events as $event) {
                $timestamp = DateTimeImmutable::createFromFormat(DATE_W3C, $event['timestamp'], new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                $markwhen .= "$timestamp: ";
                $markwhen .= match ($event['type']) {
                    'NodeAggregateWithNodeWasCreated' => 'Created #creation',
                    'NodePropertiesWereSet' => 'Properties updated #modification',
                    'NodeReferencesWereSet' => 'References updated #modification',
                    'NodeAggregateWasDisabled' => 'Disabled #deletion',
                    'NodeAggregateWasEnabled' => 'Enabled #creation',
                    'NodeAggregateWasRemoved' => 'Deleted #deletion',
                };
                $markwhen .= "\n";
            }

            $markwhen .= "endGroup\n\n";
        }
        return $markwhen;
    }

    /**
     * The state is persisted into a JSON file
     */
    public function jsonSerialize(): array
    {
        $data = [
            'sequenceNumber' => $this->sequenceNumber->value,
        ];
        if ($this->liveContentStreamId !== null) {
            $data['liveContentStreamId'] = $this->liveContentStreamId->value;
        }
        $data['nodeEvents'] = $this->nodeEvents;
        return $data;
    }
}
