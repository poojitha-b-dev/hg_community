// frontend/src/pages/VerifyEmail.jsx
import { useEffect, useState } from "react";
import API_URL from "../api";

export default function VerifyEmail({ token }) {
  const [status, setStatus] = useState("loading"); // loading | success | error | already
  const [message, setMessage] = useState("");

  useEffect(() => {
    if (!token) {
      setStatus("error");
      setMessage("No verification token found in this link. Please use the full link from your email.");
      return;
    }

    fetch(`${API_URL}/api/auth/verify-email/${token}`)
      .then((res) => res.json().then((data) => ({ ok: res.ok, data })))
      .then(({ ok, data }) => {
        if (ok) {
          if (data.message.toLowerCase().includes("already")) {
            setStatus("already");
          } else {
            setStatus("success");
          }
        } else {
          setStatus("error");
        }
        setMessage(data.message);
      })
      .catch(() => {
        setStatus("error");
        setMessage("Network error. Please try again.");
      });
  }, [token]);

  const goToLogin = () => {
    window.location.href = "/";
  };

  return (
    <div className="auth-screen">
      <div className="auth-glow" />
      <div className="auth-card">
        <div className="auth-header">
          <div className="auth-logo">
            {status === "loading" && "⏳"}
            {status === "success" && "✅"}
            {status === "already" && "✅"}
            {status === "error" && "❌"}
          </div>
          <h1 className="auth-title">CipherSeek</h1>
          <p className="auth-subtitle">Email Verification</p>
        </div>

        <div style={{ padding: "8px 0 24px" }}>
          {status === "loading" && (
            <div style={{ textAlign: "center", padding: "24px 0" }}>
              <span className="spinner large" />
              <p style={{ marginTop: 20, color: "var(--text-2)", fontSize: 14 }}>
                Verifying your email address…
              </p>
            </div>
          )}

          {(status === "success" || status === "already") && (
            <>
              <div className="alert alert-success">{message}</div>
              <p style={{ fontSize: 14, color: "var(--text-2)", marginBottom: 24, lineHeight: 1.7 }}>
                Your account is now active. You can sign in with your email and password.
              </p>
              <button className="btn btn-primary btn-full" onClick={goToLogin}>
                Go to Login
              </button>
            </>
          )}

          {status === "error" && (
            <>
              <div className="alert alert-error">{message}</div>
              <p style={{ fontSize: 14, color: "var(--text-2)", marginBottom: 24, lineHeight: 1.7 }}>
                If your link has expired, you can request a new verification email from
                the login page by attempting to sign in.
              </p>
              <button className="btn btn-primary btn-full" onClick={goToLogin}>
                Back to Login
              </button>
            </>
          )}
        </div>

        <div className="auth-badges">
          <span className="badge">AES-256 Encrypted</span>
          <span className="badge">PEKS Scheme</span>
          <span className="badge">Zero-Knowledge Search</span>
        </div>
      </div>
    </div>
  );
}
