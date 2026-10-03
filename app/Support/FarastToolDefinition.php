<?php

namespace App\Support;

use App\Models\FarastTool;
use App\Models\FarastToolVersion;

final class FarastToolDefinition
{
    public function __construct(
        public readonly FarastTool $tool,
        public readonly FarastToolVersion $version,
        public readonly array $inputSchema,
        public readonly array $outputSchema,
        public readonly array $permissions,
        public readonly array $entitlementPolicy,
        public readonly string $usageMeter,
        public readonly bool $reversible,
        public readonly bool $transactional,
        public readonly bool $audit,
        public readonly int $timeout,
        public readonly int $retry,
        public readonly array $provenance,
    ) {}

    public static function from(FarastTool $tool, FarastToolVersion $version): self
    {
        return new self(
            $tool,$version,
            is_array($tool->input_schema)?$tool->input_schema:[],
            is_array($tool->output_schema)?$tool->output_schema:[],
            is_array($tool->permissions)?$tool->permissions:[],
            is_array($tool->entitlement_policy)?$tool->entitlement_policy:[],
            (string)($tool->usage_meter?:'operation'),
            (bool)$tool->reversible,(bool)$tool->transactional,(bool)$tool->audit_enabled,
            max(1,(int)$tool->timeout_seconds),max(0,(int)$tool->retry_count),
            is_array($tool->provenance)?$tool->provenance:[]
        );
    }
}