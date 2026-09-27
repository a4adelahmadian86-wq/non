<?php

namespace App\Services;

use App\Models\PricingRule;

class PricingService
{
    public function quote(string $text, int $pages): array
    {
        $rules = PricingRule::where('active', true)->pluck('value', 'key');
        $pages = max(1, $pages);
        $base = (int)($rules['page_base'] ?? 35000);
        $stats = $this->stats($text);
        $stats['words_per_page'] = $stats['word_count'] / $pages;
        $factor = 1.0;
        if ($stats['english_ratio'] >= 0.70) $factor = max($factor, (int)($rules['english_multiplier'] ?? 120) / 100);
        elseif ($stats['arabic_ratio'] >= 0.70) $factor = max($factor, (int)($rules['arabic_multiplier'] ?? 110) / 100);
        elseif ($stats['mixed_ratio'] >= 0.35) $factor = max($factor, (int)($rules['mixed_multiplier'] ?? 105) / 100);
        if ($stats['words_per_page'] > 650) $factor = max($factor, (int)($rules['dense_page_multiplier'] ?? 110) / 100);
        $pageUnit = (int)round($base * $factor);
        $formulaUnit = (int)($rules['formula_unit'] ?? 5000);
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
        $pageUnit = max(0, (int)($rules['page_base'] ?? 35000));
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
