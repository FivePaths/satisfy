<?php

namespace Playbloom\Plugin\Mcp\Tool;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;
use Playbloom\Queue\BuildQueue;
use Playbloom\Runner\SatisBuildRunner;

/**
 * The build queue and the last build's output, as the admin UI's build page
 * shows them.
 */
final class BuildStatus
{
    private const MAX_LOG_BYTES = 65536;

    public function __construct(
        private readonly BuildQueue $queue,
        private readonly SatisBuildRunner $runner,
    ) {
    }

    /**
     * @return array{building: bool, pending: int, log: string}
     */
    #[McpTool(
        name: 'build_status',
        description: 'Whether the worker is running a build, how many build requests wait in the queue, and the end of the last build\'s output.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    public function __invoke(
        #[Schema(description: 'How much of the last build\'s output to return, in bytes counted from its end.', minimum: 0, maximum: self::MAX_LOG_BYTES)]
        int $logBytes = 8192,
    ): array {
        return [
            'building' => $this->queue->isBuilding(),
            'pending' => $this->queue->pending(),
            'log' => $this->runner->getLastLog(max(0, min($logBytes, self::MAX_LOG_BYTES))),
        ];
    }
}
