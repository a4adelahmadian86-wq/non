<?php

namespace App\Services;

use App\Models\FarastProject;
use App\Models\TypingDocument;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectBillingService
{
    public function __construct(private PricingService $pricing) {}

    public function quote(FarastProject $project, int $pages, ?string $text = null): array
    {
        $context = $project->context ?? [];
        $subscription = $this->includedAllowance($project->user, (string)($context['workflow'] ?? 'manual'));
        return $this->pricing->projectQuote(
            (string)($context['workflow'] ?? 'manual'),
            (string)($context['source_type'] ?? ''),
            max(1,$pages),
            $text ?? '',
            !$subscription['subscriber']
        );
    }

    public function entitlement(User $user, FarastProject $project, int $amount): array
    {
        $included = $this->includedAllowance($user, (string)($project->context['workflow'] ?? 'manual'));
        $unit = $this->pricing->projectQuote((string)($project->context['workflow'] ?? 'manual'), (string)($project->context['source_type'] ?? ''), 1)['unit_price_rials'];
        $coveredPages = min($included['remaining'], max(0, (int)($project->context['estimated_pages'] ?? 1)));
        $covered = min(max(0,$amount), $coveredPages * $unit);
        $additional = max(0,$amount-$covered);
        return [
            'subscriber' => $included['subscriber'],
            'included_rials' => $covered,
            'additional_rials' => $additional,
            'upgrade_available' => $additional > 0 && $included['subscriber'],
            'included_pages' => $coveredPages,
            'payg_available' => true,
            'status' => $additional > 0 ? 'payment_required' : 'included',
        ];
    }

    public function includedAllowance(User $user, string $workflow): array
    {
        $query = DB::table('farast_subscriptions')->join('farast_plans','farast_plans.id','=','farast_subscriptions.plan_id')
            ->where('farast_subscriptions.user_id',$user->id)->where('farast_subscriptions.status','active')
            ->where(function($q){$q->whereNull('farast_subscriptions.ends_at')->orWhere('farast_subscriptions.ends_at','>',now());})
            ->orderByDesc('farast_subscriptions.id')->first();
        if (!$query) return ['subscriber'=>false,'remaining'=>0,'plan'=>null];
        $quotas = is_string($query->quotas ?? null) ? json_decode($query->quotas,true) : ($query->quotas ?? []);
        $limit = (int)($quotas['typing_pages'] ?? $quotas['pages'] ?? 0);
        $used = (int)DB::table('farast_usage_counters')->where('user_id',$user->id)->where('metric','typing_pages')->where('period_start','<=',now()->toDateString())->where('period_end','>=',now()->toDateString())->sum('used');
        return ['subscriber'=>true,'remaining'=>max(0,$limit-$used),'plan'=>$query->code ?? null];
    }

    public function applyQuote(FarastProject $project, int $pages, string $text = ''): array
    {
        $quote = $this->quote($project,$pages,$text);
        $ent = $this->entitlement($project->user,$project,$quote['price_rials']);
        $project->forceFill([
            'estimated_pages'=>$pages,'used_pages'=>$pages,'estimated_price_rials'=>$quote['price_rials'],
            'billing_state'=>['status'=>$ent['status'],'quote'=>$quote,'entitlement'=>$ent,'amount_remaining'=>$ent['additional_rials']],
            'output_state'=>['status'=>$ent['additional_rials'] > 0 ? 'locked' : 'unlocked','reason'=>$ent['additional_rials'] > 0 ? 'payment_required' : 'included'],
        ])->save();
        return ['quote'=>$quote,'entitlement'=>$ent];
    }

    public function markPaid(FarastProject $project, int $amount): void
    {
        $project->forceFill(['paid_rials'=>max(0,(int)$project->paid_rials+$amount),'billing_state'=>array_merge($project->billing_state ?? [],['status'=>'paid','amount_remaining'=>0])])->save();
    }
}
