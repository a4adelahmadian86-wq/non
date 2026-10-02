<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarastInvoiceItem extends Model
{
    protected $table = 'farast_invoice_items';
    protected $fillable = [
        'invoice_id','charge_id','description','quantity','unit','unit_price','subtotal','discount',
        'fee','tax','total','currency','snapshot',
    ];
    protected function casts(): array
    {
        return ['quantity'=>'decimal:6','unit_price'=>'integer','subtotal'=>'integer','discount'=>'integer','fee'=>'integer','tax'=>'integer','total'=>'integer','snapshot'=>'array'];
    }
}