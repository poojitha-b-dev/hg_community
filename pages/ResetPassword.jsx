// frontend/src/pages/ResetPassword.jsx
// Landing page for the password reset link sent by email.
// URL: /reset-password?token=<hex>
// The same password strength UI as Register is reused.

import { useState } from "react";
import { useAuth } from "../context/AuthContext";

function getPasswordStrength(password) {
  let score = 0;
  if (password.length >= 8) score++;
  if (password.length >= 12) score++;
  if (/[A-Z]/.test(password)) score++;
  if (/[a-z]/.test(password)) score++;
  if (/[0-9]/.test(password)) score++;
  if (/[^A-Za-z0-9]/.test(password)) score++;
  if (score <= 2) return { level: "weak", label: "Weak", color: "#e24b4a", width: "25%" };
  if (score <= 3) return { level: "fair", label: "Fair", color: "#ef9f27", width: "50%" };
  if (score <= 4) return { level: "medium", label: "Medium", color: "#639922", width: "70%" };
  return { level: "strong", label: "Strong", color: "#1d9e75", width: "100%" };
}

export default function ResetPassword({ token }) {
  const { resetPassword } = useAuth();
  const [form, setForm] = useState({ password: "", confirm: "" });
  const [showPassword, setShowPassword] = useState(false);
  const [passwordTouched, setPasswordTouched] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);

  const strength = getPasswordStrength(form.password);
  const isPasswordAcceptable = ["medium", "strong"].includes(strength.level);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    if (!token) {
      setError("Missing reset token. Please use the full link from your email.");
      return;
    }
    if (!isPasswordAcceptable) {
      setError("Please use a stronger password (at least Medium strength).");
      return;
    }
    if (form.password !== form.confirm) {
      setError("Passwords do not match.");
      return;
    }

    setLoading(true);
    try {
      await resetPassword(token, form.password);
      setSuccess(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <div className="auth-screen">
        <div className="auth-glow" />
        <div className="auth-card">
          <div className="auth-header">
            <div className="auth-logo">✅</div>
            <h2 className="auth-title">Password Reset</h2>
            <p className="auth-subtitle">Your password has been updated</p>
          </div>
          <div style={{ padding: "8px 0 24px" }}>
            <div className="alert alert-success" style={{ marginBottom: 20 }}>
              Your password has been reset successfully. You've been signed out
              of all devices — please log in with your new password.
            </div>
            <button
              className="btn btn-primary btn-full"
              onClick={() => { window.location.href = "/"; }}
            >
              Go to Login
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="auth-screen">
      <div className="auth-glow" />
      <div className="auth-card">
        <div className="auth-header">
          <div className="auth-logo">🔐</div>
          <h1 className="auth-title">CipherSeek</h1>
          <p className="auth-subtitle">Choose a new password</p>
        </div>

        <form className="auth-form" onSubmit={handleSubmit}>
          <h2 className="form-title">Reset Password</h2>

          {error && <div className="alert alert-error">{error}</div>}

          <div className="field">
            <label className="field-label">New Password</label>
            <div className="password-wrapper">
              <input
                className="field-input"
                type={showPassword ? "text" : "password"}
                placeholder="Min 8 characters"
                value={form.password}
                onChange={(e) => setForm({ ...form, password: e.target.value })}
                onFocus={() => setPasswordTouched(true)}
                required
              />
              <button
                type="button"
                className="eye-toggle"
                onClick={() => setShowPassword((v) => !v)}
                tabIndex={-1}
                aria-label={showPassword ? "Hide password" : "Show password"}
              >
                {showPassword ? (
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                    <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                  </svg>
                ) : (
                  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                )}
              </button>
            </div>

            {passwordTouched && form.password.length > 0 && (
              <div className="strength-section">
                <div className="strength-bar-track">
                  <div
                    className="strength-bar-fill"
                    style={{ width: strength.width, backgroundColor: strength.color }}
                  />
                </div>
                <span className="strength-label" style={{ color: strength.color }}>
                  {strength.label}
                </span>
              </div>
            )}
          </div>

          <div className="field">
            <label className="field-label">Confirm New Password</label>
            <input
              className="field-input"
              type="password"
              placeholder="Repeat new password"
              value={form.confirm}
              onChange={(e) => setForm({ ...form, confirm: e.target.value })}
              required
            />
            {form.confirm.length > 0 && (
              <p className="confirm-match" style={{ color: form.password === form.confirm ? "#1d9e75" : "#e24b4a" }}>
                {form.password === form.confirm ? "✓ Passwords match" : "✗ Passwords do not match"}
              </p>
            )}
          </div>

          <button
            className="btn btn-primary btn-full"
            type="submit"
            disabled={loading || !isPasswordAcceptable}
          >
            {loading ? <span className="spinner" /> : "Reset Password"}
          </button>
        </form>

        <div className="auth-badges">
          <span className="badge">AES-256 Encrypted</span>
          <span className="badge">PEKS Scheme</span>
          <span className="badge">Zero-Knowledge Search</span>
        </div>
      </div>
    </div>
  );
}
