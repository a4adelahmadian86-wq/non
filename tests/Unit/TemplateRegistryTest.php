<?php
namespace Tests\Unit;
use App\Services\TemplateRegistry;
use Tests\TestCase;
class TemplateRegistryTest extends TestCase {
 public function test_simple_typing_template_is_centralized_and_persian_first():void{$t=app(TemplateRegistry::class)->get('simple_typing');$this->assertSame('A4',$t['settings']['paper']);$this->assertSame('rtl',$t['settings']['direction']);$this->assertSame('B Nazanin',$t['settings']['font_family']);$this->assertSame(16,$t['settings']['font_size']);}
}