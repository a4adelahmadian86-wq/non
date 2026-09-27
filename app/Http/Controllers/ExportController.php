<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\TypingDocument;
use App\Services\CapabilityService;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;
use Dompdf\Dompdf;

class ExportController extends Controller
{
    public function export(Request $r, string $format, CapabilityService $capabilities)
    {
        $key=$format==='docx'?'can_export_docx':($format==='pdf'?'can_export_pdf':null);
        abort_unless($key&&$capabilities->allowed(auth()->user(),$key),403,'این نوع خروجی برای حساب شما فعال نیست.');
        $id=$r->validate(['document_id'=>'required|integer'])['document_id'];
        $doc=TypingDocument::whereKey($id)->where('user_id',auth()->id())->firstOrFail();
        abort_unless($doc->status!=='deleted',404);
        if(!auth()->user()->isAdmin()){
            $hash=hash('sha256',(string)$doc->content);
            $paid=Order::where('user_id',auth()->id())->where('document_id',$doc->id)->where('status','paid')->whereNotNull('paid_at')->latest('paid_at')->first();
            abort_unless($paid && hash_equals((string)$paid->content_hash,$hash),402,'ابتدا متن نهایی را بازبینی کنید و هزینه خروجی را در صفحه پرداخت تسویه کنید.');
        }
        $content=$this->cleanExportHtml((string)$doc->content);
        if($format==='docx')return $this->docx($doc,$content);
        $html='<html dir="rtl"><head><meta charset="utf-8"><style>@page{size:A4;margin:25.4mm}body{font-family:"B Nazanin",BNazanin,Tahoma,sans-serif;font-size:16px;direction:rtl;text-align:right;line-height:1.15;color:#111}p{margin:0 0 8pt}h1{font-size:25px;margin:0 0 12pt}h2{font-size:21px;margin:0 0 10pt}h3{font-size:18px;margin:0 0 8pt}blockquote{border-right:3px solid #999;margin:10px 0;padding:6px 12px}a{color:#111;text-decoration:underline}</style></head><body>'.$content.'</body></html>';
        $pdf=new Dompdf(['isRemoteEnabled'=>false]);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();$canvas=$pdf->getCanvas();$canvas->page_script(function($pageNumber,$pageCount,$canvas,$fontMetrics){$margin=28.8;$canvas->rectangle($margin,$margin,$canvas->get_width()-($margin*2),$canvas->get_height()-($margin*2),[0.85,0.87,0.90],0.7);});return response($pdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="farast-'.$doc->id.'.pdf"']);
    }
    private function docx(TypingDocument $doc,string $content){$w=new PhpWord();$w->setDefaultFontName('B Nazanin');$w->setDefaultFontSize(16);$w->setDefaultParagraphStyle(['lineSpacing'=>276,'spaceAfter'=>160,'alignment'=>'right']);$s=$w->addSection(['paperSize'=>'A4','marginTop'=>1440,'marginBottom'=>1440,'marginLeft'=>1440,'marginRight'=>1440,'borderSize'=>4,'borderColor'=>'D9DDE3','borderStyle'=>'single']);PhpWordHtml::addHtml($s,$content,false,false);$tmp=tempnam(sys_get_temp_dir(),'farast');IOFactory::createWriter($w,'Word2007')->save($tmp);return response()->download($tmp,'farast-'.$doc->id.'.docx')->deleteFileAfterSend(true);}
    private function cleanExportHtml(string $html):string{$html=preg_replace('/<span\b[^>]*class=(?:"[^"]*\bai-uncertain\b[^"]*"|\'[^\']*\bai-uncertain\b[^\']*\')[^>]*>(.*?)<\/span>/isu','$1',$html)??$html;$html=preg_replace('/\sdata-(?:original|suggestions)=(?:"[^\"]*"|\'[^\']*\')/iu','',$html)??$html;return$html;}
}
