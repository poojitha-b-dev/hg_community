<?php
require_once 'includes/auth.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $auth->getCurrentUser();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

$logoutToken = Auth::generateLogoutToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HG Community - Hackers Gurukul</title>
    <link rel="stylesheet" href="assets/css/main.css">
    <link rel="stylesheet" href="assets/css/dm.css">
</head>
<body>
<div class="app-container">

    <!-- ═══════════════════════════════════════════════════════════════════════
         LEFT SIDEBAR — Channels
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="sidebar-left">
        <div class="server-header" id="server-header">
            <div class="server-banner" id="server-banner" style="display:none">
                <img id="server-banner-img" src="" alt="">
            </div>
            <div class="server-identity">
                <div class="server-logo" id="server-logo-wrap">
                    <img id="server-logo-img" src="assets/images/default-avatar.png" alt="Logo"
                         onerror="this.src='assets/images/default-avatar.png'">
                </div>
                <div>
                    <h2 id="server-name">HG Community</h2>
                    <p id="server-tagline" style="color:#949ba4;font-size:.75rem;margin:0"></p>
                </div>
            </div>
            <div class="user-info">
                <div class="avatar" style="cursor:pointer" onclick="app.openProfile({id:<?php echo $user['id'];?>,username:'<?php echo htmlspecialchars($user['username']);?>',role:'<?php echo $user['role'];?>',avatar:'<?php echo htmlspecialchars($user['avatar']??'');?>'})">
                    <img src="<?php $av=$user['avatar']??''; echo htmlspecialchars($av?:'assets/images/default-avatar.png'); ?>"
                         alt="Avatar" onerror="this.src='assets/images/default-avatar.png'">
                    <div class="status-indicator online"></div>
                </div>
                <span class="username"><?php echo htmlspecialchars($user['username']); ?></span>
                <span class="role-badge role-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span>
            </div>
        </div>

        <div class="channels-container">
            <div class="channel-category">
                <h3>📢 Announcements</h3>
                <div id="announcement-channels"></div>
            </div>
            <div class="channel-category">
                <h3>👥 Teams</h3>
                <div id="team-channels"></div>
            </div>
            <div class="channel-category">
                <h3>💻 Technical</h3>
                <div id="technical-channels"></div>
            </div>
            <div class="channel-category">
                <h3>💬 General</h3>
                <div id="general-channels"></div>
            </div>
        </div>

        <?php if ($user['role'] === 'admin'): ?>
        <div class="admin-panel">
            <button class="admin-panel-toggle" id="admin-panel-toggle">
                <span>Admin Panel</span>
                <span class="admin-toggle-icon">▼</span>
            </button>
            <div class="admin-controls" id="admin-controls" style="display:none">
                <button id="community-settings-btn" class="control-btn">🏠 Community Settings</button>
                <button id="create-channel-btn"     class="control-btn">＋ New Channel</button>
                <button id="manage-channels-btn"    class="control-btn">📋 Manage Channels</button>
                <button id="create-invite-btn"      class="control-btn">🔗 Create Invite</button>
                <button id="manage-invites-btn"     class="control-btn">📨 Manage Invites</button>
                <button id="manage-users-btn"       class="control-btn">👥 Manage Users</button>
            </div>
        </div>
        <?php elseif ($user['role'] === 'moderator'): ?>
        <div class="admin-panel">
            <button class="admin-panel-toggle" id="admin-panel-toggle">
                <span>Moderator Panel</span>
                <span class="admin-toggle-icon">▼</span>
            </button>
            <div class="admin-controls" id="admin-controls" style="display:none">
                <button id="create-invite-btn"  class="control-btn">🔗 Create Invite</button>
                <button id="manage-invites-btn" class="control-btn">📨 Manage Invites</button>
                <button id="manage-users-btn"   class="control-btn">👥 Manage Users</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="user-controls">
            <button id="connections-btn" class="control-btn">🤝 Connections
                <span id="conn-requests-badge" style="display:none;background:#ed4245;color:#fff;
                    border-radius:8px;padding:1px 6px;font-size:.72rem;margin-left:4px"></span>
            </button>
            <button id="dm-btn" class="control-btn">
                💬 Messages
                <span id="dm-sidebar-badge" class="dm-sidebar-badge" style="display:none"></span>
            </button>
            <button id="settings-btn" class="control-btn">⚙️ Settings</button>
            <button id="logout-btn" class="control-btn"
                    data-token="<?php echo htmlspecialchars($logoutToken); ?>">🚪 Logout</button>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         MAIN CONTENT — Chat
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="main-content">

        <!-- Chat header with channel name + action buttons -->
        <div class="chat-header">
            <h3 id="current-channel">Select a channel</h3>
            <div class="channel-actions">
                <!-- Search -->
                <div class="search-wrapper">
                    <input type="text"
                           id="message-search-input"
                           placeholder="🔍 Search messages…"
                           autocomplete="off">
                    <div id="search-results" class="search-results" style="display:none"></div>
                </div>

                <!-- Pinned messages -->
                <button id="pinned-messages-btn" class="action-btn" title="Pinned messages" style="display:none">
                    📌 Pinned
                </button>
                <button id="channel-info-btn" class="action-btn" title="Channel info" style="display:none">
                    ℹ️ Info
                </button>

                <?php if ($user['role'] === 'admin' || $user['role'] === 'moderator'): ?>
                <!-- Manage team members (team channels only) -->
                <button id="manage-team-btn" class="action-btn" title="Manage team members"
                        style="display:none;align-items:center;gap:5px">
                    👥 Manage Team
                </button>
                <?php endif; ?>

                <?php if ($user['role'] === 'admin'): ?>
                <!-- Edit / Delete current channel (admin only) -->
                <button id="edit-channel-btn"   class="action-btn" title="Edit channel"   style="display:none">✏️ Edit</button>
                <button id="delete-channel-btn" class="action-btn action-btn-danger" title="Delete channel" style="display:none">🗑️ Delete</button>
                <?php endif; ?>

                <button id="upload-file-btn" class="action-btn">📎 Upload</button>
            </div>
        </div>

        <!-- Messages area -->
        <div class="messages-container" id="messages-container">
            <div class="welcome-message">
                <h2>Welcome to HG Community! 👋</h2>
                <p>Select a channel from the sidebar to start chatting.</p>
            </div>
        </div>

        <!-- Typing indicator -->
        <div id="typing-indicator" class="typing-indicator"></div>

        <!-- Read-only bar for members in announcement channels -->
        <div id="announce-readonly-bar" style="display:none;align-items:center;gap:10px;
             padding:14px 20px;background:#1e1f22;border-top:1px solid #3f4147;
             color:#949ba4;font-size:.88rem">
            <span style="font-size:1.1rem">📢</span>
            <span>This is an announcement channel. Only admins and moderators can post here.</span>
        </div>

        <!-- Message input -->
        <div class="message-input-container" style="display:none">
            <form id="message-form" enctype="multipart/form-data">
                <input type="hidden" id="current-channel-id" name="channel_id">
                <div class="file-preview" id="file-preview" style="display:none"></div>
                <div class="input-row">
                    <input type="text" id="message-input" name="content"
                           placeholder="Type a message…" autocomplete="off">
                    <input type="file" id="file-input" name="file" style="display:none"
                           accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt">
                    <button type="button" id="file-btn" class="file-button">📎</button>
                    <button type="submit" class="send-button">Send</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════
         RIGHT SIDEBAR — Online Members
    ════════════════════════════════════════════════════════════════════════ -->
    <div class="sidebar-right">
        <div class="members-header">
            <h3>Online Members</h3>
            <span id="online-count">0 online</span>
        </div>
        <div class="members-list" id="members-list"></div>
    </div>
