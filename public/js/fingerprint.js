/**
 * Fingerprint Authentication System
 * Uses WebAuthn API for biometric authentication
 */

class FingerprintAuth {
    constructor() {
        this.scanButton = document.getElementById("scan-fingerprint");
        this.statusText = document.querySelector(".scanner-status");
        this.scannerAnimation = document.querySelector(".scanner-animation");
        this.fingerprintForm = document.getElementById("fingerprint-form");
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                        document.querySelector('input[name="_token"]')?.value;

        this.init();
    }

    init() {
        if (this.scanButton) {
            this.scanButton.addEventListener("click", () => this.startScan());
        }

        // Check if WebAuthn is available
        this.checkBrowserSupport();
    }

    checkBrowserSupport() {
        if (!window.PublicKeyCredential) {
            this.showError(
                "Biometric authentication is not supported on this browser"
            );
            if (this.scanButton) {
                this.scanButton.disabled = true;
            }
            return false;
        }
        return true;
    }

    async startScan() {
        if (!this.checkBrowserSupport()) {
            return;
        }

        try {
            this.updateStatus("Scanning fingerprint...", "scanning");

            // Use real WebAuthn authentication
            const result = await this.authenticateWithWebAuthn();

            if (result.success) {
                this.updateStatus("Fingerprint verified successfully!", "success");
                
                // Set the fingerprint data (credential ID)
                document.getElementById("fingerprint_data").value = result.credentialId;

                // Submit the form after successful scan
                setTimeout(() => {
                    this.fingerprintForm.submit();
                }, 1000);
            } else {
                this.showError(result.message || "Fingerprint not recognized");
            }
        } catch (error) {
            console.error("Fingerprint authentication error:", error);
            this.showError(
                error.message || "Fingerprint scan failed. Please try again."
            );
        }
    }

    /**
     * Real WebAuthn Implementation
     */
    async authenticateWithWebAuthn() {
        try {
            // 1. Get challenge from server
            const challengeResponse = await fetch("/webauthn/challenge", {
                method: "GET",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": this.csrfToken,
                },
            });

            if (!challengeResponse.ok) {
                throw new Error("Failed to get authentication challenge");
            }

            const challengeData = await challengeResponse.json();

            // 2. Convert challenge from base64
            const publicKeyCredentialRequestOptions = {
                challenge: this.base64ToArrayBuffer(challengeData.challenge),
                timeout: 60000,
                rpId: window.location.hostname,
                userVerification: "preferred"
            };

            // If there are allowed credentials, add them
            if (challengeData.allowCredentials && challengeData.allowCredentials.length > 0) {
                publicKeyCredentialRequestOptions.allowCredentials = challengeData.allowCredentials.map(
                    cred => ({
                        id: this.base64ToArrayBuffer(cred.id),
                        type: "public-key",
                        transports: cred.transports || ["internal", "usb", "nfc", "ble"]
                    })
                );
            }

            // 3. Request credential from authenticator
            const credential = await navigator.credentials.get({
                publicKey: publicKeyCredentialRequestOptions,
            });

            if (!credential) {
                throw new Error("No credential received");
            }

            // 4. Prepare credential data for server
            const credentialData = {
                id: credential.id,
                rawId: this.arrayBufferToBase64(credential.rawId),
                type: credential.type,
                response: {
                    authenticatorData: this.arrayBufferToBase64(credential.response.authenticatorData),
                    clientDataJSON: this.arrayBufferToBase64(credential.response.clientDataJSON),
                    signature: this.arrayBufferToBase64(credential.response.signature),
                    userHandle: credential.response.userHandle ? 
                        this.arrayBufferToBase64(credential.response.userHandle) : null
                }
            };

            // 5. Send credential to server for verification
            const verifyResponse = await fetch("/webauthn/verify", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": this.csrfToken,
                },
                body: JSON.stringify(credentialData),
            });

            if (!verifyResponse.ok) {
                throw new Error("Verification failed");
            }

            const result = await verifyResponse.json();
            return result;

        } catch (error) {
            console.error("WebAuthn error:", error);
            
            // Provide user-friendly error messages
            if (error.name === "NotAllowedError") {
                throw new Error("Authentication was cancelled or timed out");
            } else if (error.name === "InvalidStateError") {
                throw new Error("This device is not registered");
            } else if (error.name === "NotSupportedError") {
                throw new Error("This browser doesn't support biometric authentication");
            }
            
            throw error;
        }
    }

    /**
     * Helper function to convert base64 to ArrayBuffer
     */
    base64ToArrayBuffer(base64) {
        const binaryString = atob(base64.replace(/-/g, '+').replace(/_/g, '/'));
        const bytes = new Uint8Array(binaryString.length);
        for (let i = 0; i < binaryString.length; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * Helper function to convert ArrayBuffer to base64
     */
    arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
    }

    updateStatus(message, status = "idle") {
        if (this.statusText) {
            this.statusText.textContent = message;
            this.statusText.className = `scanner-status status-${status}`;
        }

        if (this.scannerAnimation) {
            this.scannerAnimation.className = `scanner-animation ${status}`;
        }
    }

    showError(message) {
        this.updateStatus(message, "error");

        // Create error alert
        const existingAlert = document.querySelector(".alert-error");
        if (existingAlert) {
            existingAlert.remove();
        }

        const alert = document.createElement("div");
        alert.className = "alert alert-error";
        alert.innerHTML = `
            <i class="fas fa-exclamation-circle"></i>
            <span>${message}</span>
        `;

        const form = document.querySelector(".login-form");
        form.insertBefore(alert, form.firstChild);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            alert.remove();
            this.updateStatus("Waiting for fingerprint...", "idle");
        }, 5000);
    }
}

// Initialize fingerprint authentication when DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
    new FingerprintAuth();
});

// Add additional status styles dynamically
const style = document.createElement("style");
style.textContent = `
    .scanner-status.status-scanning {
        color: #3b82f6;
        font-weight: 600;
    }

    .scanner-status.status-success {
        color: #10b981;
        font-weight: 600;
    }

    .scanner-status.status-error {
        color: #ef4444;
        font-weight: 600;
    }

    .scanner-animation.scanning {
        animation: scanPulse 1s infinite;
    }

    .scanner-animation.success {
        background: linear-gradient(135deg, #10b981, #059669);
    }

    .scanner-animation.error {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        animation: shake 0.5s;
    }

    @keyframes scanPulse {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.7);
        }
        50% {
            transform: scale(1.05);
            box-shadow: 0 0 0 10px rgba(99, 102, 241, 0);
        }
    }

    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-10px); }
        75% { transform: translateX(10px); }
    }
`;
document.head.appendChild(style);
