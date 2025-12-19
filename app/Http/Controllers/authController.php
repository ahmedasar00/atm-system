<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
{
    $request->validate([
        'card_number' => 'required',
        'pin' => 'required|digits:4',
    ]);

    $cardNumber = $request->input('card_number');
    $pin = $request->input('pin');
    $user = User::where('card_number', $cardNumber)->first();

    
    if ($user && hash('sha256', $pin) === $user->card_pin) {
        Auth::login($user);
        $request->session()->regenerate();
        return redirect()->intended('dashboard');
    }

    return back()->withErrors([
        'card_number' => 'Invalid Card Number or PIN.',
    ]);
}

    public function loginWithFingerprint(Request $request)
    {
        $request->validate([
            'fingerprint_data' => 'required',
        ]);

        // Real Auth Logic: Find user by their unique fingerprint ID
        $user = User::where('fingerprint_id', $request->fingerprint_data)->first();

        if ($user) {
            Auth::login($user);
            $request->session()->regenerate();
            return redirect()->intended('dashboard');
        }

        return back()->withErrors(['fingerprint' => 'Biometric data not recognized.']);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}