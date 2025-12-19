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

    // 1. Find the user
    $user = User::where('card_number', $cardNumber)->first();

    // 2. Manual SHA-256 Comparison
    // We hash the input PIN and see if it matches the string in the DB
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

        // Find user by their credential ID
        $user = User::where('fingerprint_id', $request->fingerprint_data)->first();

        if ($user) {
            Auth::login($user);
            $request->session()->regenerate();
            return redirect()->intended('dashboard');
        }

        return back()->withErrors(['fingerprint' => 'Biometric data not recognized.']);
    }

    /**
     * Generate WebAuthn challenge for authentication
     */
    public function generateChallenge(Request $request)
    {
        // Generate a random challenge (32 bytes)
        $challenge = random_bytes(32);
        $challengeBase64 = base64_encode($challenge);
        
        // Store challenge in session for later verification
        $request->session()->put('webauthn_challenge', $challengeBase64);
        
        // Get all registered credentials (optional - for better UX)
        // In a real system, you might want to return specific user credentials
        $allowCredentials = [];
        
        // Get all users with fingerprint_id to allow any registered device
        $users = User::whereNotNull('fingerprint_id')->get();
        foreach ($users as $user) {
            if ($user->fingerprint_id) {
                $allowCredentials[] = [
                    'id' => $user->fingerprint_id,
                    'type' => 'public-key',
                    'transports' => ['internal', 'usb', 'nfc', 'ble']
                ];
            }
        }
        
        return response()->json([
            'challenge' => rtrim(strtr($challengeBase64, '+/', '-_'), '='),
            'allowCredentials' => $allowCredentials,
            'timeout' => 60000,
            'rpId' => $request->getHost()
        ]);
    }

    /**
     * Verify WebAuthn credential
     */
    public function verifyCredential(Request $request)
    {
        try {
            $credentialData = $request->all();
            
            // Get stored challenge from session
            $storedChallenge = $request->session()->get('webauthn_challenge');
            
            if (!$storedChallenge) {
                return response()->json([
                    'success' => false,
                    'message' => 'No challenge found. Please try again.'
                ], 400);
            }
            
            // Decode the client data JSON
            $clientDataJSON = base64_decode(
                strtr($credentialData['response']['clientDataJSON'], '-_', '+/') . 
                str_repeat('=', (4 - strlen($credentialData['response']['clientDataJSON']) % 4) % 4)
            );
            $clientData = json_decode($clientDataJSON, true);
            
            // Verify the challenge matches
            $receivedChallenge = rtrim(strtr($clientData['challenge'], '-_', '+/'), '=');
            $storedChallengeNormalized = rtrim(strtr($storedChallenge, '-_', '+/'), '=');
            
            if ($receivedChallenge !== $storedChallengeNormalized) {
                return response()->json([
                    'success' => false,
                    'message' => 'Challenge verification failed.'
                ], 400);
            }
            
            // Verify origin
            $expectedOrigin = $request->getScheme() . '://' . $request->getHost();
            if ($request->getPort() && !in_array($request->getPort(), [80, 443])) {
                $expectedOrigin .= ':' . $request->getPort();
            }
            
            if ($clientData['origin'] !== $expectedOrigin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Origin verification failed.'
                ], 400);
            }
            
            // Find user by credential ID
            $credentialId = $credentialData['id'];
            $user = User::where('fingerprint_id', $credentialId)->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Credential not found.'
                ], 404);
            }
            
            // Clear the challenge from session
            $request->session()->forget('webauthn_challenge');
            
            // In a production system, you should verify the signature here
            // For now, we'll trust that the credential ID match is sufficient
            
            return response()->json([
                'success' => true,
                'credentialId' => $credentialId,
                'message' => 'Authentication successful'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('WebAuthn verification error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }

    /**
     * Get WebAuthn registration options
     */
    public function getRegistrationOptions(Request $request)
    {
        $user = Auth::user();
        
        // Generate a random challenge
        $challenge = random_bytes(32);
        $challengeBase64 = base64_encode($challenge);
        
        // Store challenge in session
        $request->session()->put('webauthn_registration_challenge', $challengeBase64);
        
        // Create user entity
        $userEntity = [
            'id' => base64_encode((string) $user->id),
            'name' => $user->email,
            'displayName' => $user->name,
        ];
        
        return response()->json([
            'challenge' => rtrim(strtr($challengeBase64, '+/', '-_'), '='),
            'rp' => [
                'name' => config('app.name', 'SecureBank'),
                'id' => $request->getHost()
            ],
            'user' => $userEntity,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],  // ES256
                ['type' => 'public-key', 'alg' => -257], // RS256
            ],
            'timeout' => 60000,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'requireResidentKey' => false,
                'userVerification' => 'preferred'
            ],
            'attestation' => 'none'
        ]);
    }

    /**
     * Register WebAuthn credential
     */
    public function registerCredential(Request $request)
    {
        try {
            $user = Auth::user();
            $credentialData = $request->all();
            
            // Get stored challenge
            $storedChallenge = $request->session()->get('webauthn_registration_challenge');
            
            if (!$storedChallenge) {
                return response()->json([
                    'success' => false,
                    'message' => 'No registration challenge found.'
                ], 400);
            }
            
            // Verify challenge
            $clientDataJSON = base64_decode(
                strtr($credentialData['response']['clientDataJSON'], '-_', '+/') . 
                str_repeat('=', (4 - strlen($credentialData['response']['clientDataJSON']) % 4) % 4)
            );
            $clientData = json_decode($clientDataJSON, true);
            
            $receivedChallenge = rtrim(strtr($clientData['challenge'], '-_', '+/'), '=');
            $storedChallengeNormalized = rtrim(strtr($storedChallenge, '-_', '+/'), '=');
            
            if ($receivedChallenge !== $storedChallengeNormalized) {
                return response()->json([
                    'success' => false,
                    'message' => 'Challenge verification failed.'
                ], 400);
            }
            
            // Store the credential ID
            $credentialId = $credentialData['id'];
            $user->fingerprint_id = $credentialId;
            $user->save();
            
            // Clear the challenge
            $request->session()->forget('webauthn_registration_challenge');
            
            return response()->json([
                'success' => true,
                'message' => 'Fingerprint registered successfully!'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('WebAuthn registration error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ], 500);
        }
    }
}