</div><!-- .app-container -->


<!-- ═══════════════════════════════════════════════════════════════════════════
     MODALS
════════════════════════════════════════════════════════════════════════════ -->

<!-- Create Channel -->
<div id="create-channel-modal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Create New Channel</h2>
        <form id="create-channel-form">
            <div class="form-group">
                <label>Channel Name</label>
                <input type="text" id="channel-name" name="name" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="channel-description" name="description"></textarea>
            </div>
            <div class="form-group">
                <label>Type</label>
                <select id="channel-type" name="type" required>
                    <option value="general">General</option>
                    <option value="team">Team</option>
                    <option value="technical">Technical</option>
                    <option value="announcement">Announcement</option>
                </select>
            </div>
            <div class="form-group" id="team-name-group" style="display:none">
                <label>Team Name</label>
                <input type="text" id="team-name" name="team_name">
            </div>
            <button type="submit">Create Channel</button>
        </form>
    </div>
</div>

<!-- Edit Channel (admin) -->
<div id="edit-channel-modal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Edit Channel</h2>
        <form id="edit-channel-form">
            <input type="hidden" id="edit-channel-id">
            <div class="form-group">
                <label>Channel Name</label>
                <input type="text" id="edit-channel-name" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="edit-channel-desc"></textarea>
            </div>
            <div class="form-group">
                <label>Type</label>
                <select id="edit-channel-type">
                    <option value="general">General</option>
                    <option value="team">Team</option>
                    <option value="technical">Technical</option>
                    <option value="announcement">Announcement</option>
                </select>
            </div>
            <button type="submit">Save Changes</button>
        </form>
    </div>
</div>

<!-- Pinned Messages -->
<div id="pinned-messages-modal" class="modal">
    <div class="modal-content" style="max-width:560px">
        <span class="close">&times;</span>
        <h2>📌 Pinned Messages</h2>
        <div id="pinned-messages-list" style="margin-top:16px;max-height:400px;overflow-y:auto"></div>
    </div>
</div>

