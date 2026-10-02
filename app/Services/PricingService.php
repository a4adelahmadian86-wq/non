<?php

namespace App\Services;

use App\Models\PricingRule;
use App\Models\FarastPricingPolicy;

class PricingService
{
    public function projectQuote(string $workflow, string $sourceType, int $pages, string $text = '', bool $payg = false, ?int $audioMinutes = null): array
    {
        $rules = PricingRule::where('active', true)->pluck('value', 'key');
        $pages = max(1, $pages);
        $key = match ($workflow) {
            'voice' => 'typing_voice_page',
            'source_file' => match ($sourceType) { 'handwritten' => 'typing_handwritten_page', 'mixed' => 'typing_mixed_page', default => 'typing_printed_page' },
            default => 'typing_manual_page',
        };
        $unit = max(0, $this->ruleValue($key, 0));
        $audio = 0;
        if ($workflow === 'voice' && $audioMinutes !== null) {
            $perMinute = max(0, $this->ruleValue('typing_voice_minute', 0));
            $audio = max(0, $audioMinutes) * $perMinute;
        }
        $multiplier = $payg ? max(100, $this->ruleValue('typing_payg_multiplier', 100)) : 100;
        $line = (int)round(($pages * $unit + $audio) * $multiplier / 100);
        return [
            'pages'=>$pages,'unit_price_rials'=>$unit,'free_page_value_rials'=>$unit,'price_rials'=>$line,'currency'=>'IRR',
            'workflow'=>$workflow,'source_type'=>$sourceType ?: null,'payg'=>$payg,
            'audio_minutes'=>$audioMinutes,'audio_cost_rials'=>$audio,
            'breakdown'=>['unit'=>$unit,'pages'=>$pages,'audio_cost'=>$audio,'multiplier'=>$multiplier],
        ];
    }

    public function initialTypingPrices(): array
    {
        return [
            'manual'=>$this->ruleValue('typing_manual_page', 0),
            'voice'=>$this->ruleValue('typing_voice_page', 0),
            'printed'=>$this->ruleValue('typing_printed_page', 0),
            'handwritten'=>$this->ruleValue('typing_handwritten_page', 0),
            'mixed'=>$this->ruleValue('typing_mixed_page', 0),
            'payg_multiplier_percent'=>$this->ruleValue('typing_payg_multiplier', 100),
            'voice_minute'=>$this->ruleValue('typing_voice_minute', 0),
        ];
    }

    public function calculate(string $policyCode, int $units, bool $subscription = false): array
    {
        $units=max(0,$units);
        $policy=FarastPricingPolicy::where('code',$policyCode)->where('active',true)->first();
        if(!$policy){
            return ['policy'=>$policyCode,'units'=>$units,'base_price_rials'=>0,'subscription_allowance'=>0,'included_units'=>0,'billable_units'=>$units,'additional_usage_rials'=>0,'discount_rials'=>0,'fee_rials'=>0,'final_price_rials'=>0];
        }
        $included=$subscription?min($units,max(0,(int)$policy->subscription_allowance)):0;
        $billable=max(0,$units-$included);
        $additionalPrice=max(0,(int)($policy->additional_price_rials??$policy->base_price_rials));
        $subtotal=$billable*$additionalPrice;
        $discount=min($subtotal,(int)round($subtotal*max(0,min(100,(float)$policy->discount_percent))/100));
        $fee=$billable>0?max(0,(int)$policy->fee_rials):0;
        $total=max(0,$subtotal-$discount+$fee);
        return ['policy'=>$policyCode,'unit'=>'page','units'=>$units,'base_price_rials'=>(int)$policy->base_price_rials,'subscription_allowance'=>$included,'included_units'=>$included,'billable_units'=>$billable,'additional_usage_rials'=>$subtotal,'discount_rials'=>$discount,'fee_rials'=>$fee,'final_price_rials'=>$total,'pricing_policy_version_id'=>null,'currency'=>'IRR'];
    }

