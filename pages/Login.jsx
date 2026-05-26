// frontend/src/pages/Login.jsx

import { useState, useEffect } from "react";
import { useAuth } from "../context/AuthContext";
import { useTheme } from "../context/ThemeContext";
import PasswordStrength from "../components/PasswordStrength";
import CooldownTimer from "../components/CooldownTimer";
import { Eye, EyeOff } from "../components/EyeToggle";

const RESEND_LIMIT = 3;
const COOLDOWN_MS  = 30 * 60 * 1000; // 30 minutes


export default function Login({ onSwitch, onForgotPassword }) {
  const { login, resendVerification } = useAuth();
  const { dark, toggle } = useTheme();

  const [form, setForm]       = useState({ email: "", password: "" });
  const [loading, setLoading] = useState(false);
  const [showPw, setShowPw]   = useState(false);

  const [emailErr, setEmailErr]       = useState("");
  const [passwordErr, setPasswordErr] = useState("");
  const [generalErr, setGeneralErr]   = useState("");

  const [unverified, setUnverified]           = useState(false);
  const [unverifiedEmail, setUnverifiedEmail] = useState("");
  const [resendCount, setResendCount]         = useState(0);
  const [resendLoading, setResendLoading]     = useState(false);
  const [resendMsg, setResendMsg]             = useState("");
  const [cooldownUntil, setCooldownUntil]     = useState(null);

  const inCooldown = cooldownUntil && Date.now() < cooldownUntil;
  const limitHit   = resendCount >= RESEND_LIMIT;

  const clearErrors = () => {
    setEmailErr(""); setPasswordErr(""); setGeneralErr("");
    setUnverified(false); setResendMsg("");
  };

  const handleChange = (e) => {
    const { name, value } = e.target;
    setForm(f => ({ ...f, [name]: value }));
    if (name === "email")    setEmailErr("");
    if (name === "password") setPasswordErr("");
    setGeneralErr("");
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    clearErrors();
    setLoading(true);
    try {
      await login(form.email, form.password);
    } catch (err) {
      switch (err.errorType) {
        case "email_not_found":
          setEmailErr("No account found.");
          break;
        case "wrong_password":
          setPasswordErr("Incorrect password.");
          break;
        case "email_not_verified":
          setUnverified(true);
          setUnverifiedEmail(err.email || form.email);
          setGeneralErr("Please verify your email first.");
          break;
        default:
          setGeneralErr(err.message || "Login failed. Please try again.");
      }
    } finally {
      setLoading(false);
    }
  };

  const handleResend = async () => {
    if (inCooldown || limitHit || resendLoading) return;
    setResendLoading(true);
    setResendMsg("");
    try {
      await resendVerification(unverifiedEmail);
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

  return (
    <div className="auth-screen">
      <div className="auth-glow" />
      <button
        type="button"
        className="theme-toggle auth-theme-toggle"
        onClick={toggle}
        title="Toggle theme"
        aria-label={dark ? "Switch to light mode" : "Switch to dark mode"}
      >
        {dark ? "☀" : "🌙"}
      </button>
      <div className="auth-card">
        <div className="auth-header">
          <div className="auth-logo">🔐</div>
          <h1 className="auth-title">CipherSeek</h1>
          <p className="auth-subtitle">Secure Searchable Encryption</p>
        </div>

        <form className="auth-form" onSubmit={handleSubmit}>
          <h2 className="form-title">Sign In</h2>

          {/* General error banner */}
          {generalErr && (
            <div className="alert alert-error">
              {generalErr}

              {/* Verification resend section */}
              {unverified && (
                <div style={{ marginTop: 12 }}>
                  <p style={{ fontSize: 13, color: "#1d9e75", fontWeight: 500, marginBottom: 8 }}>
                    Didn't receive it?
                  </p>

                  {resendMsg && (
                    <p style={{
                      fontSize: 12, marginBottom: 8,
                      color: (inCooldown || resendMsg.toLowerCase().includes("failed"))
                        ? "#fbbf24" : "#1d9e75",
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
                      type="button"
                      onClick={handleResend}
                      disabled={resendLoading}
                      style={{
                        display: "block",
                        width: "100%",
                        padding: "10px 0",
                        marginTop: 4,
                        background: "linear-gradient(135deg, #1d9e75, #16a34a)",
                        color: "#fff",
                        border: "none",
                        borderRadius: 8,
                        fontSize: 13,
                        fontWeight: 600,
                        cursor: resendLoading ? "not-allowed" : "pointer",
                        opacity: resendLoading ? 0.7 : 1,
                      }}
                    >
                      {resendLoading ? "Sending…" : "Resend verification email"}
                    </button>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Email */}
          <div className="field">
            <label className="field-label">Email</label>
            <input className="field-input" type="email" name="email"
              placeholder="your@email.com" value={form.email}
              onChange={handleChange}
              style={emailErr ? { borderColor: "var(--error)" } : {}}
              required />
            {emailErr && <p style={{ fontSize: 12, color: "var(--error)", marginTop: 4 }}>{emailErr}</p>}
          </div>

          {/* Password + eye toggle */}
          <div className="field">
            <label className="field-label" style={{ display: "flex", alignItems: "center" }}>
              Password
              {onForgotPassword && (
                <button type="button" className="link-btn" onClick={onForgotPassword}
                  style={{ marginLeft: "auto", fontSize: 12, fontWeight: 400 }}>
                  Forgot password?
                </button>
              )}
            </label>
            <div className="password-wrapper">
              <input className="field-input"
                type={showPw ? "text" : "password"} name="password"
                placeholder="••••••••" value={form.password}
                onChange={handleChange}
                style={passwordErr ? { borderColor: "var(--error)" } : {}}
                required />
              <button type="button" className="eye-toggle" tabIndex={-1}
                onClick={() => setShowPw(v => !v)}
                aria-label={showPw ? "Hide password" : "Show password"}>
                {showPw ? <EyeOff /> : <Eye />}
              </button>
            </div>
            {passwordErr && <p style={{ fontSize: 12, color: "var(--error)", marginTop: 4 }}>{passwordErr}</p>}
          </div>

          <button className="btn btn-primary btn-full" type="submit" disabled={loading}>
            {loading ? <span className="spinner" /> : "Sign In"}
          </button>

          <p className="auth-switch">
            Don't have an account?{" "}
            <button type="button" className="link-btn" onClick={onSwitch}>Register</button>
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