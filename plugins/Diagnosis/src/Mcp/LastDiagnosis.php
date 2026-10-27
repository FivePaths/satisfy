<?php

namespace Playbloom\Plugin\Diagnosis\Mcp;

use Mcp\Capability\Attribute\McpResource;
use Playbloom\Plugin\Diagnosis\DiagnosisStore;

/**
 * The newest diagnosis, for the mcp plugin's server.
 */
final class LastDiagnosis
{
    public function __construct(private readonly DiagnosisStore $store)
    {
    }

    #[McpResource(
        uri: 'satisfy://build/diagnosis',
        name: 'build_diagnosis',
        description: 'The diagnosis of the newest failed build: the build (id, exit code, what it built, when it finished), the status of the diagnosis (done or failed), the model\'s answer, and the model. {"status": "none"} until a build has failed.',
        mimeType: 'application/json',
    )]
    public function __invoke(): string
    {
        return json_encode($this->store->last() ?? ['status' => 'none'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