<!-- Create Invite -->
<div id="create-invite-modal" class="modal">
    <div class="modal-content" style="max-width:480px">
        <span class="close">&times;</span>
        <h2>🔗 Create Invite</h2>

        <form id="create-invite-form">
            <!-- Invite type selector -->
            <div class="form-group">
                <label>Invite Type</label>
                <div class="invite-type-selector">
                    <label class="invite-type-option" id="invite-type-single-label">
                        <input type="radio" name="invite_type" value="single" id="invite-type-single" checked>
                        <div class="invite-type-card">
                            <span class="invite-type-icon">👤</span>
                            <strong>Single Person</strong>
                            <small>One-time use — link expires after first registration</small>
                        </div>
                    </label>
                    <label class="invite-type-option" id="invite-type-group-label">
                        <input type="radio" name="invite_type" value="group" id="invite-type-group">
                        <div class="invite-type-card">
                            <span class="invite-type-icon">👥</span>
                            <strong>Group Link</strong>
                            <small>Multiple people can use the same link until it expires</small>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Email — required for single, hidden for group -->
            <div class="form-group" id="invite-email-group">
                <label>Email <span style="color:#ed4245">*</span>
                    <span style="color:#949ba4;font-size:.78rem;font-weight:400"> — required for single invites</span>
                </label>
                <input type="email" id="invite-email" name="email" required
                       placeholder="person@example.com">
            </div>

            <div class="form-group">
                <label>Role</label>
                <select id="invite-role" name="role">
                    <option value="member">Member</option>
                    <option value="moderator">Moderator</option>
                </select>
            </div>

            <div class="form-group">
                <label>Expires in</label>
                <select id="expiry-hours" name="expiry_hours">
                    <option value="1">1 hour</option>
                    <option value="6">6 hours</option>
                    <option value="12">12 hours</option>
                    <option value="24" selected>24 hours</option>
                    <option value="48">2 days</option>
                    <option value="168">7 days</option>
                </select>
                <small style="color:#949ba4;font-size:.78rem;margin-top:4px;display:block">
                    ⚠️ Link will hard-expire at exactly this time — no extensions
                </small>
            </div>

            <button type="submit" style="width:100%;padding:10px;background:#5865f2;color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer">
                Generate Invite
            </button>
        </form>

        <div id="invite-result" style="display:none;margin-top:16px">
            <div style="background:#2b2d31;border-radius:8px;padding:16px">
                <p style="color:#23a559;font-weight:600;margin-bottom:12px">✅ Invite created!</p>
                <div style="margin-bottom:10px">
                    <label style="color:#949ba4;font-size:.8rem;display:block;margin-bottom:4px">Invite Link</label>
                    <div style="display:flex;gap:6px">
                        <input type="text" id="invite-url" readonly
                               style="flex:1;background:#383a40;border:1px solid #4f545c;border-radius:6px;
                                      padding:8px 10px;color:#fff;font-size:.82rem;font-family:monospace">
                        <button onclick="copyInviteLink()"
                                style="background:#5865f2;color:#fff;border:none;border-radius:6px;
                                       padding:8px 14px;cursor:pointer;font-size:.82rem;font-weight:600;white-space:nowrap">
                            Copy
                        </button>
                    </div>
                </div>
                <div>
                    <label style="color:#949ba4;font-size:.8rem;display:block;margin-bottom:4px">Expires</label>
                    <span id="invite-expires-display" style="color:#f0b232;font-size:.85rem"></span>
                </div>
                <button onclick="app.resetInviteForm()"
                        style="margin-top:12px;background:transparent;border:1px solid #4f545c;color:#949ba4;
                               border-radius:6px;padding:6px 14px;cursor:pointer;font-size:.82rem">
                    Create Another
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Profile View Modal -->
<div id="profile-modal" class="modal">
    <div class="modal-content" style="max-width:380px;text-align:center">
        <span class="close">&times;</span>
        <div style="padding:20px 10px 10px">
            <img id="profile-modal-avatar"
                 src="assets/images/default-avatar.png"
                 onerror="this.src='assets/images/default-avatar.png'"
                 style="width:90px;height:90px;border-radius:50%;object-fit:cover;
                        border:3px solid #5865f2;margin-bottom:12px">
            <div id="profile-modal-username"
                 style="font-size:1.15rem;font-weight:700;color:#fff;margin-bottom:4px"></div>
            <div id="profile-modal-role" style="margin-bottom:10px"></div>
            <div id="profile-modal-bio"
                 style="color:#949ba4;font-size:.88rem;line-height:1.5;margin-bottom:14px;
                        min-height:20px;padding:0 10px"></div>
            <div id="profile-modal-joined"
                 style="color:#72767d;font-size:.78rem;margin-bottom:16px"></div>
            <!-- Connection status + action -->
            <div id="profile-modal-connection" style="margin-bottom:10px"></div>
            <button id="profile-modal-dm-btn"
                    style="background:#5865f2;color:#fff;border:none;border-radius:8px;
                           padding:9px 24px;font-size:.9rem;font-weight:600;cursor:pointer;
                           width:100%;display:none">
                Send Message
            </button>
        </div>
    </div>
</div>

