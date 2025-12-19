/**
 * Fingerprint Registration System
 * Uses WebAuthn API for biometric registration
 */

class FingerprintRegistration {
    constructor() {
        this.registerButton = document.getElementById("register-fingerprint");
        this.statusElement = document.getElementById("fingerprint-status");
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                        document.querySelector('input[name="_token"]')?.value;

        this.init();
    }

    init() {
        if (this.registerButton) {
            this.registerButton.addEventListener("click", () => this.startRegistration());
        }

        // Check if WebAuthn is available
        this.checkBrowserSupport();
    }

    checkBrowserSupport() {
        if (!window.PublicKeyCredential) {
            this.showError("Biometric authentication is not supported on this browser");
            if (this.registerButton) {
                this.registerButton.disabled = true;
            }
            return false;
        }
        return true;
    }

    async startRegistration() {
        if (!this.checkBrowserSupport()) {
            return;
        }

        try {
            this.updateStatus("Preparing registration...", "info");

            // Get registration options from server
            const optionsResponse = await fetch("/webauthn/register/options", {
                method: "GET",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": this.csrfToken,
                },
            });

            if (!optionsResponse.ok) {
                throw new Error("Failed to get registration options");
            }

            const options = await optionsResponse.json();

            // Convert base64 strings to ArrayBuffer
            const publicKeyCredentialCreationOptions = {
                challenge: this.base64ToArrayBuffer(options.challenge),
                rp: options.rp,
                user: {
                    id: this.base64ToArrayBuffer(options.user.id),
                    name: options.user.name,
                    displayName: options.user.displayName,
                },
                pubKeyCredParams: options.pubKeyCredParams,
                timeout: options.timeout,
                authenticatorSelection: options.authenticatorSelection,
                attestation: options.attestation
            };

            this.updateStatus("Please scan your fingerprint...", "scanning");

            // Create credential
            const credential = await navigator.credentials.create({
                publicKey: publicKeyCredentialCreationOptions
            });

            if (!credential) {
                throw new Error("No credential created");
            }

            this.updateStatus("Verifying fingerprint...", "info");

            // Prepare credential data for server
            const credentialData = {
                id: credential.id,
                rawId: this.arrayBufferToBase64(credential.rawId),
                type: credential.type,
                response: {
                    attestationObject: this.arrayBufferToBase64(credential.response.attestationObject),
                    clientDataJSON: this.arrayBufferToBase64(credential.response.clientDataJSON)
                }
            };

            // Send credential to server
            const registerResponse = await fetch("/webauthn/register", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": this.csrfToken,
                },
                body: JSON.stringify(credentialData),
            });

            if (!registerResponse.ok) {
                throw new Error("Registration failed");
            }

            const result = await registerResponse.json();

            if (result.success) {
                this.updateStatus("Fingerprint registered successfully! ✓", "success");
                
                // Disable the register button and show success
                if (this.registerButton) {
                    this.registerButton.disabled = true;
                    this.registerButton.textContent = "Fingerprint Registered";
                    this.registerButton.classList.add("btn-success");
                }
                
                // Reload page after 2 seconds to show updated status
                setTimeout(() => {
                    window.location.reload();
                }, 2000);
            } else {
                throw new Error(result.message || "Registration failed");
            }

        } catch (error) {
            console.error("Registration error:", error);
            
            // Provide user-friendly error messages
            let message = "Registration failed. Please try again.";
            
            if (error.name === "NotAllowedError") {
                message = "Registration was cancelled or timed out";
            } else if (error.name === "InvalidStateError") {
                message = "This device is already registered";
            } else if (error.name === "NotSupportedError") {
                message = "This browser doesn't support biometric authentication";
            } else if (error.message) {
                message = error.message;
            }
            
            this.showError(message);
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

    updateStatus(message, type = "info") {
        if (this.statusElement) {
            this.statusElement.textContent = message;
            this.statusElement.className = `fingerprint-status status-${type}`;
        }
    }

    showError(message) {
        this.updateStatus(message, "error");
        
        // Also show an alert if available
        const alertContainer = document.getElementById("alert-container");
        if (alertContainer) {
            const alert = document.createElement("div");
            alert.className = "alert alert-danger";
            alert.innerHTML = `
                <i class="fas fa-exclamation-circle"></i>
                <span>${message}</span>
            `;
            alertContainer.appendChild(alert);
            
            setTimeout(() => alert.remove(), 5000);
        }
    }
}

// Initialize when DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
    new FingerprintRegistration();
});
