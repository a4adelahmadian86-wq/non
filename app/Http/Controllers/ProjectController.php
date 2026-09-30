<?php

namespace App\Http\Controllers;

use App\Models\FarastProject;
use App\Services\CapabilityService;
use App\Services\ProjectBillingService;
use App\Services\ProjectInterviewService;
use App\Services\PricingService;
use App\Services\UserKnowledgeService;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function create(ProjectInterviewService $interview, ProjectBillingService $billing, PricingService $pricing)
    {
        $subscription = $billing->includedAllowance(request()->user(), 'manual');
        return view('projects.create', [
            'types' => $interview::TYPES,
            'templates' => $interview::TEMPLATES,
            'subscriber' => $subscription['subscriber'],
            'initialPrices' => $pricing->initialTypingPrices(),
        ]);
    }

    public function store(Request $request, ProjectInterviewService $interview, UserKnowledgeService $knowledge, ProjectBillingService $billing)
    {
        $data = $request->validate([
            'project_type' => ['required','string','in:typing'],
            'template' => ['required','string','in:'.implode(',',array_keys($interview::TEMPLATES))],
            'workflow' => ['required','string','in:manual,voice,source_file'],
            'source_type' => ['nullable','string','in:printed,handwritten,mixed'],
            'language' => ['nullable','string','max:32'],
            'estimated_pages' => ['nullable','integer','min:1','max:100000'],
            'name' => ['nullable','string','max:255'],
            'special_requirements' => ['nullable','string','max:2000'],
        ]);

        $context = $interview->buildContext($data,$knowledge->snapshotForProject($request->user()));
        $project = FarastProject::create([
            'user_id'=>$request->user()->id,
            'name'=>($data['name'] ?? '') ?: 'پروژه تایپ جدید',
            'project_type'=>'typing','workflow'=>$context['workflow'],'template_code'=>$context['template'],
            'status'=>'active','context'=>$context,
            'billing_state'=>['status'=>'estimated'],
            'output_state'=>['status'=>'locked','reason'=>'entitlement_pending'],
        ]);
        $estimatePages=(int)($context['estimated_pages'] ?? 1);
        $quote=$billing->applyQuote($project,$estimatePages);
        return response()->json([
            'ok'=>true,'project_id'=>$project->id,'next_url'=>route('editor',['project'=>$project->id]),
            'context'=>$project->fresh()->context,'quote'=>$quote['quote'],'entitlement'=>$quote['entitlement'],
        ]);
    }
}