<!-- Connections Page -->
<div id="connections-modal" class="modal">
    <div class="modal-content" style="max-width:720px">
        <span class="close">&times;</span>
        <h2>🤝 Connections</h2>

        <!-- Tabs -->
        <div style="display:flex;gap:4px;margin-bottom:20px;background:#2b2d31;
                    border-radius:8px;padding:4px">
            <button class="conn-tab active" data-tab="my-connections"
                    style="flex:1;padding:8px;border:none;border-radius:6px;cursor:pointer;
                           font-size:.88rem;font-weight:600;background:#5865f2;color:#fff">
                My Connections
            </button>
            <button class="conn-tab" data-tab="requests"
                    style="flex:1;padding:8px;border:none;border-radius:6px;cursor:pointer;
                           font-size:.88rem;font-weight:600;background:transparent;color:#949ba4">
                Requests <span id="requests-badge" style="display:none;background:#ed4245;
                    color:#fff;border-radius:8px;padding:1px 6px;font-size:.72rem;margin-left:4px"></span>
            </button>
            <button class="conn-tab" data-tab="discover"
                    style="flex:1;padding:8px;border:none;border-radius:6px;cursor:pointer;
                           font-size:.88rem;font-weight:600;background:transparent;color:#949ba4">
                Discover People
            </button>
        </div>

        <!-- My Connections -->
        <div id="tab-my-connections" class="conn-tab-content">
            <div id="connections-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
                <p style="color:#949ba4;grid-column:1/-1;text-align:center;padding:30px">Loading…</p>
            </div>
        </div>

        <!-- Requests -->
        <div id="tab-requests" class="conn-tab-content" style="display:none">
            <div id="requests-list">
                <p style="color:#949ba4;text-align:center;padding:30px">Loading…</p>
            </div>
        </div>

        <!-- Discover -->
        <div id="tab-discover" class="conn-tab-content" style="display:none">
            <input type="text" id="discover-search" placeholder="Search people…"
                   style="width:100%;padding:8px 12px;background:#383a40;border:1px solid #4f545c;
                          border-radius:6px;color:#fff;font-size:.9rem;margin-bottom:12px">
            <div id="discover-list" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px">
                <p style="color:#949ba4;grid-column:1/-1;text-align:center;padding:30px">Loading…</p>
            </div>
        </div>
    </div>
</div>

<!-- Settings -->
<div id="settings-modal" class="modal">
    <div class="modal-content" style="max-width:480px">
        <span class="close">&times;</span>
        <h2>Settings</h2>

        <div style="text-align:center;margin-bottom:20px">
            <img id="settings-avatar-preview"
                 src="<?php $av=$user['avatar']??''; echo htmlspecialchars($av ?: 'assets/images/default-avatar.png'); ?>"
                 onerror="this.src='assets/images/default-avatar.png'"
                 style="width:80px;height:80px;border-radius:50%;object-fit:cover;border:3px solid #5865f2">
            <div style="margin-top:10px">
                <label for="avatar-upload"
                       style="cursor:pointer;background:#5865f2;color:#fff;padding:6px 14px;border-radius:6px;font-size:.85rem">
                    Change Avatar
                </label>
                <input type="file" id="avatar-upload" accept="image/*" style="display:none">
            </div>
            <div style="margin-top:10px">
                <label style="color:#949ba4;font-size:.8rem;display:block;margin-bottom:4px">Who can see my avatar</label>
                <select id="settings-avatar-visibility"
                        style="background:#383a40;border:1px solid #4f545c;border-radius:6px;
                               color:#dcddde;padding:6px 10px;font-size:.85rem;width:100%;max-width:220px">
                    <option value="everyone"    <?php echo ($user['avatar_visibility']??'everyone')==='everyone'    ? 'selected' : ''; ?>>🌐 Everyone</option>
                    <option value="connections" <?php echo ($user['avatar_visibility']??'everyone')==='connections' ? 'selected' : ''; ?>>🤝 Connections only</option>
                    <option value="nobody"      <?php echo ($user['avatar_visibility']??'everyone')==='nobody'      ? 'selected' : ''; ?>>🔒 Nobody</option>
                </select>
            </div>
        </div>

        <form id="settings-form">
            <div class="form-group">
                <label>Username <span style="color:#949ba4;font-size:.78rem">(a–z, 0–9, . _ only)</span></label>
                <input type="text" id="settings-username" name="username"
                       placeholder="<?php echo htmlspecialchars($user['username']); ?>"
                       pattern="[a-z0-9._]{3,30}" title="3–30 chars, a-z 0-9 . _ only">
            </div>
            <div class="form-group">
                <label>Email <span style="color:#ed4245;font-size:.78rem">🔒 cannot be changed</span></label>
                <input type="email" value="<?php echo htmlspecialchars($user['email']); ?>"
                       disabled style="opacity:.5;cursor:not-allowed">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="tel" id="settings-phone" name="phone"
                       placeholder="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Bio</label>
                <textarea id="settings-bio" name="bio" rows="2"
                          placeholder="Tell the community about yourself…"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
            </div>
            <hr style="border-color:#3f4147;margin:16px 0">
            <p style="color:#949ba4;font-size:.82rem;margin-bottom:12px">
                Leave password fields blank to keep your current password.
            </p>
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" id="settings-current-password" name="current_password"
                       placeholder="Required to change password">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" id="settings-new-password" name="new_password"
                       placeholder="New password">
            </div>
            <button type="submit">Save Changes</button>
        </form>
    </div>
</div>

<!-- Team Member Manager -->
<div id="team-manager-modal" class="modal">
    <div class="modal-content" style="max-width:500px">
        <span class="close">&times;</span>
        <h2 id="team-manager-title">👥 Team Members</h2>
        <div id="team-members-list" style="max-height:480px;overflow-y:auto">
            <p style="color:#949ba4;text-align:center;padding:20px">Loading…</p>
        </div>
    </div>
