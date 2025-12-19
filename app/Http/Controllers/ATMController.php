<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ATMController extends Controller
{
    public function index()
    {
        return view('atm.index');
    }

    public function showWithdraw()
    {
        return view('atm.withdraw');
    }

    public function processWithdraw(Request $request)
    {
        $request->validate([
            'account' => 'required|in:savings,current,gold',
            'amount' => 'required|numeric|min:10|max:5000'
        ]);

        $account = $request->input('account');
        $amount = $request->input('amount');

        return redirect()
            ->route('dashboard')
            ->with('success', "Successfully withdrawn $$amount from your $account account!");
    }

    public function showTransfer()
    {
        return view('atm.transfer');
    }

    public function processTransfer(Request $request)
    {
        $request->validate([
            'from_account' => 'required|in:savings,current,gold',
            'to_account' => 'required|string|min:10|max:16',
            'amount' => 'required|numeric|min:1|max:10000'
        ]);

        $fromAccount = $request->input('from_account');
        $toAccount = $request->input('to_account');
        $amount = $request->input('amount');

        $maskedAccount = '****  ****  ****  ' . substr($toAccount, -4);

        return redirect()
            ->route('dashboard')
            ->with('success', "Successfully transferred $$amount to $maskedAccount!");
    }

    // NEW HISTORY METHOD
    public function showHistory()
    {
        
        
        return view('atm.history');
    }
}