<?php

namespace App\Services;

use App\Models\FarastPricingPolicyVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class PricingPolicyAdminService
{
    public function createVersion(array $data): FarastPricingPolicyVersion
    {
        foreach(['policy_code','unit'] as $key)if(empty($data[$key]))throw new RuntimeException($key.'_required');
        $code=(string)$data['policy_code'];
        $version=((int)FarastPricingPolicyVersion::where('policy_code',$code)->max('version'))+1;
        $payload=[
            'policy_code'=>$code,'version'=>$version,'capability_code'=>$data['capability_code']??null,
            'unit'=>(string)$data['unit'],'currency'=>(string)($data['currency']??'IRR'),
            'unit_price'=>max(0,(int)($data['unit_price']??0)),
            'additional_unit_price'=>array_key_exists('additional_unit_price',$data)&&$data['additional_unit_price']!==null?max(0,(int)$data['additional_unit_price']):null,
            'fee_amount'=>max(0,(int)($data['fee_amount']??0)),
            'discount_basis_points'=>max(0,min(10000,(int)($data['discount_basis_points']??0))),
            'tax_basis_points'=>max(0,min(10000,(int)($data['tax_basis_points']??0))),
            'payg_multiplier_basis_points'=>max(10000,(int)($data['payg_multiplier_basis_points']??10000)),
            'included_quantity'=>max(0,(float)($data['included_quantity']??0)),
            'region_code'=>$data['region_code']??null,
            'effective_from'=>$data['effective_from']??now(),
            'effective_until'=>$data['effective_until']??null,
            'metadata'=>$data['metadata']??[],
        ];
        $payload['checksum']=hash('sha256',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
        return FarastPricingPolicyVersion::create($payload);
    }

    public function schedule(FarastPricingPolicyVersion $version,\DateTimeInterface $from,?\DateTimeInterface $until=null): FarastPricingPolicyVersion
    {
        if($until&&$until<=$from)throw new RuntimeException('invalid_pricing_window');
        $version->effective_from=$from;$version->effective_until=$until;$version->save();
        return $version->fresh();
    }

    public function retire(FarastPricingPolicyVersion $version,?\DateTimeInterface $at=null): FarastPricingPolicyVersion
    {
        $at=$at?:now();
        $version->effective_until=$at;
        $version->save();
        return $version->fresh();
    }

    public function compare(FarastPricingPolicyVersion $a,FarastPricingPolicyVersion $b):array
    {
        $fields=['policy_code','version','capability_code','unit','currency','unit_price','additional_unit_price','fee_amount',
            'discount_basis_points','tax_basis_points','payg_multiplier_basis_points','included_quantity','region_code',
            'effective_from','effective_until','metadata','checksum'];
        $left=$a->only($fields);$right=$b->only($fields);$diff=[];
        foreach($fields as $field)if($left[$field] instanceof \DateTimeInterface)$left[$field]=$left[$field]->format(DATE_ATOM);
        foreach($fields as $field)if($right[$field] instanceof \DateTimeInterface)$right[$field]=$right[$field]->format(DATE_ATOM);
        foreach($fields as $field)if($left[$field]!=$right[$field])$diff[$field]=['left'=>$left[$field],'right'=>$right[$field]];
        return ['left'=>$left,'right'=>$right,'diff'=>$diff];
    }

    public function activate(FarastPricingPolicyVersion $version): FarastPricingPolicyVersion
    {
        return DB::transaction(function()use($version){
            FarastPricingPolicyVersion::where('policy_code',$version->policy_code)
                ->where('region_code',$version->region_code)->where('id','!=',$version->id)
                ->whereNull('effective_until')->update(['effective_until'=>now()]);
            if(!$version->effective_from||$version->effective_from->isFuture())$version->effective_from=now();
            $version->save();
            return $version->fresh();
        });
    }
}