</div>

<!-- Manage Channels (Admin) -->
<div id="manage-channels-modal" class="modal">
    <div class="modal-content" style="max-width:700px">
        <span class="close">&times;</span>
        <h2>📋 Manage Channels</h2>
        <div style="margin-bottom:12px">
            <input type="text" id="channel-search" placeholder="Search channels…"
                   style="width:100%;padding:8px 12px;background:#383a40;border:1px solid #4f545c;
                          border-radius:6px;color:#fff;font-size:.9rem">
        </div>
        <div id="channels-table-container">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead>
                    <tr style="border-bottom:1px solid #3f4147;color:#949ba4;text-align:left">
                        <th style="padding:8px 10px">Name</th>
                        <th style="padding:8px 10px">Type</th>
                        <th style="padding:8px 10px">Description</th>
                        <th style="padding:8px 10px">Created</th>
                        <th style="padding:8px 10px">Actions</th>
                    </tr>
                </thead>
                <tbody id="channels-table-body">
                    <tr><td colspan="5" style="text-align:center;padding:20px;color:#949ba4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Manage Invites (Admin) -->
<div id="manage-invites-modal" class="modal">
    <div class="modal-content" style="max-width:750px">
        <span class="close">&times;</span>
        <h2>📨 Manage Invites</h2>
        <div id="invites-table-container">
            <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead>
                    <tr style="border-bottom:1px solid #3f4147;color:#949ba4;text-align:left">
                        <th style="padding:8px 10px">Code</th>
                        <th style="padding:8px 10px">Type</th>
                        <th style="padding:8px 10px">Role</th>
                        <th style="padding:8px 10px">Created By</th>
                        <th style="padding:8px 10px">Expires</th>
                        <th style="padding:8px 10px">Status</th>
                        <th style="padding:8px 10px">Actions</th>
                    </tr>
                </thead>
                <tbody id="invites-table-body">
                    <tr><td colspan="7" style="text-align:center;padding:20px;color:#949ba4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Community Settings (Admin) -->
<div id="community-settings-modal" class="modal">
    <div class="modal-content" style="max-width:560px">
        <span class="close">&times;</span>
        <h2>🏠 Community Settings</h2>

        <!-- Stats bar -->
        <div id="community-stats" style="display:flex;gap:12px;flex-wrap:wrap;
             background:#2b2d31;border-radius:8px;padding:12px 16px;margin-bottom:20px">
            <div class="stat-item"><span id="stat-members">—</span><label>Members</label></div>
            <div class="stat-item"><span id="stat-channels">—</span><label>Channels</label></div>
            <div class="stat-item"><span id="stat-teams">—</span><label>Teams</label></div>
            <div class="stat-item"><span id="stat-messages">—</span><label>Messages</label></div>
        </div>

        <!-- Logo upload -->
        <div style="display:flex;gap:16px;align-items:flex-start;margin-bottom:16px">
            <div>
                <img id="community-logo-preview" src="assets/images/default-avatar.png"
                     style="width:70px;height:70px;border-radius:12px;object-fit:cover;
                            border:2px solid #4f545c">
            </div>
            <div style="flex:1">
                <label style="display:block;color:#949ba4;font-size:.8rem;margin-bottom:6px">Community Logo</label>
                <input type="file" id="logo-upload" accept="image/*"
                       style="font-size:.82rem;color:#949ba4">
            </div>
        </div>

        <!-- Banner upload -->
        <div style="margin-bottom:16px">
            <label style="display:block;color:#949ba4;font-size:.8rem;margin-bottom:6px">Banner Image</label>
            <div id="community-banner-preview" style="width:100%;height:80px;background:#2b2d31;
                 border-radius:8px;border:2px dashed #4f545c;overflow:hidden;margin-bottom:6px">
                <img id="community-banner-img" src="" style="width:100%;height:100%;object-fit:cover;display:none">
            </div>
            <input type="file" id="banner-upload" accept="image/*"
                   style="font-size:.82rem;color:#949ba4">
        </div>

        <form id="community-settings-form">
            <div class="form-group">
                <label>Community Name</label>
                <input type="text" id="community-name" required
                       style="width:100%;padding:9px 12px;background:#383a40;border:1px solid #4f545c;
                              border-radius:6px;color:#fff;font-size:.9rem">
            </div>
            <div class="form-group" style="margin-top:10px">
                <label>Tagline <span style="color:#949ba4;font-size:.78rem">(short description shown under logo)</span></label>
                <input type="text" id="community-tagline" maxlength="80"
                       style="width:100%;padding:9px 12px;background:#383a40;border:1px solid #4f545c;
                              border-radius:6px;color:#fff;font-size:.9rem">
            </div>
            <div class="form-group" style="margin-top:10px">
                <label>Description</label>
                <textarea id="community-description" rows="3"
                          style="width:100%;padding:9px 12px;background:#383a40;border:1px solid #4f545c;
                                 border-radius:6px;color:#fff;font-size:.9rem;resize:vertical"></textarea>
            </div>
            <button type="submit"
                    style="margin-top:14px;width:100%;padding:10px;background:#5865f2;color:#fff;
                           border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer">
                Save Settings
            </button>
        </form>
    </div>
