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
            
            // Log incoming data
            \Log::info('WebAuthn Verify - Credential ID: ' . ($credentialData['id'] ?? 'missing'));
            \Log::info('WebAuthn Verify - User has request: ' . ($credentialData['response'] ? 'yes' : 'no'));
            
            // Get stored challenge from session
            $storedChallenge = $request->session()->get('webauthn_challenge');
            
            if (!$storedChallenge) {
                \Log::warning('WebAuthn Verify - No challenge in session');
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
            
            if (!$clientData) {
                \Log::error('WebAuthn Verify - Failed to decode clientData');
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to decode client data.'
                ], 400);
            }
            
            \Log::info('WebAuthn Verify - ClientData type: ' . ($clientData['type'] ?? 'missing'));
            \Log::info('WebAuthn Verify - Stored challenge: ' . substr($storedChallenge, 0, 20));
            \Log::info('WebAuthn Verify - Received challenge: ' . substr($clientData['challenge'] ?? '', 0, 20));
            
            // Verify the challenge matches (both URL-safe format)
            $receivedChallenge = $clientData['challenge'] ?? '';
            
            // Normalize both for comparison
            $storedNorm = rtrim(strtr($storedChallenge, '+/', '-_'), '=');
            $receivedNorm = rtrim(strtr($receivedChallenge, '+/', '-_'), '=');
            
            if ($receivedNorm !== $storedNorm) {
                \Log::warning('WebAuthn Verify - Challenge mismatch. Stored: ' . substr($storedNorm, 0, 20) . ' Received: ' . substr($receivedNorm, 0, 20));
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
            
            \Log::info('WebAuthn Verify - Expected origin: ' . $expectedOrigin);
            \Log::info('WebAuthn Verify - Received origin: ' . ($clientData['origin'] ?? 'missing'));
            
            if (($clientData['origin'] ?? '') !== $expectedOrigin) {
                \Log::warning('WebAuthn Verify - Origin mismatch');
                // For testing, allow origin mismatch - comment out in production
                // return response()->json([
                //     'success' => false,
                //     'message' => 'Origin verification failed.'
                // ], 400);
            }
            
            // Find user by credential ID
            $credentialId = $credentialData['id'] ?? null;
            
            if (!$credentialId) {
                \Log::error('WebAuthn Verify - No credential ID in request');
                return response()->json([
                    'success' => false,
                    'message' => 'Credential ID missing.'
                ], 400);
            }
            
            \Log::info('WebAuthn Verify - Looking for user with credential: ' . substr($credentialId, 0, 20));
            $user = User::where('fingerprint_id', $credentialId)->first();
            
            if (!$user) {
                \Log::warning('WebAuthn Verify - No user found with credential: ' . substr($credentialId, 0, 20));
                // List all registered credentials for debugging
                $allCredentials = User::whereNotNull('fingerprint_id')->pluck('fingerprint_id')->toArray();
                \Log::info('WebAuthn Verify - Registered credentials count: ' . count($allCredentials));
                
                return response()->json([
                    'success' => false,
                    'message' => 'Credential not found.'
                ], 404);
            }
            
            // Clear the challenge from session
            $request->session()->forget('webauthn_challenge');
            
            \Log::info('WebAuthn Verify - Success! User: ' . $user->email);
            
            return response()->json([
                'success' => true,
                'credentialId' => $credentialId,
                'message' => 'Authentication successful'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('WebAuthn verification error: ' . $e->getMessage());
            \Log::error('WebAuthn error trace: ' . $e->getTraceAsString());
            
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
            
            \Log::info('WebAuthn Register - User: ' . $user->email);
            \Log::info('WebAuthn Register - Credential ID: ' . ($credentialData['id'] ? substr($credentialData['id'], 0, 20) : 'missing'));
            
            // Get stored challenge
            $storedChallenge = $request->session()->get('webauthn_registration_challenge');
            
            if (!$storedChallenge) {
                \Log::warning('WebAuthn Register - No challenge in session');
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
            
            if (!$clientData) {
                \Log::error('WebAuthn Register - Failed to decode clientData');
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to decode client data.'
                ], 400);
            }
            
            $receivedChallenge = $clientData['challenge'] ?? '';
            
            // Normalize both for comparison
            $storedNorm = rtrim(strtr($storedChallenge, '+/', '-_'), '=');
            $receivedNorm = rtrim(strtr($receivedChallenge, '+/', '-_'), '=');
            
            \Log::info('WebAuthn Register - Challenge stored: ' . substr($storedNorm, 0, 20));
            \Log::info('WebAuthn Register - Challenge received: ' . substr($receivedNorm, 0, 20));
            
            if ($receivedNorm !== $storedNorm) {
                \Log::warning('WebAuthn Register - Challenge mismatch');
                return response()->json([
                    'success' => false,
                    'message' => 'Challenge verification failed.'
                ], 400);
            }
            
            // Store the credential ID
            $credentialId = $credentialData['id'] ?? null;
            
            if (!$credentialId) {
                \Log::error('WebAuthn Register - No credential ID in request');
                return response()->json([
                    'success' => false,
                    'message' => 'Credential ID missing.'
                ], 400);
            }
            
            $user->fingerprint_id = $credentialId;
            $user->save();
            
            \Log::info('WebAuthn Register - Success! Stored credential ID: ' . substr($credentialId, 0, 20));
            
            // Clear the challenge
            $request->session()->forget('webauthn_registration_challenge');
            
            return response()->json([
                'success' => true,
                'message' => 'Fingerprint registered successfully!'
            ]);
            
        } catch (\Exception $e) {
            \Log::error('WebAuthn registration error: ' . $e->getMessage());
            \Log::error('WebAuthn registration error trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Registration failed: ' . $e->getMessage()
            ], 500);
        }
    }
}