<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\FraudDetectionService;

class ATMController extends Controller
{    public function index()
    {
        return view('atm.index');
    }

    public function showWithdraw()
    {
        $user = Auth::user();
        $accounts = $user->accounts;
        
        return view('atm.withdraw', [
            'accounts' => $accounts,
        ]);
    }

    public function processWithdraw(Request $request)
    {
        $request->validate([
            'account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:10|max:5000',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $accountId = $request->input('account_id');
        $amount = $request->input('amount');
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $user = Auth::user();
        
        // Get the account and verify it belongs to the user
        $account = Account::find($accountId);
        
        if (!$account || $account->user_id !== $user->id) {
            return back()->with('error', 'Account not found.');
        }
        if ($account->isFrozen()) {
            return back()->with('error', 'This account is frozen and cannot perform transactions.');
        }
        
        // Check if account has sufficient balance
        if (!$account->hasSufficientBalance($amount)) {
            return back()->with('error', 'Insufficient balance for this withdrawal.');
        }
        
        $fraudService = FraudDetectionService::getInstance();
        $fraudCheck = $fraudService->checkFraud($user->id, $amount, $latitude, $longitude);
        if ($fraudCheck['is_fraud']) {
            $pin = $request->input('pin');
            if (!$pin || hash('sha256', $pin) !== $user->card_pin) {
                $attemptKey = "pin_attempts_account_{$account->id}";
                $attempts = session($attemptKey, 0) + 1;
                session([$attemptKey => $attempts]);
                if ($attempts >= 3) {
                    $account->freeze();
                    session()->forget($attemptKey);
                    $failedTransaction = Transaction::create([
                        'transaction_id' => Transaction::generateTransactionId(),
                        'user_id' => $user->id,
                        'from_account_id' => $account->id,
                        'to_account_id' => null,
                        'transaction_type' => 'withdrawal',
                        'amount' => $amount,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'status' => 'failed',
                        'transaction_date' => now(),
                    ]);
                    $fraudService->detectFraud($user->id, $amount, $failedTransaction->transaction_id, $latitude, $longitude, $attempts);
                    return back()->with('error', 'Account frozen after 3 failed PIN attempts. Transaction marked as failed.');
                }
                return back()->withInput()->with('verify_pin', true)->with('error', 'Unusual activity detected. Please enter your PIN to confirm this transaction.');
            }
        }
        
        // Perform balance change and transaction creation atomically
        $transaction = DB::transaction(function () use ($account, $user, $amount, $latitude, $longitude) {
            $account->deduct($amount);
            return Transaction::create([
                'transaction_id' => Transaction::generateTransactionId(),
                'user_id' => $user->id,
                'from_account_id' => $account->id,
                'to_account_id' => null,
                'transaction_type' => 'withdrawal',
                'amount' => $amount,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'status' => 'completed',
                'transaction_date' => now(),
            ]);
        });

        // Check for fraud
        $fraudService = FraudDetectionService::getInstance();
        $attempts = session("pin_attempts_account_{$account->id}", 0);
        session()->forget("pin_attempts_account_{$account->id}");
        $fraudService->detectFraud($user->id, $amount, $transaction->transaction_id, $latitude, $longitude, $attempts);

        return redirect()
            ->route('dashboard')
            ->with('success', "Successfully withdrawn $$amount from your " . ucfirst($account->account_type) . " account!");
    }

    public function showTransfer()
    {
        $user = Auth::user();
        $accounts = $user->accounts;
        
        return view('atm.transfer', [
            'accounts' => $accounts,
        ]);
    }

    public function processTransfer(Request $request)
    {
        $request->validate([
            'from_account_id' => 'required|exists:accounts,id',
            'to_account_number' => 'required|string|min:10|max:20',
            'amount' => 'required|numeric|min:1|max:10000',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
        ]);

        $fromAccountId = $request->input('from_account_id');
        $toAccountNumber = $request->input('to_account_number');
        $amount = $request->input('amount');
        $latitude = $request->input('latitude');
        $longitude = $request->input('longitude');
        $user = Auth::user();
        
        // Get the from account and verify it belongs to the user
        $fromAccount = Account::find($fromAccountId);
        
        if (!$fromAccount || $fromAccount->user_id !== $user->id) {
            return back()->with('error', 'From account not found.');
        }
        if ($fromAccount->isFrozen()) {
            return back()->with('error', 'This account is frozen and cannot perform transactions.');
        }
        
        // Get the to account by account number
        $toAccount = Account::where('account_number', $toAccountNumber)->first();
        
        if (!$toAccount) {
            return back()->with('error', 'Recipient account not found.');
        }
        
        // Check if account has sufficient balance
        if (!$fromAccount->hasSufficientBalance($amount)) {
            return back()->with('error', 'Insufficient balance for this transfer.');
        }
        
        $fraudService = FraudDetectionService::getInstance();
        $fraudCheck = $fraudService->checkFraud($user->id, $amount, $latitude, $longitude);
        if ($fraudCheck['is_fraud']) {
            $pin = $request->input('pin');
            if (!$pin || hash('sha256', $pin) !== $user->card_pin) {
                $attemptKey = "pin_attempts_account_{$fromAccount->id}";
                $attempts = session($attemptKey, 0) + 1;
                session([$attemptKey => $attempts]);
                if ($attempts >= 3) {
                    $fromAccount->freeze();
                    session()->forget($attemptKey);
                    $failedTransaction = Transaction::create([
                        'transaction_id' => Transaction::generateTransactionId(),
                        'user_id' => $user->id,
                        'from_account_id' => $fromAccount->id,
                        'to_account_id' => $toAccount->id,
                        'transaction_type' => 'transfer',
                        'amount' => $amount,
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'status' => 'failed',
                        'transaction_date' => now(),
                    ]);
                    $fraudService->detectFraud($user->id, $amount, $failedTransaction->transaction_id, $latitude, $longitude, $attempts);
                    return back()->with('error', 'Account frozen after 3 failed PIN attempts. Transaction marked as failed.');
                }
                return back()->withInput()->with('verify_pin', true)->with('error', 'Unusual activity detected. Please enter your PIN to confirm this transaction.');
            }
        }
        
        // Perform balance changes and transaction creation atomically
        $transaction = DB::transaction(function () use ($fromAccount, $toAccount, $user, $amount, $latitude, $longitude) {
            $fromAccount->deduct($amount);
            $toAccount->deposit($amount);
            return Transaction::create([
                'transaction_id' => Transaction::generateTransactionId(),
                'user_id' => $user->id,
                'from_account_id' => $fromAccount->id,
                'to_account_id' => $toAccount->id,
                'transaction_type' => 'transfer',
                'amount' => $amount,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'status' => 'completed',
                'transaction_date' => now(),
            ]);
        });

        // Check for fraud
        $fraudService = FraudDetectionService::getInstance();
        $attempts = session("pin_attempts_account_{$fromAccount->id}", 0);
        session()->forget("pin_attempts_account_{$fromAccount->id}");
        $fraudService->detectFraud($user->id, $amount, $transaction->transaction_id, $latitude, $longitude, $attempts);

        return redirect()
            ->route('dashboard')
            ->with('success', "Successfully transferred $$amount to account ending in " . substr($toAccountNumber, -4) . "!");
    }

    // API endpoint to lookup account holder by account number
    public function getAccountHolder($accountNumber)
    {
        $account = Account::where('account_number', $accountNumber)->with('user')->first();
        
        if ($account && $account->user) {
            return response()->json([
                'success' => true,
                'name' => $account->user->name,
                'account_type' => $account->account_type,
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Account not found',
        ]);
    }

    // NEW HISTORY METHOD
    public function showHistory()
    {
        $user = Auth::user();
        $accounts = $user->accounts;
        $accountIds = $accounts->pluck('id')->toArray();
        
        // Fetch all transactions for user's accounts (both sent and received)
        $transactions = Transaction::where(function($query) use ($user, $accountIds) {
                            $query->where('user_id', $user->id)
                                  ->orWhereIn('to_account_id', $accountIds);
                        })
                        ->orderBy('transaction_date', 'desc')
                        ->get();
        
        return view('atm.history', [
            'transactions' => $transactions,
            'accounts' => $accounts,
            'accountIds' => $accountIds,
        ]);
    }
}
