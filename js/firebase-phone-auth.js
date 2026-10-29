import { initializeApp } from 'https://www.gstatic.com/firebasejs/10.14.1/firebase-app.js';
import {
    getAuth,
    RecaptchaVerifier,
    signInWithPhoneNumber
} from 'https://www.gstatic.com/firebasejs/10.14.1/firebase-auth.js';

const form = document.getElementById('registration-form') || document.getElementById('buyer-login-form');
const configNode = document.getElementById('firebase-auth-config');
if (form && configNode) {
    const config = JSON.parse(configNode.textContent || '{}') || {};
    const phoneInput = document.getElementById('phone');
    const codeInput = document.getElementById('phone-otp');
    const tokenInput = document.getElementById('firebase-id-token');
    const sendButton = document.getElementById('send-phone-code');
    const verifyButton = document.getElementById('verify-phone-code');
    const submitButton = document.getElementById(
        form.id === 'buyer-login-form' ? 'login-submit' : 'register-submit'
    );
    const status = document.getElementById('phone-verification-status');
    const codeGroup = document.getElementById('phone-code-group');
    let confirmation = null;
    let verifier = null;
    let verifiedPhone = '';

    const setStatus = (message, isError = false) => {
        status.textContent = message;
        status.classList.toggle('text-danger', isError);
        status.classList.toggle('text-success', !isError);
    };

    const phoneSendErrorMessage = (error) => {
        const messages = {
            'auth/invalid-phone-number': 'Enter a valid 10-digit Indian mobile number.',
            'auth/operation-not-allowed': 'Phone sign-in is disabled. Enable Phone in Firebase Authentication settings.',
            'auth/invalid-app-credential': 'Firebase could not verify reCAPTCHA. Check Firebase authorized domains and allow Google reCAPTCHA in your browser.',
            'auth/captcha-check-failed': 'reCAPTCHA verification failed. Allow Google reCAPTCHA and try again.',
            'auth/app-not-authorized': 'This website domain is not authorized in the Firebase project.',
            'auth/quota-exceeded': 'Firebase SMS quota is exhausted. Try a configured Firebase test phone number or check the project billing/quota.',
            'auth/billing-not-enabled': 'Firebase requires billing to send real SMS. Use a configured Firebase test phone number to avoid charges.',
            'auth/too-many-requests': 'Too many attempts. Wait before requesting another code.',
            'auth/network-request-failed': 'The Firebase request failed. Check your internet connection and browser extensions.'
        };
        return messages[error?.code]
            || `Firebase could not send the code${error?.code ? ` (${error.code})` : ''}. Check Firebase Phone Authentication and authorized-domain settings.`;
    };

    if (!config.apiKey || !config.authDomain || !config.projectId || !config.appId) {
        sendButton.disabled = true;
        setStatus('Phone verification is not configured. Contact the administrator.', true);
    } else {
        const auth = getAuth(initializeApp(config));
        auth.languageCode = 'en';

        sendButton.addEventListener('click', async () => {
            const localPhone = phoneInput.value.trim();
            if (!/^[6-9]\d{9}$/.test(localPhone)) {
                setStatus('Enter a valid 10-digit Indian mobile number.', true);
                phoneInput.focus();
                return;
            }
            const phone = `+91${localPhone}`;
            sendButton.disabled = true;
            setStatus('Sending verification code…');
            try {
                if (!verifier) {
                    verifier = new RecaptchaVerifier(auth, document.getElementById('recaptcha-container'), {
                        size: 'invisible'
                    });
                }
                confirmation = await signInWithPhoneNumber(auth, phone, verifier);
                codeGroup.hidden = false;
                verifyButton.hidden = false;
                setStatus('Verification code sent. Enter it below.');
                codeInput.focus();
            } catch (error) {
                confirmation = null;
                if (verifier) {
                    verifier.clear();
                    verifier = null;
                }
                console.warn('Firebase phone verification send failed:', error?.code || 'unknown');
                setStatus(phoneSendErrorMessage(error), true);
            } finally {
                sendButton.disabled = false;
            }
        });

        verifyButton.addEventListener('click', async () => {
            if (!confirmation || !/^\d{6}$/.test(codeInput.value.trim())) {
                setStatus('Enter the six-digit verification code.', true);
                return;
            }
            verifyButton.disabled = true;
            setStatus('Verifying code…');
            try {
                const credential = await confirmation.confirm(codeInput.value.trim());
                const idToken = await credential.user.getIdToken(true);
                verifiedPhone = credential.user.phoneNumber || '';
                if (verifiedPhone !== `+91${phoneInput.value.trim()}`) {
                    throw new Error('The verified number changed.');
                }
                tokenInput.value = idToken;
                phoneInput.readOnly = true;
                sendButton.disabled = true;
                verifyButton.hidden = true;
                submitButton.disabled = false;
                setStatus('Phone number verified.');
                if (form.id === 'buyer-login-form') {
                    form.requestSubmit();
                }
            } catch (error) {
                tokenInput.value = '';
                setStatus(error.code === 'auth/invalid-verification-code'
                    ? 'That code is incorrect. Check it and try again.'
                    : 'Verification failed or expired. Request a new code.', true);
            } finally {
                verifyButton.disabled = false;
            }
        });

        phoneInput.addEventListener('input', () => {
            if (verifiedPhone && phoneInput.value.trim() !== verifiedPhone) {
                verifiedPhone = '';
                tokenInput.value = '';
                phoneInput.readOnly = false;
                sendButton.disabled = false;
                submitButton.disabled = true;
                setStatus('Verify this phone number before creating the account.', true);
            }
        });
    }

    form.addEventListener('submit', (event) => {
        if (!tokenInput.value) {
            event.preventDefault();
            setStatus('Verify your phone number before creating the account.', true);
        }
    });
}
