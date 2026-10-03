<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class UploadSecurityService
{
    private array $allowed=['image/jpeg','image/png','image/webp','image/gif','application/pdf','application/zip'];

    public function inspect(string $path,?string $expectedMime=null,?int $maxBytes=null):array
    {
        $disk=Storage::disk('private'); if(!$disk->exists($path))throw new RuntimeException('upload_not_found');
        $size=(int)$disk->size($path); $limit=$maxBytes??(int)config('farast.security.max_upload_bytes',2*1024*1024*1024);
        if($size>$limit)throw new RuntimeException('upload_too_large');
        $mime=strtolower((string)$disk->mimeType($path)); if(!in_array($mime,$this->allowed,true))throw new RuntimeException('upload_mime_rejected');
        if($expectedMime&&$expectedMime!==$mime)throw new RuntimeException('upload_mime_mismatch');
        $ext=strtolower(pathinfo($path,PATHINFO_EXTENSION)); if(in_array($ext,['php','phar','phtml','exe','js','html','htm','svg'],true))throw new RuntimeException('upload_extension_rejected');
        $bytes=$disk->get($path); $hash=hash('sha256',$bytes);
        $clam=(string)env('FARAST_CLAMSCAN_BINARY','');$scan='not_configured';
        if($clam){$command=escapeshellcmd($clam).' --no-summary '.escapeshellarg($disk->path($path));exec($command,$out,$code);if($code!==0)throw new RuntimeException('upload_malware_scan_failed');$scan='clean';}
        return ['safe'=>true,'mime'=>$mime,'size'=>$size,'checksum'=>$hash,'scan'=>$scan,'sandbox_required'=>true,'storage'=>'private'];
    }
}