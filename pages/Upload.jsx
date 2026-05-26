import { useState, useRef } from "react";
import { useAuth } from "../context/AuthContext";
import API_URL from "../api";

export default function Upload() {
  const { authFetch } = useAuth();
  const [mode, setMode] = useState("text");
  const [keyword, setKeyword] = useState("");
  const [content, setContent] = useState("");
  const [file, setFile] = useState(null);
  const [dragOver, setDragOver] = useState(false);
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(false);
  const fileRef = useRef();

  const reset = () => {
    setKeyword(""); setContent(""); setFile(null); setDragOver(false);
    if (fileRef.current) fileRef.current.value = "";
  };

  const handleDrop = (e) => {
    e.preventDefault();
    setDragOver(false);
    const dropped = e.dataTransfer.files[0];
    if (dropped) setFile(dropped);
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    setStatus(null);
    if (!keyword.trim()) {
      setStatus({ type: "error", msg: "Keyword is required." });
      return;
    }
    setLoading(true);
    try {
      const fd = new FormData();
      fd.append("keyword", keyword.trim());
      if (mode === "text") {
        if (!content.trim()) {
          setStatus({ type: "error", msg: "Content is required." });
          setLoading(false);
          return;
        }
        const blob = new Blob([content], { type: "text/plain" });
        fd.append("document", blob, "document.txt");
        fd.append("format", "text");
      } else {
        if (!file) {
          setStatus({ type: "error", msg: "Please select a file." });
          setLoading(false);
          return;
        }
        fd.append("document", file);
        fd.append("format", file.type || "application/octet-stream");
      }
      const res = await authFetch(`${API_URL}/api/documents/save`, {
        method: "POST",
        body: fd,
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || "Upload failed");
      setStatus({ type: "success", msg: "Document encrypted and stored successfully!" });
      reset();
    } catch (err) {
      setStatus({ type: "error", msg: err.message });
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <h1 className="page-title">Encrypt &amp; Store</h1>
          <p className="page-sub">Your data is encrypted before storage. Keywords are hashed with SHA-256.</p>
        </div>
      </div>

      <div className="upload-layout">
        <div className="upload-card">
          <div className="mode-tabs">
            <button className={`mode-tab ${mode === "text" ? "active" : ""}`} onClick={() => setMode("text")}>
              📝 Text Content
            </button>
            <button className={`mode-tab ${mode === "file" ? "active" : ""}`} onClick={() => setMode("file")}>
              📎 Upload File
            </button>
          </div>

          <form className="upload-form" onSubmit={handleSubmit}>
            {status && <div className={`alert alert-${status.type}`}>{status.msg}</div>}

            {mode === "text" ? (
              <div className="field">
                <label className="field-label">Content</label>
                <textarea
                  className="field-input field-textarea"
                  placeholder="Paste or type your sensitive content here…"
                  value={content}
                  onChange={(e) => setContent(e.target.value)}
                  rows={8}
                />
              </div>
            ) : (
              <div className="field">
                <label className="field-label">File Upload</label>
                <div
                  className={`drop-zone-old ${dragOver ? "drag-active" : ""} ${file ? "has-file" : ""}`}
                  onClick={() => fileRef.current?.click()}
                  onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                  onDragLeave={() => setDragOver(false)}
                  onDrop={handleDrop}
                >
                  {file ? (
                    <div className="drop-zone-old-file">
                      <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#6366f1" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                        <polyline points="14 2 14 8 20 8"/>
                      </svg>
                      <div>
                        <p className="drop-filename">{file.name}</p>
                        <p className="drop-filesize">{(file.size / 1024).toFixed(1)} KB</p>
                      </div>
                      <button
                        type="button"
                        className="drop-remove"
                        onClick={(e) => { e.stopPropagation(); setFile(null); }}
                      >✕</button>
                    </div>
                  ) : (
                    <>
                      <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" className="drop-svg">
                        <polyline points="16 16 12 12 8 16"/>
                        <line x1="12" y1="12" x2="12" y2="21"/>
                        <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
                      </svg>
                      <p className="drop-main">Drag and drop your file here, or <span className="drop-browse">click to browse</span></p>
                      <p className="drop-sub">PDF, Word, Image supported</p>
                    </>
                  )}
                </div>
                <input
                  ref={fileRef}
                  type="file"
                  style={{ display: "none" }}
                  accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                  onChange={(e) => setFile(e.target.files[0])}
                />
              </div>
            )}

            <div className="field">
              <label className="field-label">
                Search Keyword
                <span className="field-hint">Single keyword. Will be SHA-256 hashed.</span>
              </label>
              <input
                className="field-input"
                type="text"
                placeholder="e.g. medical"
                value={keyword}
                onChange={(e) => setKeyword(e.target.value)}
              />
            </div>
            <button className="btn btn-primary btn-full" type="submit" disabled={loading}>
              {loading ? <><span className="spinner" /> Encrypting…</> : "🔒 Encrypt & Store Document"}
            </button>

            <div className="enc-info-box">
              <div className="enc-info-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                  <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span>How encryption works:</span>
              </div>
              <p className="enc-info-text">
                Your document is encrypted using the PEKS algorithm. The keyword generates a secure trapdoor, and the document is stored with an encrypted index. No plaintext keywords are ever exposed.
              </p>
            </div>

            <div className="encryption-info">
              <div className="enc-item"><span className="enc-dot" /><span>Content encrypted with AES-256-CBC</span></div>
              <div className="enc-item"><span className="enc-dot" /><span>Keyword hashed with SHA-256</span></div>
              <div className="enc-item"><span className="enc-dot" /><span>Stored securely in MySQL</span></div>
            </div>
          </form>
        </div>

        <div className="info-panel">
          <h3 className="info-title">How it works</h3>
          <div className="steps">
            {[
              { n: "1", title: "You submit data", desc: "Text or file with a keyword" },
              { n: "2", title: "AES-256 encryption", desc: "Content is encrypted server-side" },
              { n: "3", title: "Keyword hashing", desc: "SHA-256 token for search index" },
              { n: "4", title: "Secure storage", desc: "Ciphertext saved to MySQL" },
            ].map((s) => (
              <div key={s.n} className="step">
                <div className="step-num">{s.n}</div>
                <div>
                  <div className="step-title">{s.title}</div>
                  <div className="step-desc">{s.desc}</div>
                </div>
              </div>
            ))}
          </div>

          <div className="info-badges">
            <div className="info-badge">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#10b981" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
              </svg>
              <span>Secure Storage</span>
              <small>Documents encrypted at rest</small>
            </div>
            <div className="info-badge">
              <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#6366f1" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
              </svg>
              <span>Privacy First</span>
              <small>No keyword exposure</small>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