</div>

<!-- Channel Info Panel (slides in from right) -->
<div id="channel-info-panel" style="display:none;position:fixed;top:0;right:0;width:300px;height:100vh;
     background:#2b2d31;border-left:1px solid #3f4147;z-index:200;overflow-y:auto;
     box-shadow:-4px 0 20px rgba(0,0,0,.4)">
    <div style="padding:16px;border-bottom:1px solid #3f4147;display:flex;
                align-items:center;justify-content:space-between">
        <h3 id="channel-info-name" style="color:#fff;margin:0;font-size:1rem"></h3>
        <button onclick="document.getElementById('channel-info-panel').style.display='none'"
                style="background:none;border:none;color:#949ba4;font-size:1.2rem;cursor:pointer">✕</button>
    </div>
    <div style="padding:16px">
        <div id="channel-info-type" style="margin-bottom:12px"></div>
        <div id="channel-info-desc" style="color:#949ba4;font-size:.88rem;line-height:1.5;margin-bottom:16px"></div>
        <div id="channel-info-stats" style="display:flex;gap:12px;margin-bottom:16px"></div>
        <div id="channel-info-members-section" style="display:none">
            <div style="color:#949ba4;font-size:.78rem;font-weight:600;text-transform:uppercase;
                        letter-spacing:.5px;margin-bottom:8px">Team Members</div>
            <div id="channel-info-members"></div>
        </div>
        <div id="channel-info-pinned-section" style="display:none;margin-top:16px">
            <div style="color:#949ba4;font-size:.78rem;font-weight:600;text-transform:uppercase;
                        letter-spacing:.5px;margin-bottom:8px">Pinned Messages</div>
            <div id="channel-info-pinned"></div>
        </div>
    </div>
</div>

<!-- Manage Users (Admin) -->
<div id="manage-users-modal" class="modal">
    <div class="modal-content" style="max-width:740px">
        <span class="close">&times;</span>
        <h2>👥 Manage Users</h2>

        <!-- Status legend -->
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;
                    background:#2b2d31;border-radius:8px;padding:10px 14px">
            <span style="font-size:.78rem;color:#949ba4;font-weight:600;align-self:center">Status:</span>
            <span class="status-legend-item" title="User can access everything normally">
                <span style="color:#23a559">●</span> Active — full access
            </span>
            <span class="status-legend-item" title="Can read messages but cannot send any">
                <span style="color:#f0b232">●</span> Muted — read only
            </span>
            <span class="status-legend-item" title="Can only see public channels, cannot DM or post">
                <span style="color:#949ba4">●</span> Restricted — public channels only, no DMs
            </span>
            <span class="status-legend-item" title="Completely blocked from the platform">
                <span style="color:#ed4245">●</span> Banned — no access
            </span>
        </div>

        <div style="margin-bottom:12px">
            <input type="text" id="user-search" placeholder="Search users…"
                   style="width:100%;padding:8px 12px;background:#383a40;border:1px solid #4f545c;
                          border-radius:6px;color:#fff;font-size:.9rem">
        </div>
        <div id="users-table-container" style="overflow-x:auto">
            <table id="users-table" style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead>
                    <tr style="border-bottom:1px solid #3f4147;color:#949ba4;text-align:left">
                        <th style="padding:8px 10px">Username</th>
                        <th style="padding:8px 10px">Email</th>
                        <th style="padding:8px 10px">Role</th>
                        <th style="padding:8px 10px">Status</th>
                        <th style="padding:8px 10px;min-width:220px">Actions</th>
                    </tr>
                </thead>
                <tbody id="users-table-body">
                    <tr><td colspan="5" style="text-align:center;padding:20px;color:#949ba4">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════════════════════
     DM — Conversation window
════════════════════════════════════════════════════════════════════════════ -->
<div id="dm-modal" class="modal">
    <div class="modal-content dm-modal-content">

        <!-- Header -->
        <div class="dm-header">
            <button class="dm-back-btn" id="dm-back-btn">← All DMs</button>
            <div class="dm-recipient-info" id="dm-recipient-info">
                <img id="dm-recipient-avatar"
                     src="assets/images/default-avatar.png"
                     alt="Avatar"
                     onerror="this.src='assets/images/default-avatar.png'">
                <span class="dm-recipient-name" id="dm-recipient-name"></span>
            </div>
            <span class="close" style="margin-left:auto">&times;</span>
        </div>

        <!-- Messages -->
        <div class="dm-messages" id="dm-messages-container">
            <p class="dm-empty">Select a conversation to get started.</p>
        </div>

        <!-- Input -->
        <div class="dm-input-area">
            <form id="dm-form" enctype="multipart/form-data">
                <input type="hidden" id="dm-recipient-id">
                <div class="dm-file-preview" id="dm-file-preview"></div>
                <div class="dm-input-row">
                    <input type="text" id="dm-input" placeholder="Message…" autocomplete="off">
                    <input type="file" id="dm-file-input" style="display:none"
                           accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.txt">
                    <button type="button" class="dm-file-btn" id="dm-file-btn">📎</button>
                    <button type="submit" class="dm-send-btn">Send</button>
                </div>
            </form>
        </div>

    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     DM — Conversations list
