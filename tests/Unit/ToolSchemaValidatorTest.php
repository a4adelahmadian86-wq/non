<?php

namespace Tests\Unit;

use App\Services\ToolSchemaValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ToolSchemaValidatorTest extends TestCase
{
    public function test_required_and_type_validation_are_enforced():void
    {
        $schema=['type'=>'object','required'=>['text'],'properties'=>['text'=>['type'=>'string'],'count'=>['type'=>'integer']]];
        $v=new ToolSchemaValidator();$v->validate(['text'=>'سلام','count'=>2],$schema);
        $this->expectException(InvalidArgumentException::class);$v->validate(['text'=>'سلام','count'=>'2'],$schema);
    }
    public function test_missing_required_field_is_rejected():void
    {
        $this->expectException(InvalidArgumentException::class);(new ToolSchemaValidator())->validate([],['type'=>'object','required'=>['text']]);
    }
}