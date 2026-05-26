import { useEffect, useState } from "react";
import { useAuth } from "../context/AuthContext";
import heroBg from "../assets/hero-bg.png";
import API_URL from "../api";

const cryptoBadges = [
  { label: "AES-256", icon: "🔒", cls: "cbadge-aes" },
  { label: "ECC", icon: "🔐", cls: "cbadge-rsa" },
  { label: "SHA-256", icon: "#", cls: "cbadge-sha" },
  { label: "JWT", icon: "🪙", cls: "cbadge-jwt" },
  { label: "PEKS", icon: "🔍", cls: "cbadge-peks" },
];

const workflowSteps = [
  { n: "1", title: "Trapdoor Generation", icon: "🔑", desc: "Generate secure trapdoors from keywords" },
  { n: "2", title: "Encryption", icon: "🔒", desc: "Encrypt documents with AES-256" },
  { n: "3", title: "Index Storage", icon: "🗄", desc: "Store encrypted indexes securely" },
  { n: "4", title: "Search", icon: "🔍", desc: "Search without revealing keywords" },
  { n: "5", title: "Retrieve", icon: "📥", desc: "Decrypt and retrieve results" },
];

export default function Dashboard({ setPage }) {
  const { user, authFetch } = useAuth();
  const [docs, setDocs] = useState([]);

  useEffect(() => {
    async function fetchDocs() {
      try {
        const res = await authFetch(`${API_URL}/api/documents`);
        const data = await res.json();
        setDocs(Array.isArray(data) ? data : data.documents || []);
      } catch {
        setDocs([]);
      }
    }
    fetchDocs();
  }, [authFetch]);

  return (
    <div className="dashboard-root">

      {/* TOP LEFT */}
      <div className="db-topbar">
        <h2>
          Welcome back, <span>{user?.username}</span>
        </h2>
      </div>

      {/* HERO */}
      <div className="hero-section" style={{ backgroundImage: `url(${heroBg})` }}>
        <div className="hero-overlay" />

        <div className="hero-inner">
          <div className="hero-center">

            <h1 className="db-main-title">
              Secure Searchable Encryption
              <span className="db-CipherSeek-accent"> (CipherSeek)</span>
            </h1>

            <p className="db-main-sub">
              Secure keyword-based document search
            </p>

            {/* BADGES */}
            <div className="crypto-badges">
              {cryptoBadges.map((b) => (
                <div key={b.label} className={`cbadge ${b.cls}`}>
                  {b.icon} {b.label}
                </div>
              ))}
            </div>

            {/* BUTTONS */}
            <div className="db-action-btns">
              <button className="db-action-btn upload" onClick={() => setPage("upload")}>
                📤 Upload
              </button>
              <button className="db-action-btn search" onClick={() => setPage("search")}>
                🔍 Search
              </button>
            </div>

          </div>
        </div>
      </div>

      {/* WORKFLOW */}
      <div className="page">
        <div className="workflow-grid">
          {workflowSteps.map((s) => (
            <div key={s.n} className="workflow-card">
              <div className="wf-num">{s.n}</div>
              <div className="wf-icon">{s.icon}</div>
              <div className="wf-title">{s.title}</div>
              <div className="wf-desc">{s.desc}</div>
            </div>
          ))}
        </div>
      </div>

      {/* FOOTER */}
      <footer className="db-footer">
        <div className="db-footer-inner">
          <span className="db-footer-lock">🔐</span>
          <div className="db-footer-text">
            <span className="db-footer-built">Designed &amp; developed by</span>
            <span className="db-footer-name">Banoth Poojitha</span>
            <span className="db-footer-meta"> Bhoj Reddy Engineering College for Women · IT Dept · 2025-26</span>
          </div>
        </div>
      </footer>

    </div>
  );
}
