<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FarastInvoice extends Model
{
    protected $table = 'farast_invoices';
    protected $fillable = [
        'invoice_number','actor_id','organization_id','currency','subtotal','discount','fee','tax',
        'total','status','issued_at','paid_at','snapshot',
    ];
    protected function casts(): array
    {
        return ['subtotal'=>'integer','discount'=>'integer','fee'=>'integer','tax'=>'integer','total'=>'integer','issued_at'=>'datetime','paid_at'=>'datetime','snapshot'=>'array'];
    }
    public function items(): HasMany { return $this->hasMany(FarastInvoiceItem::class, 'invoice_id'); }
}