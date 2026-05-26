// frontend/src/pages/ChangePassword.jsx
// Authenticated page (requires a logged-in session) for changing password.
// Accessible from Navbar → user menu → "Change Password", or setPage("change-password").

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

export default function ChangePassword({ onBack }) {
  const { changePassword } = useAuth();
  const [form, setForm] = useState({ current: "", newPass: "", confirm: "" });
  const [showCurrent, setShowCurrent] = useState(false);
  const [showNew, setShowNew] = useState(false);
  const [newTouched, setNewTouched] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);

  const strength = getPasswordStrength(form.newPass);
  const isPasswordAcceptable = ["medium", "strong"].includes(strength.level);

  const EyeIcon = ({ visible }) => visible ? (
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
  );

  const handleSubmit = async (e) => {
    e.preventDefault();
    setError("");

    if (!isPasswordAcceptable) {
      setError("Please use a stronger new password (at least Medium strength).");
      return;
    }
    if (form.newPass !== form.confirm) {
      setError("New passwords do not match.");
      return;
    }
    if (form.current === form.newPass) {
      setError("New password must differ from your current password.");
      return;
    }

    setLoading(true);
    try {
      await changePassword(form.current, form.newPass);
      setSuccess(true);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  if (success) {
    return (
      <div className="page" style={{ maxWidth: 480 }}>
        <div className="alert alert-success" style={{ marginBottom: 20 }}>
          ✅ Password changed successfully!
        </div>
        <p style={{ fontSize: 14, color: "var(--text-2)", marginBottom: 24 }}>
          Your password has been updated. Your current session remains active.
        </p>
        <button className="btn btn-primary" onClick={onBack}>
          ← Back to Dashboard
        </button>
      </div>
    );
  }

  return (
    <div className="page" style={{ maxWidth: 480 }}>
      <div className="page-header">
        <div>
          <h1 className="page-title">Change Password</h1>
          <p className="page-sub">Update your account password</p>
        </div>
      </div>

      <div style={{
        background: "var(--bg-2)",
        border: "1px solid var(--border)",
        borderRadius: "var(--radius)",
        padding: 28,
      }}>
        <form onSubmit={handleSubmit}>
          {error && <div className="alert alert-error">{error}</div>}

          <div className="field">
            <label className="field-label">Current Password</label>
            <div className="password-wrapper">
              <input
                className="field-input"
                type={showCurrent ? "text" : "password"}
                placeholder="Your current password"
                value={form.current}
                onChange={(e) => setForm({ ...form, current: e.target.value })}
                required
              />
              <button
                type="button"
                className="eye-toggle"
                onClick={() => setShowCurrent((v) => !v)}
                tabIndex={-1}
              >
                <EyeIcon visible={showCurrent} />
              </button>
            </div>
          </div>

          <div className="field">
            <label className="field-label">New Password</label>
            <div className="password-wrapper">
              <input
                className="field-input"
                type={showNew ? "text" : "password"}
                placeholder="Min 8 characters"
                value={form.newPass}
                onChange={(e) => setForm({ ...form, newPass: e.target.value })}
                onFocus={() => setNewTouched(true)}
                required
              />
              <button
                type="button"
                className="eye-toggle"
                onClick={() => setShowNew((v) => !v)}
                tabIndex={-1}
              >
                <EyeIcon visible={showNew} />
              </button>
            </div>

            {newTouched && form.newPass.length > 0 && (
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
              <p className="confirm-match" style={{ color: form.newPass === form.confirm ? "#1d9e75" : "#e24b4a" }}>
                {form.newPass === form.confirm ? "✓ Passwords match" : "✗ Passwords do not match"}
              </p>
            )}
          </div>

          <div style={{ display: "flex", gap: 12, marginTop: 8 }}>
            <button
              className="btn btn-primary"
              type="submit"
              disabled={loading || !isPasswordAcceptable}
            >
              {loading ? <span className="spinner" /> : "Update Password"}
            </button>
            <button
              type="button"
              className="btn btn-secondary"
              onClick={onBack}
            >
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
