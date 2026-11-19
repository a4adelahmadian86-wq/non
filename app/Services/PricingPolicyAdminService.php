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
        $payload['checksum']=$this->checksumForAttributes($payload);
        return FarastPricingPolicyVersion::create($payload);
    }

    public function schedule(FarastPricingPolicyVersion $version,\DateTimeInterface $from,?\DateTimeInterface $until=null): FarastPricingPolicyVersion
    {
        if($until&&$until<=$from)throw new RuntimeException('invalid_pricing_window');
        $version->effective_from=$from;$version->effective_until=$until;$version->checksum=$this->checksumForVersion($version);$version->save();
        return $version->fresh();
    }

    public function retire(FarastPricingPolicyVersion $version,?\DateTimeInterface $at=null): FarastPricingPolicyVersion
    {
        $at=$at?:now();
        $version->effective_until=$at;
        $version->checksum=$this->checksumForVersion($version);
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
            $retiredAt = now();
            $futureScheduled = FarastPricingPolicyVersion::where('policy_code', $version->policy_code)
                ->where('region_code', $version->region_code)
                ->where('id', '!=', $version->id)
                ->where('effective_from', '>', $retiredAt)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $retiredAt))
                ->exists();
            if ($futureScheduled) {
                throw new RuntimeException('future_pricing_policy_version_exists');
            }

            $previousVersions = FarastPricingPolicyVersion::where('policy_code',$version->policy_code)
                ->where('region_code',$version->region_code)->where('id','!=',$version->id)
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $retiredAt))
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>', $retiredAt))
                ->lockForUpdate()->get();
            foreach ($previousVersions as $previous) {
                $previous->effective_until = $retiredAt;
                $previous->checksum = $this->checksumForVersion($previous);
                $previous->save();
            }
            if(!$version->effective_from||$version->effective_from->isFuture()) {
                $version->effective_from = $retiredAt;
                $version->checksum = $this->checksumForVersion($version);
            }
            $version->save();
            return $version->fresh();
        });
    }
    /**
     * Create an immutable version from an existing legacy admin price field.
     * The key map is intentionally allow-listed: arbitrary request keys cannot
     * create executable pricing policies.
     */
    public function createVersionFromPricingRule(string $key, int $value, string $label): FarastPricingPolicyVersion
    {
        $definitions = [
            'page_base' => ['unit' => 'page', 'capability' => 'typing.manual'],
            'typing_manual_page' => ['unit' => 'page', 'capability' => 'typing.manual'],
            'typing_voice_page' => ['unit' => 'page', 'capability' => 'typing.voice'],
            'typing_printed_page' => ['unit' => 'page', 'capability' => 'typing.source_file'],
            'typing_handwritten_page' => ['unit' => 'page', 'capability' => 'typing.source_file'],
            'typing_mixed_page' => ['unit' => 'page', 'capability' => 'typing.source_file'],
            'typing_voice_minute' => ['unit' => 'minute', 'capability' => 'typing.voice'],
            'typing_payg_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.payg'],
            'english_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'arabic_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'mixed_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'dense_page_multiplier' => ['unit' => 'percentage', 'capability' => 'typing.manual'],
            'formula_unit' => ['unit' => 'formula', 'capability' => 'typing.manual'],
        ];

        if (!isset($definitions[$key])) {
            throw new RuntimeException('unsupported_legacy_pricing_rule');
        }

        $value = max(0, $value);
        return DB::transaction(function () use ($key, $value, $label, $definitions) {
            $version = $this->createVersion([
                'policy_code' => $key,
                'capability_code' => $definitions[$key]['capability'],
                'unit' => $definitions[$key]['unit'],
                'currency' => 'IRR',
                'unit_price' => $key === 'typing_payg_multiplier' ? 0 : $value,
                'payg_multiplier_basis_points' => $key === 'typing_payg_multiplier' ? max(10000, $value * 100) : 10000,
                'metadata' => [
                    'source' => 'admin_pricing_rule',
                    'source_key' => $key,
                    'source_label' => $label,
                ],
            ]);

            return $this->activate($version);
        });
    }

    private function checksumForVersion(FarastPricingPolicyVersion $version): string
    {
        return $this->checksumForAttributes($version->only([
            'policy_code','version','capability_code','unit','currency','unit_price','additional_unit_price','fee_amount',
            'discount_basis_points','tax_basis_points','payg_multiplier_basis_points','included_quantity','region_code',
            'effective_from','effective_until','metadata',
        ]));
    }

    private function checksumForAttributes(array $attributes): string
    {
        $fields = [
            'policy_code','version','capability_code','unit','currency','unit_price','additional_unit_price','fee_amount',
            'discount_basis_points','tax_basis_points','payg_multiplier_basis_points','included_quantity','region_code',
            'effective_from','effective_until','metadata',
        ];
        $payload = [];
        foreach ($fields as $field) {
            $value = $attributes[$field] ?? null;
            if (in_array($field, ['effective_from', 'effective_until'], true) && $value !== null) {
                $value = $value instanceof \DateTimeInterface
                    ? $value->format(DATE_ATOM)
                    : \Illuminate\Support\Carbon::parse($value)->toIso8601String();
            } elseif ($field === 'included_quantity' && $value !== null) {
                $value = (float) $value;
            } elseif ($field === 'metadata' && is_string($value)) {
                $value = json_decode($value, true) ?? [];
            }
            $payload[$field] = $value;
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

}
