<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreDownload extends Model
{
    protected $fillable = ['library_item_id','user_id','product_file_id','token_hash','expires_at','download_count','ip_hash','user_agent_hash'];
    protected $casts = ['expires_at'=>'datetime','download_count'=>'integer'];
    public function libraryItem(): BelongsTo { return $this->belongsTo(StoreLibraryItem::class, 'library_item_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function file(): BelongsTo { return $this->belongsTo(StoreProductFile::class, 'product_file_id'); }
}
