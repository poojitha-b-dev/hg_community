// frontend/src/pages/ForgotPassword.jsx

import { useState } from "react";
import { useAuth } from "../context/AuthContext";

export default function ForgotPassword({ onBack }) {
  const { forgotPassword } = useAuth();

  const [email, setEmail]       = useState("");
  const [loading, setLoading]   = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [error, setError]       = useState("");

  // Resend: max 3 total (first send + 2 resends)
  const [sendCount, setSendCount]       = useState(0);
  const [resendLoading, setResendLoading] = useState(false);
  const [resendMsg, setResendMsg]       = useState("");
  const [limitReached, setLimitReached] = useState(false);

  const MAX = 3;

  const doSend = async (isResend = false) => {
    if (isResend) setResendLoading(true);
    else setLoading(true);
    setError(""); setResendMsg("");

    try {
      await forgotPassword(email);
      const next = sendCount + 1;
      setSendCount(next);
      if (!isResend) {
        setSubmitted(true);
      } else {
        setResendMsg("Reset link resent! Check your inbox and spam folder.");
      }
    } catch (err) {
      if (err.limitReached) {
        setLimitReached(true);
        setResendMsg("Reset limit reached.");
      } else if (!isResend) {
        // Show inline on the form (e.g. "No account found")
        setError(err.message || "Something went wrong.");
      } else {
        setResendMsg(err.message || "Failed to resend. Please try again.");
      }
    } finally {
      if (isResend) setResendLoading(false);
      else setLoading(false);
    }
  };

  const handleSubmit = (e) => { e.preventDefault(); doSend(false); };

  // ── Success screen ────────────────────────────────────────────────────────
  if (submitted) {
    const resendsLeft = MAX - sendCount;
    return (
      <div className="auth-screen">
        <div className="auth-glow" />
        <div className="auth-card">
          <div className="auth-header">
            <div className="auth-logo">📬</div>
            <h2 className="auth-title">Check your inbox</h2>
            <p className="auth-subtitle">Password reset instructions sent</p>
          </div>
          <div style={{ padding: "8px 0 24px" }}>
            <div className="alert alert-success" style={{ marginBottom: 20 }}>
              A reset link has been sent to <strong>{email}</strong>.
            </div>
            <p style={{ fontSize: 13, color: "var(--text-2)", marginBottom: 20, lineHeight: 1.7 }}>
              The link expires in <strong style={{ color: "var(--text)" }}>1 hour</strong>.
              Check your spam folder if you don't see it.
            </p>

            {resendMsg && (
              <p style={{
                fontSize: 13, marginBottom: 14,
                color: limitReached || resendMsg.includes("Failed") ? "var(--error)" : "var(--success)"
              }}>
                {resendMsg}
              </p>
            )}

            {!limitReached && resendsLeft > 0 && (
              <button className="btn btn-secondary btn-full"
                onClick={() => doSend(true)} disabled={resendLoading}
                style={{ marginBottom: 12 }}>
                {resendLoading ? <span className="spinner" /> : "Resend reset link"}
              </button>
            )}

            <button className="btn btn-primary btn-full" onClick={onBack}>
              Back to Login
            </button>
          </div>
        </div>
      </div>
    );
  }

  // ── Request form ──────────────────────────────────────────────────────────
  return (
    <div className="auth-screen">
      <div className="auth-glow" />
      <div className="auth-card">
        <div className="auth-header">
          <div className="auth-logo">🔑</div>
          <h1 className="auth-title">CipherSeek</h1>
          <p className="auth-subtitle">Reset your password</p>
        </div>

        <form className="auth-form" onSubmit={handleSubmit}>
          <h2 className="form-title">Forgot Password</h2>
          <p style={{ fontSize: 14, color: "var(--text-2)", marginBottom: 20, lineHeight: 1.7 }}>
            Enter your account email and we'll send you a reset link.
          </p>

          {error && <div className="alert alert-error">{error}</div>}

          <div className="field">
            <label className="field-label">Email</label>
            <input className="field-input" type="email"
              placeholder="your@email.com" value={email}
              onChange={(e) => { setEmail(e.target.value); setError(""); }}
              required />
          </div>

          <button className="btn btn-primary btn-full" type="submit" disabled={loading}>
            {loading ? <span className="spinner" /> : "Send Reset Link"}
          </button>

          <p className="auth-switch">
            <button type="button" className="link-btn" onClick={onBack}>← Back to Login</button>
          </p>
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
