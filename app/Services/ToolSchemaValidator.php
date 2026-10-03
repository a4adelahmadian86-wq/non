<?php

namespace App\Services;

use InvalidArgumentException;

class ToolSchemaValidator
{
    public function validate(array $payload,array $schema,string $path='$'):void
    {
        if(($schema['type']??'object')!=='object')return;
        foreach(($schema['required']??[]) as $key)
            if(!array_key_exists($key,$payload))throw new InvalidArgumentException("schema_required:{$path}.{$key}");
        foreach(($schema['properties']??[]) as $key=>$definition){
            if(!array_key_exists($key,$payload))continue;
            $this->assertType($payload[$key],(string)($definition['type']??'any'),"{$path}.{$key}");
            if(($definition['type']??null)==='string'&&isset($definition['maxLength'])&&mb_strlen((string)$payload[$key])>(int)$definition['maxLength'])
                throw new InvalidArgumentException("schema_max_length:{$path}.{$key}");
        }
    }

    private function assertType(mixed $value,string $type,string $path):void
    {
        $ok=match($type){
            'string'=>is_string($value),'integer'=>is_int($value),'number'=>is_int($value)||is_float($value),
            'boolean'=>is_bool($value),'array'=>is_array($value)&&array_is_list($value),'object'=>is_array($value),default=>true
        };
        if(!$ok)throw new InvalidArgumentException("schema_type:{$path}:{$type}");
    }
}