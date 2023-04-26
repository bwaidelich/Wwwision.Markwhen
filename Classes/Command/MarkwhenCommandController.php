<?php
declare(strict_types=1);

namespace Wwwision\Markwhen\Command;

use Neos\ContentRepository\Core\Factory\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;
use Wwwision\Markwhen\MarkwhenProjection;
use Wwwision\Markwhen\MarkwhenProjectionState;

final class MarkwhenCommandController extends CommandController {

    public function __construct(private readonly ContentRepositoryRegistry $contentRepositoryRegistry) {
        parent::__construct();
    }

    /**
     * Renders the current state of the {@see MarkwhenProjection} as [Markwhen](https://markwhen.com/) syntax
     *
     * By default, this command will just output the result to the console output.
     * With `./flow markwhen:render > out.mw` you can store it into a file that
     * can be consumed by the [Markwhen CLI](https://docs.markwhen.com/cli.html)
     *
     * Alternatively the output can be piped directly to the `mw` CLI tool:
     * `./flow markwhen:render | mw /dev/stdin -d timeline.html`
     *
     * @param string $contentRepository ID of the content repository to address
     */
    public function renderCommand(string $contentRepository = 'default'): void
    {
        $contentRepositoryInstance = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));
        $projectionState = $contentRepositoryInstance->projectionState(MarkwhenProjectionState::class);
        $this->outputLine($projectionState->renderMarkwhen());
    }
}
