<?php

namespace App\Services;

use App\Models\FarastCoupon;
use App\Models\FarastCouponRedemption;
use App\Models\FarastPromotion;
use App\Models\User;

class PromotionService
{
    public function trustedAdjustments(User $actor, string $capability, float $quantity, int $subtotal, array $context=[]): array
    {
        $code=trim((string)($context['coupon_code']??''));
        $promotionCode=trim((string)($context['promotion_code']??''));
        $discountBp=0;$fixed=0;$meta=[];
        if($code!==''){
            $coupon=FarastCoupon::where('code',$code)->where('active',true)->first();
            if(!$coupon)return [];
            $now=now();
            if($coupon->starts_at&&$now->lt($coupon->starts_at))return [];
            if($coupon->ends_at&&$now->gte($coupon->ends_at))return [];
            if($coupon->usage_limit!==null&&FarastCouponRedemption::where('coupon_code',$code)->count()>=$coupon->usage_limit)return [];
            if($coupon->per_actor_limit!==null&&FarastCouponRedemption::where('coupon_code',$code)->where('actor_id',$actor->id)->count()>=$coupon->per_actor_limit)return [];
            [$discountBp,$fixed]= $this->value($coupon->discount_type,(int)$coupon->discount_value,$subtotal,$coupon->max_discount);
            $meta=['coupon_code'=>$code];
        }elseif($promotionCode!==''){
            $promotion=FarastPromotion::where('code',$promotionCode)->where('active',true)->first();
            if(!$promotion)return [];
            $now=now();
            if($promotion->starts_at&&$now->lt($promotion->starts_at))return [];
            if($promotion->ends_at&&$now->gte($promotion->ends_at))return [];
            if($promotion->capability_code&&$promotion->capability_code!==$capability)return [];
            if($promotion->usage_limit!==null&&FarastCouponRedemption::where('coupon_code',$promotionCode)->count()>=$promotion->usage_limit)return [];
            [$discountBp,$fixed]=$this->value($promotion->discount_type,(int)$promotion->discount_value,$subtotal,$promotion->max_discount);
            $meta=['promotion_code'=>$promotionCode];
        }
        return ['discount_basis_points'=>$discountBp,'fixed_discount_amount'=>$fixed,'metadata'=>$meta];
    }

    private function value(string $type,int $value,int $subtotal,?int $max):array
    {
        if($type==='fixed')return [0,max(0,min($subtotal,$max===null?$value:min($value,$max)))];
        return [max(0,min(10000,$value)),0];
    }
}