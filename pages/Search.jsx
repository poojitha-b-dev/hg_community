import { useState } from "react";
import { useAuth } from "../context/AuthContext";
import API_URL from "../api";

export default function Search() {
  const { authFetch } = useAuth();

  const [query, setQuery]       = useState("");
  const [results, setResults]   = useState([]);
  const [searched, setSearched] = useState(false);
  const [loading, setLoading]   = useState(false);
  const [error, setError]       = useState("");

  const handleSearch = async (e) => {
    e.preventDefault();

    if (!query.trim()) {
      setResults([]);
      setSearched(false);
      setError("");
      return;
    }

    setError("");
    setResults([]);
    setSearched(false);
    setLoading(true);

    try {
      const res = await authFetch(
        `${API_URL}/api/documents/verify`,
        {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ keyword: query.trim() }),
        }
      );

      if (res.status === 404) {
        setResults([]);
        setSearched(true);
        setLoading(false);
        return;
      }

      if (!res.ok) {
        const data = await res.json();
        throw new Error(data.message || "Search failed");
      }

      const data = await res.json();
      setResults(data.documents || []);
      setSearched(true);

    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  const handleDownload = (doc) => {
    const bytes = atob(doc.content);
    const arr   = new Uint8Array(bytes.length);
    for (let i = 0; i < bytes.length; i++) arr[i] = bytes.charCodeAt(i);
    const blob = new Blob([arr], { type: doc.format });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement("a");
    a.href     = url;
    a.download = `document-${doc.id}.${doc.format.split("/")[1] || "bin"}`;
    a.click();
    URL.revokeObjectURL(url);
  };

  const fmtDate = (d) => d ? new Date(d).toLocaleString() : "";

  return (
    <div className="search-page">

      {/* ── HEADER ── */}
      <div className="search-container">
        <div className="search-header">
          <h1>Search Documents</h1>
          <p>Search encrypted documents using secure keyword trapdoors</p>
        </div>

        {/* ── FORM ── */}
        <div className="search-card-centered">
          <form onSubmit={handleSearch}>
            <label className="field-label">Search Keyword</label>

            <div className="search-box">
              <input
                className="search-input"
                type="text"
                placeholder="Enter keyword to search..."
                value={query}
                onChange={(e) => {
                  const v = e.target.value;
                  setQuery(v);
                  if (!v.trim()) { setResults([]); setSearched(false); setError(""); }
                }}
                autoFocus
              />
            </div>

            <button
              className="search-main-btn"
              type="submit"
              disabled={loading || !query.trim()}
            >
              {loading ? "Searching..." : "Search Document"}
            </button>

            <p className="search-hint">
              🔒 Your keyword is hashed (SHA-256) before reaching the server
            </p>
          </form>
        </div>
      </div>

      {/* ── RESULTS ── */}
      <div className="search-layout">

        {error && <div className="alert alert-error">{error}</div>}

        {loading && (
          <div className="empty-state">
            <div className="spinner large" />
            <p>Searching encrypted index…</p>
          </div>
        )}

        {/* Nothing found */}
        {searched && !loading && results.length === 0 && (
          <div className="empty-state">
            <div className="empty-icon">🔍</div>
            <p>No document found for "<strong>{query}</strong>"</p>
          </div>
        )}

        {/* Results found */}
        {searched && !loading && results.length > 0 && (
          <div>

            <div style={{ marginBottom: "1rem" }}>
              <span className="badge badge-match" style={{ fontSize: "0.95rem", padding: "0.4rem 1rem" }}>
                {results.length} document{results.length > 1 ? "s" : ""} found for &quot;{query}&quot;
              </span>
            </div>

            {results.map((doc) => (
              <div className="doc-card" key={doc.id} style={{ marginBottom: "1.25rem" }}>

                <div className="doc-card-header">
                  <span className="doc-icon">📄</span>
                  <div>
                    <div className="doc-name">
                      Document #{doc.number}
                      <span style={{ fontWeight: 400, color: "#888", fontSize: "0.82rem", marginLeft: "0.5rem" }}>
                        (ID: {doc.id})
                      </span>
                    </div>
                    <div className="doc-meta">
                      {doc.format === "text" ? "Text document" : doc.format}
                      {doc.created_at && <> &nbsp;·&nbsp; {fmtDate(doc.created_at)}</>}
                    </div>
                  </div>
                  <span className="badge badge-match">Match</span>
                </div>

                {doc.format === "text" && (
                  <pre className="decrypted-content" style={{ marginTop: "0.75rem" }}>
                    {doc.content}
                  </pre>
                )}

                {doc.format !== "text" && (
                  <div style={{ marginTop: "0.75rem" }}>
                    <button className="btn btn-primary" onClick={() => handleDownload(doc)}>
                      ⬇ Download File
                    </button>
                  </div>
                )}

              </div>
            ))}

          </div>
        )}

      </div>
    </div>
  );
}