════════════════════════════════════════════════════════════════════════════ -->
<div id="dm-list-modal" class="modal">
    <div class="modal-content" style="max-width:480px">
        <span class="close">&times;</span>
        <h2>💬 Messages</h2>

        <!-- Tabs -->
        <div style="display:flex;gap:4px;margin-bottom:16px;background:#2b2d31;border-radius:8px;padding:4px">
            <button class="dm-main-tab active" data-tab="dms"
                    style="flex:1;padding:8px;border:none;border-radius:6px;cursor:pointer;
                           font-size:.88rem;font-weight:600;background:#5865f2;color:#fff">
                Direct Messages
            </button>
            <button class="dm-main-tab" data-tab="groups"
                    style="flex:1;padding:8px;border:none;border-radius:6px;cursor:pointer;
                           font-size:.88rem;font-weight:600;background:transparent;color:#949ba4">
                Group Chats
            </button>
        </div>

        <!-- DMs tab -->
        <div id="dm-tab-dms">
            <div class="dm-conversations-list" id="dm-conversations-list">
                <p class="dm-conv-empty">Loading…</p>
            </div>
        </div>

        <!-- Groups tab -->
        <div id="dm-tab-groups" style="display:none">
            <button onclick="groupChats.openCreateGroup()"
                    style="width:100%;padding:9px;background:#5865f2;color:#fff;border:none;
                           border-radius:8px;font-size:.88rem;font-weight:600;cursor:pointer;margin-bottom:12px">
                + Create Group Chat
            </button>
            <div id="group-chats-list">
                <p class="dm-conv-empty">Loading…</p>
            </div>
        </div>
    </div>
</div>

<!-- Create Group Chat Modal -->
<div id="create-group-modal" class="modal">
    <div class="modal-content" style="max-width:440px">
        <span class="close">&times;</span>
        <h2>👥 Create Group Chat</h2>
        <div class="form-group">
            <label>Group Name</label>
            <input type="text" id="group-name-input" placeholder="e.g. Study Buddies"
                   style="width:100%;padding:9px 12px;background:#383a40;border:1px solid #4f545c;
                          border-radius:6px;color:#fff;font-size:.9rem">
        </div>
        <div class="form-group" style="margin-top:12px">
            <label>Add Members <span style="color:#949ba4;font-size:.78rem">(from your connections)</span></label>
            <div id="group-member-picker" style="max-height:260px;overflow-y:auto;margin-top:8px;
                 background:#2b2d31;border-radius:8px;padding:8px">
                <p style="color:#949ba4;text-align:center;padding:16px">Loading connections…</p>
            </div>
        </div>
        <button onclick="groupChats.createGroup()"
                style="width:100%;margin-top:14px;padding:10px;background:#5865f2;color:#fff;
                       border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer">
            Create Group
        </button>
    </div>
</div>

<!-- Group Chat Conversation Modal -->
<div id="group-chat-modal" class="modal">
    <div class="modal-content dm-modal-content" style="max-width:620px">
        <div class="dm-header">
            <button class="dm-back-btn" onclick="groupChats.backToList()">← Back</button>
            <div class="dm-recipient-info">
                <span style="font-size:1.2rem">👥</span>
                <span class="dm-recipient-name" id="group-chat-title">Group</span>
                <span id="group-member-count" style="color:#949ba4;font-size:.78rem"></span>
            </div>
            <button onclick="groupChats.openGroupInfo()"
                    style="background:none;border:none;color:#949ba4;cursor:pointer;font-size:.82rem;
                           padding:5px 8px;border-radius:5px" title="Group info">ℹ️</button>
            <button onclick="groupChats.leaveGroup()"
                    style="background:none;border:none;color:#ed4245;cursor:pointer;font-size:.78rem;
                           padding:5px 8px;border-radius:5px" title="Leave group">Leave</button>
        </div>
        <div class="dm-messages" id="group-messages"></div>
        <div class="dm-input-area">
            <div class="dm-input-row">
                <input type="text" id="group-message-input" placeholder="Message group…"
                       onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();groupChats.sendMessage();}">
                <button class="dm-send-btn" onclick="groupChats.sendMessage()">Send</button>
            </div>
        </div>
    </div>
</div>

<!-- Group Info Modal -->
<div id="group-info-modal" class="modal">
    <div class="modal-content" style="max-width:400px">
        <span class="close">&times;</span>
        <h2 id="group-info-title">Group Info</h2>
        <div id="group-info-members"></div>
        <div id="group-add-member-section" style="display:none;margin-top:16px">
            <div style="color:#949ba4;font-size:.78rem;font-weight:600;text-transform:uppercase;
                        letter-spacing:.5px;margin-bottom:8px">Add Member</div>
            <div id="group-add-picker"></div>
        </div>
    </div>
</div>


<script>
    // Make PHP user object available to JS
    // (must be defined before main.js so the class constructor can reference it)
    const currentUser = <?php echo json_encode($user); ?>;
