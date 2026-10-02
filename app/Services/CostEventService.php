<?php

namespace App\Services;

use App\Models\FarastCostEvent;
use Illuminate\Support\Str;

class CostEventService
{
    public function record(array $data): FarastCostEvent
    {
        $key=(string)($data['idempotency_key']??Str::uuid());
        $existing=FarastCostEvent::where('idempotency_key',$key)->first();
        if($existing)return $existing;

        return FarastCostEvent::create([
            'event_id'=>Str::uuid(),
            'idempotency_key'=>$key,
            'actor_id'=>$data['actor_id']??null,
            'organization_id'=>$data['organization_id']??null,
            'project_id'=>$data['project_id']??null,
            'tool_id'=>$data['tool_id']??null,
            'tool_version_id'=>$data['tool_version_id']??null,
            'capability'=>$data['capability']??'unknown',
            'provider'=>$data['provider']??null,
            'unit'=>$data['unit']??'unit',
            'quantity'=>max(0,(float)($data['quantity']??0)),
            'cost_amount'=>max(0,(int)($data['cost_amount']??0)),
            'currency'=>$data['currency']??'IRR',
            'duration_ms'=>$data['duration_ms']??null,
            'input_bytes'=>$data['input_bytes']??null,
            'output_bytes'=>$data['output_bytes']??null,
            'metadata'=>$data['metadata']??null,
        ]);
    }
}