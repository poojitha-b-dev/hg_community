<?php
require_once 'includes/auth.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = $auth->getCurrentUser();
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
        <div class="server-header">
            <h2>HG Community</h2>
            <div class="user-info">
                <div class="avatar">
                    <img src="<?php
                        $av = $user['avatar'] ?? '';
                        // Avatars uploaded via PATCH are stored as uploads/avatars/filename
                        // Default avatar lives in assets/images/
                        echo htmlspecialchars($av ?: 'assets/images/default-avatar.png');
                    ?>"
                         alt="Avatar"
                         onerror="this.src='assets/images/default-avatar.png'">
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
                <span class="admin-toggle-icon">▲</span>
            </button>
            <div class="admin-controls" id="admin-controls" style="display:none">
                <button id="create-channel-btn"  class="control-btn">＋ New Channel</button>
                <button id="manage-channels-btn" class="control-btn">📋 Manage Channels</button>
                <button id="create-invite-btn"   class="control-btn">🔗 Create Invite</button>
                <button id="manage-invites-btn"  class="control-btn">📨 Manage Invites</button>
                <button id="manage-users-btn"    class="control-btn">👥 Manage Users</button>
            </div>
        </div>
        <?php endif; ?>

        <div class="user-controls">
            <button id="dm-btn" class="control-btn">
                💬 Messages
                <span id="dm-sidebar-badge" class="dm-sidebar-badge" style="display:none"></span>
            </button>
            <button id="settings-btn" class="control-btn">⚙️ Settings</button>
            <button id="logout-btn"   class="control-btn">🚪 Logout</button>
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
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Create Invite Link</h2>
        <form id="create-invite-form">
            <div class="form-group">
                <label>Email (Optional)</label>
                <input type="email" id="invite-email" name="email">
            </div>
            <div class="form-group">
                <label>Phone (Optional)</label>
                <input type="tel" id="invite-phone" name="phone">
            </div>
            <div class="form-group">
                <label>Role</label>
                <select id="invite-role" name="role">
                    <option value="member">Member</option>
                    <option value="moderator">Moderator</option>
                </select>
            </div>
            <div class="form-group">
                <label>Expires in (hours)</label>
                <input type="number" id="expiry-hours" name="expiry_hours" value="24" min="1" max="168">
            </div>
            <button type="submit">Create Invite</button>
        </form>
        <div id="invite-result" style="display:none">
            <h3>Invite Created!</h3>
            <div class="invite-info">
                <label>Invite Link:</label>
                <input type="text" id="invite-url" readonly>
                <button onclick="copyInviteLink()">Copy</button>
            </div>
            <div class="invite-info">
                <label>Invite Code:</label>
                <input type="text" id="invite-code" readonly>
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

<!-- Manage Users (Admin) -->
<div id="manage-users-modal" class="modal">
    <div class="modal-content" style="max-width:700px">
        <span class="close">&times;</span>
        <h2>Manage Users</h2>
        <div style="margin-bottom:12px">
            <input type="text" id="user-search" placeholder="Search users…"
                   style="width:100%;padding:8px 12px;background:#383a40;border:1px solid #4f545c;
                          border-radius:6px;color:#fff;font-size:.9rem">
        </div>
        <div id="users-table-container">
            <table id="users-table" style="width:100%;border-collapse:collapse;font-size:.875rem">
                <thead>
                    <tr style="border-bottom:1px solid #3f4147;color:#949ba4;text-align:left">
                        <th style="padding:8px 10px">Username</th>
                        <th style="padding:8px 10px">Email</th>
                        <th style="padding:8px 10px">Role</th>
                        <th style="padding:8px 10px">Status</th>
                        <th style="padding:8px 10px">Actions</th>
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
    <div class="modal-content" style="max-width:460px">
        <span class="close">&times;</span>
        <h2>💬 Direct Messages</h2>
        <div class="dm-conversations-list" id="dm-conversations-list">
            <p class="dm-conv-empty">Loading…</p>
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