</script>
<script src="assets/js/main.js"></script>
<script>
    // ── Channel edit / delete + DM wiring ─────────────────────────────────────
    // Runs after main.js so `app` and `dm` are guaranteed to exist.
    document.addEventListener('DOMContentLoaded', () => {
        const editBtn   = document.getElementById('edit-channel-btn');
        const deleteBtn = document.getElementById('delete-channel-btn');
        const editModal = document.getElementById('edit-channel-modal');
        const editForm  = document.getElementById('edit-channel-form');

        // Show edit modal pre-filled with current channel data
        if (editBtn) {
            editBtn.addEventListener('click', () => {
                const ch = app.channels.find(c => c.id == app.currentChannelId);
                if (!ch) return;
                document.getElementById('edit-channel-id').value   = ch.id;
                document.getElementById('edit-channel-name').value = ch.name;
                document.getElementById('edit-channel-desc').value = ch.description || '';
                document.getElementById('edit-channel-type').value = ch.type;
                editModal.style.display = 'block';
            });
        }

        // Save channel edits
        if (editForm) {
            editForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const channelId = document.getElementById('edit-channel-id').value;
                const payload   = {
                    channel_id:  parseInt(channelId),
                    name:        document.getElementById('edit-channel-name').value.trim(),
                    description: document.getElementById('edit-channel-desc').value.trim(),
                    type:        document.getElementById('edit-channel-type').value,
                };
                try {
                    const res  = await fetch('api/channels.php', {
                        method:  'PUT',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify(payload),
                    });
                    const data = await res.json();
                    if (data.success) {
                        app.showNotification('Channel updated!', 'success');
                        editModal.style.display = 'none';
                        await app.loadChannels();
                        // Update header if we're in the renamed channel
                        if (app.currentChannelId == channelId) {
                            document.getElementById('current-channel').textContent = payload.name;
                        }
                    } else {
                        app.showNotification('Error: ' + data.message, 'error');
                    }
                } catch (err) {
                    app.showNotification('Error updating channel', 'error');
                }
            });
        }

        // Delete current channel
        if (deleteBtn) {
            deleteBtn.addEventListener('click', async () => {
                const ch = app.channels.find(c => c.id == app.currentChannelId);
                if (!ch) return;
                if (!confirm(`Delete #${ch.name}? This will permanently remove all messages in it.`)) return;
                try {
                    const res  = await fetch('api/channels.php', {
                        method:  'DELETE',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ channel_id: ch.id }),
                    });
                    const data = await res.json();
                    if (data.success) {
                        app.showNotification('Channel deleted.', 'success');
                        app.currentChannelId = null;
                        document.getElementById('current-channel').textContent = 'Select a channel';
                        document.querySelector('.message-input-container').style.display = 'none';
                        document.getElementById('messages-container').innerHTML = `
                            <div class="welcome-message">
                                <h2>Welcome to HG Community! 👋</h2>
                                <p>Select a channel from the sidebar to start chatting.</p>
                            </div>`;
                        // Hide header buttons
                        editBtn.style.display         = 'none';
                        deleteBtn.style.display        = 'none';
                        document.getElementById('pinned-messages-btn').style.display = 'none';
                        await app.loadChannels();
                    } else {
                        app.showNotification('Error: ' + data.message, 'error');
                    }
                } catch (err) {
                    app.showNotification('Error deleting channel', 'error');
                }
            });
        }

        // ── DM button (sidebar) → open conversations list ─────────────────────
        const dmBtn = document.getElementById('dm-btn');
        if (dmBtn) {
            dmBtn.addEventListener('click', () => dm.openList());
        }

        // ── DM back button → return to conversations list ─────────────────────
        const dmBackBtn = document.getElementById('dm-back-btn');
        if (dmBackBtn) {
            dmBackBtn.addEventListener('click', () => dm.openList());
        }

        // ── DM form submit → send message ─────────────────────────────────────
        const dmForm = document.getElementById('dm-form');
        if (dmForm) {
            dmForm.addEventListener('submit', (e) => {
                e.preventDefault();
                dm.sendMessage();
            });
        }

        // ── DM file button ────────────────────────────────────────────────────
        const dmFileBtn = document.getElementById('dm-file-btn');
        if (dmFileBtn) {
            dmFileBtn.addEventListener('click', () => {
                document.getElementById('dm-file-input').click();
            });
        }
        const dmFileInput = document.getElementById('dm-file-input');
        if (dmFileInput) {
            dmFileInput.addEventListener('change', (e) => dm.handleFileSelect(e));
        }

        // ── DM input — send on Enter ──────────────────────────────────────────
        const dmTextInput = document.getElementById('dm-input');
        if (dmTextInput) {
            dmTextInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    dm.sendMessage();
                }
            });
        }

        // ── Monkey-patch selectChannel to show header buttons ─────────────────
        const _origSelect = app.selectChannel.bind(app);
        app.selectChannel = function(channelId, channelName) {
            _origSelect(channelId, channelName);
            document.getElementById('pinned-messages-btn').style.display = 'inline-block';
            <?php if ($user['role'] === 'admin'): ?>
            if (editBtn)   editBtn.style.display   = 'inline-block';
            if (deleteBtn) deleteBtn.style.display = 'inline-block';
            <?php endif; ?>
        };
    });
</script>
</body>
</html>