    public function quote(string $text, int $pages): array
    {
        $rules = PricingRule::where('active', true)->pluck('value', 'key');
        $pages = max(1, $pages);
        $base = $this->ruleValue('page_base', 0);
        $stats = $this->stats($text);
        $stats['words_per_page'] = $stats['word_count'] / $pages;
        $factor = 1.0;
        if ($stats['english_ratio'] >= 0.70) $factor = max($factor, $this->ruleValue('english_multiplier', 100) / 100);
        elseif ($stats['arabic_ratio'] >= 0.70) $factor = max($factor, $this->ruleValue('arabic_multiplier', 100) / 100);
        elseif ($stats['mixed_ratio'] >= 0.35) $factor = max($factor, $this->ruleValue('mixed_multiplier', 100) / 100);
        if ($stats['words_per_page'] > 650) $factor = max($factor, $this->ruleValue('dense_page_multiplier', 100) / 100);
        $pageUnit = (int)round($base * $factor);
        $formulaUnit = $this->ruleValue('formula_unit', 0);
        $formulaCost = $stats['formula_units'] * $formulaUnit;
        $price = (int)round($pages * $pageUnit + $formulaCost);
        return [
            'pages'=>$pages,'chargeable_pages'=>max(0,$pages-1),'word_count'=>$stats['word_count'],'price_rials'=>$price,'free_page_value_rials'=>$pageUnit,
            'breakdown'=>['base_page'=>$base,'page_unit'=>$pageUnit,'language_factor'=>round($factor,2),'formula_units'=>$stats['formula_units'],'formula_unit_price'=>$formulaUnit,'formula_cost'=>$formulaCost,'words_per_page'=>round($stats['words_per_page'],1),'english_words'=>$stats['english_words'],'arabic_words'=>$stats['arabic_words'],'persian_words'=>$stats['persian_words'],'number_tokens'=>$stats['number_tokens']]
        ];
    }

    public function estimateByPages(int $pages): array
    {
        $rules = PricingRule::where('active', true)->pluck('value', 'key');
        $pages = max(1, $pages);
        $pageUnit = max(0, $this->ruleValue('page_base', 0));
        $price = $pages * $pageUnit;

        return [
            'pages' => $pages,
            'price_rials' => $price,
            'free_page_value_rials' => $pageUnit,
            'is_estimate' => true,
            'breakdown' => [
                'base_page' => $pageUnit,
                'page_unit' => $pageUnit,
                'language_factor' => null,
                'formula_cost' => null,
            ],
        ];
    }

    private function ruleValue(string $key, int $fallback = 0): int
    {
        $version = app(PricingEngine::class)->resolve($key);
        if ($version) {
            if ($key === 'typing_payg_multiplier') return max(100, (int) round($version->payg_multiplier_basis_points / 100));
            if ($version->unit === 'percentage' && (int)$version->unit_price === 0) {
                return max(0, (int) round($version->payg_multiplier_basis_points / 100));
            }
            return max(0, (int)$version->unit_price);
        }
        $policy = FarastPricingPolicy::where('code', $key)->where('active', true)->first();
        if ($policy) {
            if ($key === 'typing_payg_multiplier') return max(100, (int)($policy->payg_multiplier_percent ?: $policy->base_price_rials));
            return max(0, (int)$policy->base_price_rials);
        }
        return (int) PricingRule::where('key', $key)->where('active', true)->value('value') ?: $fallback;
    }

    private function stats(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:\x{200c}[\p{L}\p{N}]+)*/u', trim($text), $m);
        $tokens=$m[0]??[]; $en=$ar=$fa=$numbers=0;
        foreach($tokens as $token){
            if(preg_match('/^\p{N}+$/u',$token)){ $numbers++; continue; }
            if(preg_match('/^[A-Za-z]/u',$token)) $en++;
            elseif(preg_match('/[\x{0671}-\x{06FF}]/u',$token)) $fa++;
            elseif(preg_match('/[\x{0600}-\x{0670}]/u',$token)) $ar++;
        }
        $formula=preg_match_all('/(?:[A-Za-z\x{0600}-\x{06FF}\p{N}]+\s*[=+\-*\/^]\s*[A-Za-z\x{0600}-\x{06FF}\p{N}]+)/u',$text)?:0;
        $total=max(1,count($tokens));
        return ['word_count'=>count($tokens),'english_words'=>$en,'arabic_words'=>$ar,'persian_words'=>$fa,'number_tokens'=>$numbers,'formula_units'=>$formula,'english_ratio'=>$en/$total,'arabic_ratio'=>$ar/$total,'mixed_ratio'=>($en+$ar)/$total];
    }
}
