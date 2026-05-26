// frontend/src/pages/Register.jsx

import { useState } from "react";
import { useAuth } from "../context/AuthContext";
import CooldownTimer from "../components/CooldownTimer";
import { Eye, EyeOff } from "../components/EyeToggle";

const USERNAME_RE = /^[a-zA-Z0-9._]+$/;
const RESEND_LIMIT = 3;
const COOLDOWN_MS  = 30 * 60 * 1000; // 30 minutes

function getPasswordStrength(pw) {
  let s = 0;
  if (pw.length >= 8)  s++;
  if (pw.length >= 12) s++;
  if (/[A-Z]/.test(pw)) s++;
  if (/[a-z]/.test(pw)) s++;
  if (/[0-9]/.test(pw)) s++;
  if (/[^A-Za-z0-9]/.test(pw)) s++;
  if (s <= 2) return { label: "Weak",   color: "#e24b4a", width: "25%" };
  if (s <= 3) return { label: "Fair",   color: "#ef9f27", width: "50%" };
  if (s <= 4) return { label: "Good",   color: "#639922", width: "75%" };
  return              { label: "Strong", color: "#1d9e75", width: "100%" };
}

export default function Register({ onSwitch }) {
  const { register, resendVerification } = useAuth();

  const [form, setForm]           = useState({ username: "", email: "", password: "", confirm: "" });
  const [loading, setLoading]     = useState(false);
  const [showPw, setShowPw]       = useState(false);
  const [showConfirm, setShowConfirm] = useState(false);
  const [pwTouched, setPwTouched] = useState(false);

  const [usernameErr, setUsernameErr] = useState("");
  const [emailErr, setEmailErr]       = useState("");
  const [generalErr, setGeneralErr]   = useState("");

  // Post-registration resend state
  const [registeredEmail, setRegisteredEmail] = useState(null);
  const [resendCount, setResendCount]   = useState(0);
  const [resendLoading, setResendLoading] = useState(false);
  const [resendMsg, setResendMsg]       = useState("");
  const [cooldownUntil, setCooldownUntil] = useState(null);

  const strength    = getPasswordStrength(form.password);
  const inCooldown  = cooldownUntil && Date.now() < cooldownUntil;

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm(f => ({ ...f, [name]: value }));
    if (name === "username") {
      setUsernameErr(value && !USERNAME_RE.test(value) ? "Only letters, numbers, _ and . allowed." : "");
    }
    if (name === "email") setEmailErr("");
    setGeneralErr("");
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setUsernameErr(""); setEmailErr(""); setGeneralErr("");

    if (!USERNAME_RE.test(form.username)) { setUsernameErr("Only letters, numbers, _ and . allowed."); return; }
    if (form.password.length < 8)         { setGeneralErr("Password must be at least 8 characters."); return; }
    if (form.password !== form.confirm)   { setGeneralErr("Passwords do not match."); return; }

    setLoading(true);
    try {
      await register(form.username, form.email, form.password);
      setRegisteredEmail(form.email);
    } catch (err) {
      if (err.errorType === "email_exists")        setEmailErr(err.message);
      else if (err.errorType === "username_taken") setUsernameErr(err.message);
      else setGeneralErr(err.message || "Registration failed. Please try again.");
    } finally {
      setLoading(false);
    }
  };

  const handleResend = async () => {
    if (inCooldown || resendCount >= RESEND_LIMIT || resendLoading) return;
    setResendLoading(true);
    setResendMsg("");
    try {
      await resendVerification(registeredEmail);
      const newCount = resendCount + 1;
      setResendCount(newCount);
      if (newCount >= RESEND_LIMIT) {
        setCooldownUntil(Date.now() + COOLDOWN_MS);
        setResendMsg("Limit reached. You can resend again after 30 minutes.");
      } else {
        setResendMsg("Verification email sent! Check your inbox and spam folder.");
      }
    } catch (err) {
      if (err.limitReached) {
        setCooldownUntil(Date.now() + COOLDOWN_MS);
        setResendMsg("Limit reached. Please try again after 30 minutes.");
      } else {
        setResendMsg("Failed to resend. Please try again.");
      }
    } finally {
      setResendLoading(false);
    }
  };

  // ── Success / inbox screen ────────────────────────────────────────────────
  if (registeredEmail) {
    return (
      <div className="auth-screen">
        <div className="auth-glow" />
        <div className="auth-card">
          <div className="auth-header">
            <div className="auth-logo">📬</div>
            <h2 className="auth-title">Check your inbox</h2>
            <p className="auth-subtitle">One more step to activate your account</p>
          </div>

          <div style={{ padding: "0 0 24px" }}>
            <div className="alert alert-success" style={{ marginBottom: 20 }}>
              We sent a verification link to{" "}
              <strong>{registeredEmail}</strong>.
              Click that link to activate your account.
            </div>

            <p style={{ fontSize: 13, color: "var(--text-2)", marginBottom: 20, lineHeight: 1.7 }}>
              The link expires in{" "}
              <strong style={{ color: "var(--text)" }}>24 hours</strong>.
              Check your spam folder if you don't see it.
            </p>

            <p style={{ fontSize: 13, color: "#1d9e75", marginBottom: 8, fontWeight: 500 }}>
              Didn't receive it?
            </p>

            {resendMsg && (
              <p style={{
                fontSize: 12, marginBottom: 10,
                color: (inCooldown || resendMsg.toLowerCase().includes("failed"))
                  ? "var(--error)" : "#1d9e75",
              }}>
                {resendMsg}
              </p>
            )}

            {inCooldown && (
              <CooldownTimer
                unlocksAt={cooldownUntil}
                onUnlocked={() => { setCooldownUntil(null); setResendCount(0); setResendMsg(""); }}
              />
            )}

            {!inCooldown && (
              <button
                className="btn btn-full"
                onClick={handleResend}
                disabled={resendLoading}
                style={{
                  marginBottom: 12,
                  background: "linear-gradient(135deg, #1d9e75, #16a34a)",
                  color: "#fff",
                  border: "none",
                  cursor: resendLoading ? "not-allowed" : "pointer",
                  opacity: resendLoading ? 0.7 : 1,
                }}
              >
                {resendLoading ? <span className="spinner" /> : "Resend verification email"}
              </button>
            )}

            <button className="btn btn-primary btn-full" onClick={onSwitch}>
              Go to Login
            </button>
          </div>
        </div>
      </div>
    );
  }

  // ── Registration form ─────────────────────────────────────────────────────
  return (
    <div className="auth-screen">
      <div className="auth-glow" />
      <div className="auth-card">
        <div className="auth-header">
          <div className="auth-logo">🔐</div>
          <h1 className="auth-title">CipherSeek</h1>
          <p className="auth-subtitle">Create your secure account</p>
        </div>

        <form className="auth-form" onSubmit={handleSubmit}>
          <h2 className="form-title">Register</h2>

          {generalErr && <div className="alert alert-error">{generalErr}</div>}

          <div className="field">
            <label className="field-label">Username</label>
            <input className="field-input" type="text" name="username"
              placeholder="john_doe" value={form.username}
              onChange={handleChange}
              style={usernameErr ? { borderColor: "var(--error)" } : {}}
              required />
            {usernameErr
              ? <p style={{ fontSize: 12, color: "var(--error)", marginTop: 4 }}>{usernameErr}</p>
              : <p style={{ fontSize: 11, color: "var(--text-2)", marginTop: 4 }}>Letters, numbers, _ and . only. No spaces.</p>
            }
          </div>

          <div className="field">
            <label className="field-label">Email</label>
            <input className="field-input" type="email" name="email"
              placeholder="your@email.com" value={form.email}
              onChange={handleChange}
              style={emailErr ? { borderColor: "var(--error)" } : {}}
              required />
            {emailErr && <p style={{ fontSize: 12, color: "var(--error)", marginTop: 4 }}>{emailErr}</p>}
          </div>

          <div className="field">
            <label className="field-label">Password</label>
            <div className="password-wrapper">
              <input className="field-input"
                type={showPw ? "text" : "password"} name="password"
                placeholder="Min 8 characters" value={form.password}
                onChange={handleChange} onFocus={() => setPwTouched(true)} required />
              <button type="button" className="eye-toggle" tabIndex={-1}
                onClick={() => setShowPw(v => !v)}
                aria-label={showPw ? "Hide password" : "Show password"}>
                {showPw ? <EyeOff /> : <Eye />}
              </button>
            </div>
            {pwTouched && form.password.length > 0 && (
              <div className="strength-section">
                <div className="strength-bar-track">
                  <div className="strength-bar-fill"
                    style={{ width: strength.width, backgroundColor: strength.color }} />
                </div>
                <span className="strength-label" style={{ color: strength.color }}>{strength.label}</span>
              </div>
            )}
          </div>

          <div className="field">
            <label className="field-label">Confirm Password</label>
            <div className="password-wrapper">
              <input className="field-input"
                type={showConfirm ? "text" : "password"} name="confirm"
                placeholder="Repeat password" value={form.confirm}
                onChange={handleChange} required />
              <button type="button" className="eye-toggle" tabIndex={-1}
                onClick={() => setShowConfirm(v => !v)}
                aria-label={showConfirm ? "Hide password" : "Show password"}>
                {showConfirm ? <EyeOff /> : <Eye />}
              </button>
            </div>
            {form.confirm.length > 0 && (
              <p className="confirm-match"
                style={{ color: form.password === form.confirm ? "#1d9e75" : "#e24b4a" }}>
                {form.password === form.confirm ? "✓ Passwords match" : "✗ Passwords do not match"}
              </p>
            )}
          </div>

          <button className="btn btn-primary btn-full" type="submit" disabled={loading}>
            {loading ? <span className="spinner" /> : "Create Account"}
          </button>

          <p className="auth-switch">
            Already have an account?{" "}
            <button type="button" className="link-btn" onClick={onSwitch}>Sign In</button>
          </p>
        </form>
      </div>
    </div>
  );
}