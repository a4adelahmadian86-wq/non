<?php
namespace App\Console\Commands;
use App\Models\TypingDocument;use Illuminate\Console\Command;use Illuminate\Support\Facades\Storage;
class PurgeTypingFiles extends Command {protected $signature='farast:purge-files';protected $description='Remove expired private typing sources';public function handle(){TypingDocument::whereNotNull('expires_at')->where('expires_at','<',now())->chunkById(100,function($docs){foreach($docs as $d){if($d->source_path)Storage::disk('private')->delete($d->source_path);$d->update(['source_path'=>null,'status'=>'expired']);}});$this->info('Expired typing files purged.');return self::SUCCESS;}}
