export default function About() {
  const modules = [
    { icon: "🖥", title: "User Interface", desc: "Secure web interface for login, encryption, and keyword search operations." },
    { icon: "🗄", title: "Data Storage", desc: "Encrypted ciphertext and keyword index stored in MySQL. Plaintext never persists." },
    { icon: "🔑", title: "Key Management", desc: "ECDH secp256k1 /PEKS-inspired searchable encryption public-private key pair generation. Private key stored securely, public key distributed." },
    { icon: "🔒", title: "Encryption & Indexing", desc: "AES-256-CBC for content. SHA-256 hashing for keywords. Encrypted index maps document IDs to keyword hashes." },
    { icon: "🚪", title: "Trapdoor Generation", desc: "Cryptographic token derived from private key + keyword hash. Sent to server to initiate search without revealing the keyword." },
    { icon: "🛡", title: "Access Control", desc: "Role-based control: Users manage their own data. Admins operate the system but cannot view or decrypt user content." },
  ];

  const steps = [
    {
      step: "01",
      icon: "🔑",
      title: "Key Generation",
      desc: "A public-private key pair is generated using ECDH secp256k1 /PEKS-inspired searchable encryption. The public key is used for encryption; the private key stays with the user and is never sent to the server.",
      tag: "Setup Phase",
    },
    {
      step: "02",
      icon: "🔒",
      title: "Encrypt & Index",
      desc: "Document content is encrypted using AES-256-CBC. Keywords are hashed with SHA-256 and stored as an encrypted index — the server only ever sees ciphertext.",
      tag: "Storage Phase",
    },
    {
      step: "03",
      icon: "🚪",
      title: "Trapdoor Generation",
      desc: "When the user wants to search, a cryptographic trapdoor token is derived from their private key and the keyword hash. This token is sent to the server instead of the raw keyword.",
      tag: "Search Phase",
    },
    {
      step: "04",
      icon: "🔍",
      title: "Secure Search",
      desc: "The server tests the trapdoor against the encrypted index entries. Matching documents are returned — without the server ever learning what keyword was searched.",
      tag: "Match Phase",
    },
    {
      step: "05",
      icon: "📄",
      title: "Decrypt Results",
      desc: "Matched encrypted documents are decrypted client-side using the user's private key. Plaintext is only ever visible to the authorized user, never stored or logged.",
      tag: "Output Phase",
    },
  ];

  return (
    <div className="page">
      <div className="page-header">
        <div>
          <h1 className="page-title">About the Project</h1>
          <p className="page-sub">Secure Searchable Encryption for Web Services</p>
        </div>
      </div>

      <div className="about-grid">
        <div className="about-card wide">
          <h2 className="section-title">Problem Statement</h2>
          <p className="about-text">
            Traditional encryption secures data but makes keyword search impossible without full decryption,
            compromising confidentiality. Existing Searchable Encryption (SE) methods present trade-offs:
            RSA is secure but slow, while SSE is fast but vulnerable to frequency analysis attacks.
          </p>
          <p className="about-text">
            CipherSeek resolves this by implementing a <strong>Public Key Encryption with Keyword Search (PEKS)</strong> scheme
            — users can search encrypted data using cryptographic trapdoors, without the server ever learning
            the actual keyword or document content.
          </p>
        </div>

        <div className="about-card">
          <h2 className="section-title">Tech Stack</h2>
          <div className="tech-list">
            {[
              ["Frontend", "React + Vite + Tailwind"],
              ["Backend", "Node.js + Express.js"],
              ["Database", "MySQL"],
              ["Encryption", "AES-256-CBC + SHA-256"],
              ["Auth", "JWT + bcrypt"],
              ["Search", "PEKS Scheme"],
            ].map(([k, v]) => (
              <div key={k} className="tech-row">
                <span className="tech-key">{k}</span>
                <span className="tech-val">{v}</span>
              </div>
            ))}
          </div>
        </div>

        <div className="about-card">
          <h2 className="section-title">System Modules</h2>
          <div className="modules-list">
            {modules.map((m) => (
              <div key={m.title} className="module-item">
                <span className="module-icon">{m.icon}</span>
                <div>
                  <div className="module-title">{m.title}</div>
                  <div className="module-desc">{m.desc}</div>
                </div>
              </div>
            ))}
          </div>
        </div>

        {/* How It Works — replaces Team */}
        <div className="about-card wide">
          <h2 className="section-title">How It Works</h2>
          <p className="about-text" style={{ marginBottom: "1.25rem" }}>
            CipherSeek operates in five distinct phases — from key setup to secure document retrieval —
            ensuring end-to-end privacy with zero plaintext exposure on the server.
          </p>
          <div className="hiw-steps">
            {steps.map((s, i) => (
              <div className="hiw-step" key={s.step}>
                <div className="hiw-left">
                  <div className="hiw-number">{s.step}</div>
                  {i < steps.length - 1 && <div className="hiw-line" />}
                </div>
                <div className="hiw-body">
                  <div className="hiw-header">
                    <span className="hiw-icon">{s.icon}</span>
                    <span className="hiw-title">{s.title}</span>
                    <span className="hiw-tag">{s.tag}</span>
                  </div>
                  <p className="hiw-desc">{s.desc}</p>
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}