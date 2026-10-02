<?php

namespace App\Services;

use App\Models\Order;
use App\Models\FarastProject;
use App\Models\TypingDocument;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OutputAuthorizationService
{
    public function __construct(private CommerceAuthorizationService $commerce) {}

    public function authorize(User $user, TypingDocument $document, string $format): array
    {
        if ($user->isAdmin()) return ['allowed'=>true,'mode'=>'administrator','reason'=>null];

        $hash=hash('sha256',(string)$document->content);
        $project=$document->project_id
            ? FarastProject::whereKey($document->project_id)->where('user_id',$user->id)->first()
            : null;

        if ($project && ($project->output_state['status'] ?? null)==='unlocked') {
            return ['allowed'=>true,'mode'=>'project_unlocked','reason'=>null,'project_id'=>$project->id];
        }

        $paid=Order::where('user_id',$user->id)
            ->where('document_id',$document->id)
            ->where('status','paid')
            ->whereNotNull('paid_at')
            ->latest('paid_at')->first();

        if ($paid && hash_equals((string)$paid->content_hash,$hash)) {
            return ['allowed'=>true,'mode'=>'paid_order','reason'=>null,'order_id'=>$paid->id];
        }

        $decision=$this->commerce->authorize(
            $user,
            'export.'.$format,
            ['project_id'=>$project?->id,'document_id'=>$document->id],
            1,
            ['policy_code'=>'export.'.$format,'unit'=>'export','allow_payg'=>false]
        );

        if (!$decision->allowed) {
            throw new HttpException(402,'خروجی نهایی برای این سند مجاز نیست؛ سهمیه، entitlement یا پرداخت موردنیاز تکمیل نشده است.');
        }

        return ['allowed'=>true,'mode'=>$decision->mode,'reason'=>null,'decision'=>$decision->toArray()];
    }
}