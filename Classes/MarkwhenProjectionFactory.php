<?php
declare(strict_types=1);

namespace Wwwision\Markwhen;

use Neos\ContentRepository\Core\Factory\ProjectionFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpHookFactoryInterface;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;
use Neos\ContentRepository\Core\Projection\Projections;
use Neos\Utility\Files;

/**
 * To register a projection with the Content Repository Registry, a factory is required that implements the {@see ProjectionFactoryInterface}
 */
final class MarkwhenProjectionFactory implements ProjectionFactoryInterface
{

    public function build(ProjectionFactoryDependencies $projectionFactoryDependencies, array $options, CatchUpHookFactoryInterface $catchUpHookFactory, Projections $projectionsSoFar): MarkwhenProjection
    {
        return new MarkwhenProjection(
            // We hard-code the path for the projection state to "/Data/Markwhen/state.json"
            Files::concatenatePaths([FLOW_PATH_DATA, 'Markwhen', 'state.json']),
            $projectionFactoryDependencies->eventNormalizer,
        );
    }
}
