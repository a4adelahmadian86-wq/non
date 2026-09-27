<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index()
    {
        $wallet = auth()->user()->wallet()->firstOrCreate([], ['balance_rials' => 0]);
        $transactions = $wallet->transactions()->latest()->limit(30)->get();
        return view('wallet', compact('wallet', 'transactions'));
    }

    public function topUp(Request $request)
    {
        $data = $request->validate(['amount_rials' => ['required','integer','min:10000','max:1000000000']]);
        return back()->with('status', 'درگاه افزایش موجودی هنوز در این محیط به درگاه بانکی متصل نشده است. مبلغ ثبت نشد.');
    }
}
