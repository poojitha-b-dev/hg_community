/**
 * HG Community — main.js
 * Features added in this version:
 *   • Message editing (own messages only, shows "edited" label)
 *   • Soft-delete with "[Message deleted]" placeholder
 *   • Typing indicators via SSE (api/typing.php)
 *   • Online/offline presence via SSE (api/presence.php)
 *   • Unread message badge counts per channel
 *   • Message search (keyword + username, across all channels)
 *   • Pinned messages panel per channel
 *   • All existing features preserved
 */

class CommunityApp {
    constructor() {
        this.currentChannelId   = null;
        this.channels           = [];
        this.messages           = [];
        this.onlineUsers        = [];
        this.messageUpdateInterval = null;
        this.unreadInterval        = null;
        this.typingTimeout         = null;  // debounce for stop-typing signal
        this.presenceInterval      = null;  // polling interval for presence
        this.typingInterval        = null;  // polling interval for typing

        this.init();
    }

    init() {
        this.loadChannels();
        this.setupEventListeners();
        this.startAutoRefresh();
        this.startPresencePolling();
        this.startUnreadPolling();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Event Listeners
    // ══════════════════════════════════════════════════════════════════════════

    setupEventListeners() {
        // Message form submit
        document.getElementById('message-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.sendMessage();
        });

        // File buttons
        document.getElementById('file-btn').addEventListener('click', () => {
            document.getElementById('file-input').click();
        });
        document.getElementById('file-input').addEventListener('change', (e) => {
            this.handleFileSelect(e);
        });
        const uploadFileBtn = document.getElementById('upload-file-btn');
        if (uploadFileBtn) {
            uploadFileBtn.addEventListener('click', () => {
                document.getElementById('file-input').click();
            });
        }

        // Logout
        document.getElementById('logout-btn').addEventListener('click', () => {
            if (confirm('Are you sure you want to logout?')) {
                // Tell presence we're going offline
                navigator.sendBeacon('api/presence.php', JSON.stringify({ offline: true }));
                window.location.href = 'api/auth.php?action=logout';
            }
        });

        // Typing indicator — fire heartbeat while user types
        const msgInput = document.getElementById('message-input');
        msgInput.addEventListener('input', () => this.handleTypingInput());
        msgInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                this.stopTyping();
                document.getElementById('message-form').dispatchEvent(new Event('submit'));
            }
        });

        // Search bar
        const searchInput = document.getElementById('message-search-input');
        if (searchInput) {
            let searchDebounce;
            searchInput.addEventListener('input', (e) => {
                clearTimeout(searchDebounce);
                searchDebounce = setTimeout(() => this.searchMessages(e.target.value.trim()), 400);
            });
        }

        this.setupModalControls();
    }

    setupModalControls() {
        // ── Admin panel collapse toggle ────────────────────────────────────────
        const adminToggle   = document.getElementById('admin-panel-toggle');
        const adminControls = document.getElementById('admin-controls');
        if (adminToggle) {
            adminToggle.addEventListener('click', () => {
                const open = adminControls.style.display !== 'none';
                adminControls.style.display = open ? 'none' : 'block';
                adminToggle.querySelector('.admin-toggle-icon').textContent = open ? '▲' : '▼';
            });
        }

        // Create channel
        const createChannelBtn  = document.getElementById('create-channel-btn');
        const createChannelModal = document.getElementById('create-channel-modal');
        const createChannelForm = document.getElementById('create-channel-form');

        if (createChannelBtn) {
            createChannelBtn.addEventListener('click', () => {
                createChannelModal.style.display = 'block';
            });
            createChannelForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.createChannel();
            });
        }

        // Channel type → show/hide team name
        document.getElementById('channel-type').addEventListener('change', (e) => {
            const g = document.getElementById('team-name-group');
            if (e.target.value === 'team') {
                g.style.display = 'block';
                document.getElementById('team-name').required = true;
            } else {
                g.style.display = 'none';
                document.getElementById('team-name').required = false;
            }
        });

        // Manage Channels
        const manageChannelsBtn = document.getElementById('manage-channels-btn');
        if (manageChannelsBtn) {
            manageChannelsBtn.addEventListener('click', () => {
                document.getElementById('manage-channels-modal').style.display = 'block';
                this.loadAllChannels();
            });
        }
        const channelSearch = document.getElementById('channel-search');
        if (channelSearch) {
            channelSearch.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('#channels-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }

        // Manage Invites
        const manageInvitesBtn = document.getElementById('manage-invites-btn');
        if (manageInvitesBtn) {
            manageInvitesBtn.addEventListener('click', () => {
                document.getElementById('manage-invites-modal').style.display = 'block';
                this.loadAllInvites();
            });
        }

        // Create invite
        const createInviteBtn  = document.getElementById('create-invite-btn');
        const createInviteModal = document.getElementById('create-invite-modal');
        const createInviteForm = document.getElementById('create-invite-form');

        if (createInviteBtn) {
            createInviteBtn.addEventListener('click', () => {
                createInviteModal.style.display = 'block';
                document.getElementById('invite-result').style.display = 'none';
                createInviteForm.style.display = 'block';
                createInviteForm.reset();
            });
            createInviteForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.createInvite();
            });
        }

        // Settings
        const settingsBtn  = document.getElementById('settings-btn');
        const settingsForm = document.getElementById('settings-form');
        if (settingsBtn) {
            settingsBtn.addEventListener('click', () => {
                document.getElementById('settings-modal').style.display = 'block';
            });
            settingsForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.saveSettings();
            });
            document.getElementById('avatar-upload').addEventListener('change', (e) => {
                if (e.target.files[0]) this.uploadAvatar(e.target.files[0]);
            });
        }

        // Manage Users
        const manageUsersBtn = document.getElementById('manage-users-btn');
        if (manageUsersBtn) {
            manageUsersBtn.addEventListener('click', () => {
                document.getElementById('manage-users-modal').style.display = 'block';
                this.loadAllUsers();
            });
        }

        // User search filter
        const userSearch = document.getElementById('user-search');
        if (userSearch) {
            userSearch.addEventListener('input', (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('#users-table-body tr').forEach(row => {
                    row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
                });
            });
        }

        // Pinned messages modal
        const pinnedBtn = document.getElementById('pinned-messages-btn');
        if (pinnedBtn) {
            pinnedBtn.addEventListener('click', () => {
                this.openPinnedMessages();
            });
        }

        // Close modals
        document.querySelectorAll('.close').forEach(btn => {
            btn.addEventListener('click', (e) => e.target.closest('.modal').style.display = 'none');
        });
        window.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) e.target.style.display = 'none';
        });
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Channels
    // ══════════════════════════════════════════════════════════════════════════

    async loadChannels() {
        try {
            const response = await fetch('api/channels.php');
            const data     = await response.json();
            if (data.success) {
                this.channels = data.channels;
                this.renderChannels();
            }
        } catch (err) {
            console.error('Error loading channels:', err);
        }
    }

    renderChannels() {
        const containers = {
            announcement: document.getElementById('announcement-channels'),
            team:         document.getElementById('team-channels'),
            technical:    document.getElementById('technical-channels'),
            general:      document.getElementById('general-channels'),
        };
        Object.values(containers).forEach(c => { if (c) c.innerHTML = ''; });

        this.channels.forEach(channel => {
            const el = this.createChannelElement(channel);
            if (containers[channel.type]) containers[channel.type].appendChild(el);
        });
    }

    createChannelElement(channel) {
        const el = document.createElement('div');
        el.className = 'channel-item';
        el.dataset.channelId = channel.id;

        const icons = { announcement: '📢', team: '👥', technical: '💻', general: '💬' };
        const icon  = icons[channel.type] || '💬';

        el.innerHTML = `
            <span class="channel-icon">${icon}</span>
            <span class="channel-name">${this.escapeHtml(channel.name)}</span>
            <span class="unread-badge" id="unread-${channel.id}" style="display:none"></span>
        `;

        el.addEventListener('click', () => this.selectChannel(channel.id, channel.name));
        return el;
    }

    selectChannel(channelId, channelName) {
        document.querySelectorAll('.channel-item').forEach(el => el.classList.remove('active'));
        const el = document.querySelector(`[data-channel-id="${channelId}"]`);
        if (el) el.classList.add('active');

        document.getElementById('current-channel').textContent = channelName;
        document.getElementById('current-channel-id').value   = channelId;
        document.querySelector('.message-input-container').style.display = 'block';

        // Clear local unread badge immediately
        this.clearUnreadBadge(channelId);

        this.currentChannelId = channelId;
        this.messages         = [];

        // Switch typing SSE to new channel
        this.startTypingSSE(channelId);

        this.loadMessages();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Messages — load, render, send
    // ══════════════════════════════════════════════════════════════════════════

    async loadMessages(isAutoRefresh = false) {
        if (!this.currentChannelId) return;

        try {
            const response = await fetch(`api/messages.php?channel_id=${this.currentChannelId}`);
            const data     = await response.json();

            if (data.success) {
                const lastKnown = this.messages.length ? this.messages[this.messages.length - 1].id : null;
                const lastNew   = data.messages.length ? data.messages[data.messages.length - 1].id : null;

                if (!isAutoRefresh || lastKnown !== lastNew) {
                    this.messages = data.messages;
                    this.renderMessages(isAutoRefresh);
                }
            }
        } catch (err) {
            console.error('Error loading messages:', err);
        }
    }

    renderMessages(isAutoRefresh = false) {
        const container  = document.getElementById('messages-container');
        const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 60;

        if (this.messages.length === 0) {
            container.innerHTML = `
                <div class="welcome-message">
                    <h2>Welcome to this channel! 👋</h2>
                    <p>Start the conversation by sending a message.</p>
                </div>`;
            return;
        }

        container.innerHTML = '';
        this.messages.forEach(msg => container.appendChild(this.createMessageElement(msg)));

        if (!isAutoRefresh || wasAtBottom) {
            container.scrollTop = container.scrollHeight;
        }
    }

    createMessageElement(message) {
        const el        = document.createElement('div');
        el.className    = 'message';
        el.dataset.messageId = message.id;

        // Railway DB returns UTC timestamps without 'Z' — append it so JS
        // treats them as UTC and converts to the user's local timezone correctly.
        const createdUtc  = message.created_at ? message.created_at.replace(' ', 'T') + 'Z' : '';
        const editedUtc   = message.edited_at  ? message.edited_at.replace(' ', 'T')  + 'Z' : '';
        const timestamp   = createdUtc ? new Date(createdUtc).toLocaleString() : '';
        const avatarSrc  = message.avatar || 'assets/images/default-avatar.png';
        const isDeleted  = !!message.is_deleted;
        const editedLabel = editedUtc
            ? `<span class="edited-label" title="Edited on ${new Date(editedUtc).toLocaleString()}">(edited)</span>`
            : '';
        const pinnedLabel = message.is_pinned
            ? `<span class="pinned-label">📌 Pinned</span>`
            : '';

        // File attachment rendering
        let fileContent = '';
        if (message.file_path && !isDeleted) {
            const fileType = message.file_type?.split('/')[0];
            const fileName = message.file_path.split('/').pop();
            switch (fileType) {
                case 'image':
                    fileContent = `<div class="message-file"><img src="${message.file_path}" alt="Image" onclick="window.open('${message.file_path}','_blank')"></div>`;
                    break;
                case 'video':
                    fileContent = `<div class="message-file"><video controls><source src="${message.file_path}" type="${message.file_type}"></video></div>`;
                    break;
                case 'audio':
                    fileContent = `<div class="message-file"><audio controls><source src="${message.file_path}" type="${message.file_type}"></audio></div>`;
                    break;
                default:
                    fileContent = `<div class="message-file"><div class="file-info"><span class="file-icon">📄</span><a href="${message.file_path}" target="_blank" style="color:#5865f2">${this.escapeHtml(fileName)}</a></div></div>`;
            }
        }

        // Action buttons
        const canDelete = !isDeleted && (currentUser.id == message.user_id || currentUser.role === 'admin' || currentUser.role === 'moderator');
        const canEdit   = !isDeleted && currentUser.id == message.user_id;
        const canPin    = !isDeleted && (currentUser.role === 'admin' || currentUser.role === 'moderator');
        const pinAction = message.is_pinned ? 'unpin' : 'pin';
        const pinIcon   = message.is_pinned ? '📌' : '📍';

        const editBtn   = canEdit   ? `<button class="msg-action-btn msg-edit-btn"   onclick="app.startEditMessage(${message.id})" title="Edit">✏️</button>` : '';
        const deleteBtn = canDelete ? `<button class="msg-action-btn msg-delete-btn" onclick="app.deleteMessage(${message.id})"   title="Delete">✕</button>` : '';
        const pinBtn    = canPin    ? `<button class="msg-action-btn msg-pin-btn"    onclick="app.togglePin(${message.id},'${pinAction}')" title="${pinAction}">${pinIcon}</button>` : '';

        const actionsHtml = (editBtn || deleteBtn || pinBtn)
            ? `<span class="msg-actions">${editBtn}${pinBtn}${deleteBtn}</span>`
            : '';

        const textHtml = isDeleted
            ? `<div class="message-text deleted-message">[Message deleted]</div>`
            : (message.content
                ? `<div class="message-text" id="msg-text-${message.id}">${this.formatMessage(message.content)}</div>`
                : '');

        el.innerHTML = `
            <img src="${avatarSrc}" alt="Avatar" class="message-avatar"
                 onerror="this.src='assets/images/default-avatar.png'">
            <div class="message-content">
                <div class="message-header">
                    <span class="message-author">${this.escapeHtml(message.username)}</span>
                    <span class="role-badge role-${message.role}">${message.role}</span>
                    <span class="message-timestamp">${timestamp}</span>
                    ${editedLabel}
                    ${pinnedLabel}
                    ${actionsHtml}
                </div>
                ${textHtml}
                <div class="edit-area" id="edit-area-${message.id}" style="display:none">
                    <textarea class="edit-textarea" id="edit-input-${message.id}">${this.escapeHtml(message.content || '')}</textarea>
                    <div class="edit-buttons">
                        <button class="btn-save-edit"   onclick="app.saveEdit(${message.id})">Save</button>
                        <button class="btn-cancel-edit" onclick="app.cancelEdit(${message.id})">Cancel</button>
                    </div>
                </div>
                ${fileContent}
            </div>
        `;

        // Show action buttons on hover
        el.addEventListener('mouseenter', () => {
            const btns = el.querySelector('.msg-actions');
            if (btns) btns.style.opacity = '1';
        });
        el.addEventListener('mouseleave', () => {
            const btns = el.querySelector('.msg-actions');
            if (btns) btns.style.opacity = '0';
        });

        return el;
    }

    // ── Edit message ──────────────────────────────────────────────────────────

    startEditMessage(messageId) {
        const textDiv = document.getElementById(`msg-text-${messageId}`);
        const editArea = document.getElementById(`edit-area-${messageId}`);
        if (textDiv)  textDiv.style.display  = 'none';
        if (editArea) editArea.style.display = 'block';

        const input = document.getElementById(`edit-input-${messageId}`);
        if (input) {
            input.focus();
            input.selectionStart = input.selectionEnd = input.value.length;
        }
    }

    cancelEdit(messageId) {
        const textDiv  = document.getElementById(`msg-text-${messageId}`);
        const editArea = document.getElementById(`edit-area-${messageId}`);
        if (textDiv)  textDiv.style.display  = '';
        if (editArea) editArea.style.display = 'none';
    }

    async saveEdit(messageId) {
        const input   = document.getElementById(`edit-input-${messageId}`);
        const content = input?.value.trim();
        if (!content) return;

        try {
            const response = await fetch('api/messages.php', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ message_id: messageId, content }),
            });
            const data = await response.json();

            if (data.success) {
                // Update local message array
                const idx = this.messages.findIndex(m => m.id == messageId);
                if (idx > -1) {
                    this.messages[idx] = data.message;
                    this.renderMessages(true);
                }
            } else {
                this.showNotification('Edit failed: ' + data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error saving edit', 'error');
        }
    }

    // ── Delete message ────────────────────────────────────────────────────────

    async deleteMessage(messageId) {
        if (!confirm('Delete this message? This cannot be undone.')) return;

        try {
            const response = await fetch('api/messages.php', {
                method:  'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ message_id: messageId }),
            });
            const data = await response.json();

            if (data.success) {
                // Mark as deleted locally instead of removing to preserve thread
                const idx = this.messages.findIndex(m => m.id == messageId);
                if (idx > -1) {
                    this.messages[idx].is_deleted = 1;
                    this.messages[idx].content    = '[Message deleted]';
                    this.renderMessages(true);
                }
            } else {
                this.showNotification('Failed to delete: ' + data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error deleting message', 'error');
        }
    }

    // ── Pin / Unpin ───────────────────────────────────────────────────────────

    async togglePin(messageId, action) {
        try {
            const response = await fetch('api/messages.php', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ message_id: messageId, action }),
            });
            const data = await response.json();

            if (data.success) {
                const idx = this.messages.findIndex(m => m.id == messageId);
                if (idx > -1) {
                    this.messages[idx].is_pinned = data.pinned ? 1 : 0;
                    this.renderMessages(true);
                }
                this.showNotification(action === 'pin' ? 'Message pinned!' : 'Message unpinned.', 'success');
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error toggling pin', 'error');
        }
    }

    // ── Pinned messages panel ─────────────────────────────────────────────────

    async openPinnedMessages() {
        if (!this.currentChannelId) {
            this.showNotification('Select a channel first', 'error');
            return;
        }

        const modal = document.getElementById('pinned-messages-modal');
        const list  = document.getElementById('pinned-messages-list');
        if (!modal || !list) return;

        modal.style.display = 'block';
        list.innerHTML      = '<p style="color:#949ba4">Loading…</p>';

        try {
            const response = await fetch(`api/messages.php?pinned=1&channel_id=${this.currentChannelId}`);
            const data     = await response.json();

            if (data.success && data.messages.length > 0) {
                list.innerHTML = '';
                data.messages.forEach(msg => {
                    const item = document.createElement('div');
                    item.className = 'pinned-message-item';
                    item.innerHTML = `
                        <div class="pinned-meta">
                            <strong>${this.escapeHtml(msg.username)}</strong>
                            <span>${new Date(msg.created_at).toLocaleString()}</span>
                        </div>
                        <div class="pinned-text">${this.formatMessage(msg.content)}</div>
                    `;
                    list.appendChild(item);
                });
            } else {
                list.innerHTML = '<p style="color:#949ba4">No pinned messages in this channel.</p>';
            }
        } catch (err) {
            list.innerHTML = '<p style="color:#ed4245">Error loading pinned messages.</p>';
        }
    }

    // ── Send message ──────────────────────────────────────────────────────────

    async sendMessage() {
        const form     = document.getElementById('message-form');
        const formData = new FormData(form);

        if (!formData.get('content') && !formData.get('file')?.name) return;

        try {
            const response = await fetch('api/messages.php', { method: 'POST', body: formData });
            const data     = await response.json();

            if (data.success) {
                this.messages.push(data.message);
                this.renderMessages();
                document.getElementById('message-input').value = '';
                document.getElementById('file-input').value    = '';
                document.getElementById('file-preview').style.display = 'none';
                this.stopTyping();
            } else {
                this.showNotification('Failed to send: ' + data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error sending message', 'error');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Typing Indicators (SSE)
    // ══════════════════════════════════════════════════════════════════════════

    handleTypingInput() {
        if (!this.currentChannelId) return;

        // Send heartbeat
        fetch('api/typing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ channel_id: this.currentChannelId, is_typing: true }),
        }).catch(() => {});

        // Debounce stop-typing after 3 s of no keystrokes
        clearTimeout(this.typingTimeout);
        this.typingTimeout = setTimeout(() => this.stopTyping(), 3000);
    }

    stopTyping() {
        clearTimeout(this.typingTimeout);
        if (!this.currentChannelId) return;
        fetch('api/typing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ channel_id: this.currentChannelId, is_typing: false }),
        }).catch(() => {});
    }

    startTypingSSE(channelId) {
        // Clear any previous typing poll
        if (this.typingInterval) {
            clearInterval(this.typingInterval);
            this.typingInterval = null;
        }

        const indicator = document.getElementById('typing-indicator');
        if (!indicator) return;

        // Poll typing state every 2 s — no persistent connection, no spinner
        const poll = async () => {
            try {
                const res  = await fetch(`api/typing.php?channel_id=${channelId}`);
                const data = await res.json();
                if (data && data.label !== undefined) {
                    indicator.textContent = data.label || '';
                }
            } catch (_) {}
        };

        poll(); // immediate first call
        this.typingInterval = setInterval(poll, 2000);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Presence (Polling — replaces SSE to prevent browser spinner)
    // ══════════════════════════════════════════════════════════════════════════

    startPresencePolling() {
        if (this.presenceInterval) return; // guard against double-start
        this.loadOnlineUsers();            // immediate first load
        // Poll every 10 s — frequent enough to feel live, no persistent connection
        this.presenceInterval = setInterval(() => this.loadOnlineUsers(), 10000);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Online Users
    // ══════════════════════════════════════════════════════════════════════════

    async loadOnlineUsers() {
        // Keep as fallback if SSE fails; called once on init
        try {
            const response = await fetch('api/users.php?online=1');
            const data     = await response.json();
            if (data.success) {
                this.onlineUsers = data.users;
                this.renderOnlineUsers();
            }
        } catch (err) {
            console.error('Error loading online users:', err);
        }
    }

    renderOnlineUsers() {
        const container = document.getElementById('members-list');
        const count     = document.getElementById('online-count');
        if (!container) return;

        if (count) count.textContent = `${this.onlineUsers.length} online`;

        // Build a map of currently-rendered user ids
        const rendered = new Map();
        container.querySelectorAll('.member-item[data-user-id]').forEach(el => {
            rendered.set(String(el.dataset.userId), el);
        });

        const incoming = new Map();
        this.onlineUsers.forEach(user => incoming.set(String(user.id), user));

        // Remove users who went offline
        rendered.forEach((el, id) => {
            if (!incoming.has(id)) el.remove();
        });

        // Add or update users
        this.onlineUsers.forEach(user => {
            const id = String(user.id);
            if (rendered.has(id)) {
                // Only update avatar src if it actually changed, to avoid flicker
                const img = rendered.get(id).querySelector('img');
                const newSrc = user.avatar || 'assets/images/default-avatar.png';
                if (img && img.src !== newSrc && !img.src.endsWith(newSrc)) {
                    img.src = newSrc;
                }
            } else {
                container.appendChild(this.createMemberElement(user));
            }
        });
    }

    createMemberElement(user) {
        const el = document.createElement('div');
        el.className    = 'member-item';
        el.dataset.userId = user.id;

        const avatarSrc = user.avatar || 'assets/images/default-avatar.png';
        // Don't show DM button for self
        const dmBtnHtml = (user.id != currentUser.id)
            ? `<button class="member-dm-btn" title="Send DM">DM</button>`
            : '';

        el.innerHTML = `
            <div class="member-avatar">
                <img src="${avatarSrc}" alt="Avatar" onerror="this.src='assets/images/default-avatar.png'">
                <div class="status-indicator online"></div>
            </div>
            <div class="member-info">
                <div class="member-name">${this.escapeHtml(user.username)}</div>
                <div class="member-status">${user.role}</div>
            </div>
            ${dmBtnHtml}
        `;

        const dmBtn = el.querySelector('.member-dm-btn');
        if (dmBtn) {
            dmBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dm.openConversation(user.id, user.username, user.avatar);
            });
        }

        return el;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Unread Badges
    // ══════════════════════════════════════════════════════════════════════════

    startUnreadPolling() {
        // Guard: never register a second interval
        if (this.unreadInterval) return;
        this.fetchUnreadCounts();
        // 30 s is plenty for unread badge accuracy; 10 s was adding unnecessary
        // network churn that contributed to the permanent browser tab spinner.
        this.unreadInterval = setInterval(() => this.fetchUnreadCounts(), 30000);
    }

    async fetchUnreadCounts() {
        try {
            const response = await fetch('api/messages.php?unread=1');
            const data     = await response.json();
            if (data.success) {
                Object.entries(data.unread).forEach(([channelId, count]) => {
                    this.updateUnreadBadge(channelId, count);
                });
            }
        } catch (_) {}
    }

    updateUnreadBadge(channelId, count) {
        const badge = document.getElementById(`unread-${channelId}`);
        if (!badge) return;
        if (count > 0 && channelId != this.currentChannelId) {
            badge.textContent    = count > 99 ? '99+' : count;
            badge.style.display  = 'inline-block';
        } else {
            badge.style.display  = 'none';
        }
    }

    clearUnreadBadge(channelId) {
        const badge = document.getElementById(`unread-${channelId}`);
        if (badge) badge.style.display = 'none';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Search
    // ══════════════════════════════════════════════════════════════════════════

    async searchMessages(keyword) {
        const results = document.getElementById('search-results');
        if (!results) return;

        if (!keyword) {
            results.style.display = 'none';
            results.innerHTML     = '';
            return;
        }

        results.style.display = 'block';
        results.innerHTML     = '<p style="color:#949ba4;padding:10px">Searching…</p>';

        // Track the keyword at the time this call was made; if the user types
        // again before we return, discard our stale result.
        const searchedFor = keyword;

        try {
            const url      = `api/messages.php?search=${encodeURIComponent(keyword)}` +
                             (this.currentChannelId ? `&channel_id=${this.currentChannelId}` : '');
            const response = await fetch(url);
            const data     = await response.json();

            // Bail out if a newer search has already taken over the results box
            const currentVal = document.getElementById('message-search-input')?.value.trim();
            if (currentVal !== searchedFor) return;

            if (data.success && data.messages.length > 0) {
                results.innerHTML = '';
                data.messages.forEach(msg => {
                    const item = document.createElement('div');
                    item.className = 'search-result-item';
                    item.innerHTML = `
                        <div class="search-result-meta">
                            <strong>${this.escapeHtml(msg.username)}</strong>
                            <span>#${this.escapeHtml(msg.channel_name)}</span>
                            <span>${new Date(msg.created_at).toLocaleString()}</span>
                        </div>
                        <div class="search-result-text">${this.formatMessage(msg.content)}</div>
                    `;
                    // Click jumps to that channel
                    item.addEventListener('click', () => {
                        const ch = this.channels.find(c => c.id == msg.channel_id);
                        if (ch) this.selectChannel(ch.id, ch.name);
                        results.style.display = 'none';
                    });
                    results.appendChild(item);
                });
            } else {
                results.innerHTML = '<p style="color:#949ba4;padding:10px">No results found.</p>';
            }
        } catch (err) {
            // Always clear the spinner — never leave the "Searching…" state hung
            results.innerHTML = '<p style="color:#ed4245;padding:10px">Search error.</p>';
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Auto-Refresh
    // ══════════════════════════════════════════════════════════════════════════

    startAutoRefresh() {
        // 15 s is sufficient — typing/presence SSE already provide live feel.
        // Polling every 5 s was keeping the browser's network-activity spinner
        // spinning continuously, making the tab appear to never finish loading.
        this.messageUpdateInterval = setInterval(() => {
            if (this.currentChannelId) this.loadMessages(true);
        }, 15000);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // File Handling
    // ══════════════════════════════════════════════════════════════════════════

    handleFileSelect(e) {
        const file = e.target.files[0];
        if (!file) return;

        if (file.size > 10 * 1024 * 1024) {
            this.showNotification('File size must be less than 10MB', 'error');
            e.target.value = '';
            return;
        }

        const fileType = file.type.split('/')[0];
        if (fileType === 'image') {
            const reader = new FileReader();
            reader.onload = (ev) => this.showFilePreview(`<img src="${ev.target.result}" alt="Preview">`, file.name);
            reader.readAsDataURL(file);
        } else {
            this.showFilePreview(`<div class="file-info"><span class="file-icon">📄</span></div>`, file.name);
        }
    }

    showFilePreview(content, fileName) {
        const preview = document.getElementById('file-preview');
        preview.innerHTML = `
            ${content}
            <div class="preview-info">
                <div><strong>${this.escapeHtml(fileName)}</strong></div>
                <div>Ready to upload</div>
            </div>
            <button type="button" class="remove-file" onclick="app.removeFile()">Remove</button>
        `;
        preview.style.display = 'flex';
    }

    removeFile() {
        document.getElementById('file-input').value  = '';
        document.getElementById('file-preview').style.display = 'none';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Settings & Avatar
    // ══════════════════════════════════════════════════════════════════════════

    async saveSettings() {
        const data = {
            username:         document.getElementById('settings-username').value.trim(),
            phone:            document.getElementById('settings-phone').value.trim(),
            current_password: document.getElementById('settings-current-password').value,
            new_password:     document.getElementById('settings-new-password').value,
        };
        // Also send bio if present
        const bioEl = document.getElementById('settings-bio');
        if (bioEl) data.bio = bioEl.value;

        Object.keys(data).forEach(k => { if (data[k] === '' || data[k] === undefined) delete data[k]; });

        try {
            const response = await fetch('api/users.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ action: 'update_profile', ...data }),
            });
            const result = await response.json();
            if (result.success) {
                this.showNotification('Settings saved!', 'success');
                document.getElementById('settings-modal').style.display = 'none';
                document.getElementById('settings-form').reset();
            } else {
                this.showNotification(result.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error saving settings', 'error');
        }
    }

    async uploadAvatar(file) {
        if (file.size > 2 * 1024 * 1024) {
            this.showNotification('Avatar must be under 2MB', 'error');
            return;
        }
        const formData = new FormData();
        formData.append('avatar', file);
        try {
            const response = await fetch('api/users.php', { method: 'PATCH', body: formData });
            const result   = await response.json();
            if (result.success) {
                const preview       = document.getElementById('settings-avatar-preview');
                const sidebarAvatar = document.querySelector('.avatar img');
                if (preview)       preview.src       = result.avatar;
                if (sidebarAvatar) sidebarAvatar.src = result.avatar;
                this.showNotification('Avatar updated!', 'success');
            } else {
                this.showNotification(result.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error uploading avatar', 'error');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // User Management (Admin)
    // ══════════════════════════════════════════════════════════════════════════

    async loadAllUsers() {
        try {
            const response = await fetch('api/users.php');
            const data     = await response.json();
            if (data.success) this.renderUsersTable(data.users);
        } catch (err) {
            console.error('Error loading users:', err);
        }
    }

    async loadAllChannels() {
        try {
            const res  = await fetch('api/channels.php');
            const data = await res.json();
            if (data.success) this.renderChannelsTable(data.channels);
        } catch (err) { console.error('Error loading channels:', err); }
    }

    renderChannelsTable(channels) {
        const tbody = document.getElementById('channels-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!channels.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#949ba4">No channels found.</td></tr>';
            return;
        }
        const typeLabels = { announcement:'📢 Announcement', general:'💬 General', team:'👥 Team', technical:'💻 Technical' };
        channels.forEach(ch => {
            const row = document.createElement('tr');
            row.style.borderBottom = '1px solid #3f4147';
            row.innerHTML = `
                <td style="padding:10px;color:#fff;font-weight:500">#${this.escapeHtml(ch.name)}</td>
                <td style="padding:10px;color:#949ba4">${typeLabels[ch.type] || ch.type}</td>
                <td style="padding:10px;color:#949ba4;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                    title="${this.escapeHtml(ch.description||'')}">${this.escapeHtml(ch.description||'—')}</td>
                <td style="padding:10px;color:#949ba4;font-size:.8rem">${ch.created_at ? new Date(ch.created_at.replace(' ','T')+'Z').toLocaleDateString() : '—'}</td>
                <td style="padding:10px">
                    <div style="display:flex;gap:6px">
                        <button onclick="app.openEditChannelFromTable(${ch.id})"
                                style="border:none;padding:4px 10px;border-radius:4px;font-size:.78rem;cursor:pointer;background:#5865f2;color:#fff;font-weight:600">Edit</button>
                        <button onclick="app.deleteChannelFromTable(${ch.id},'${this.escapeHtml(ch.name)}')"
                                style="border:none;padding:4px 10px;border-radius:4px;font-size:.78rem;cursor:pointer;background:#ed4245;color:#fff;font-weight:600">Delete</button>
                    </div>
                </td>`;
            tbody.appendChild(row);
        });
    }

    openEditChannelFromTable(channelId) {
        const ch = this.channels.find(c => c.id == channelId);
        if (!ch) { this.showNotification('Channel not found — refresh the page', 'error'); return; }
        document.getElementById('manage-channels-modal').style.display = 'none';
        document.getElementById('edit-channel-id').value   = ch.id;
        document.getElementById('edit-channel-name').value = ch.name;
        document.getElementById('edit-channel-desc').value = ch.description || '';
        document.getElementById('edit-channel-type').value = ch.type;
        document.getElementById('edit-channel-modal').style.display = 'block';
    }

    async deleteChannelFromTable(channelId, channelName) {
        if (!confirm(`Delete #${channelName}? All messages will be permanently removed.`)) return;
        try {
            const res  = await fetch('api/channels.php', {
                method: 'DELETE', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ channel_id: channelId }),
            });
            const data = await res.json();
            if (data.success) {
                this.showNotification('Channel deleted.', 'success');
                await this.loadChannels();
                this.loadAllChannels();
                if (this.currentChannelId == channelId) {
                    this.currentChannelId = null;
                    document.getElementById('current-channel').textContent = 'Select a channel';
                    document.querySelector('.message-input-container').style.display = 'none';
                    document.getElementById('messages-container').innerHTML =
                        '<div class="welcome-message"><h2>Welcome to HG Community! 👋</h2><p>Select a channel from the sidebar to start chatting.</p></div>';
                }
            } else {
                this.showNotification('Error: ' + data.message, 'error');
            }
        } catch (err) { this.showNotification('Error deleting channel', 'error'); }
    }

    async loadAllInvites() {
        try {
            const res  = await fetch('api/invites.php');
            const data = await res.json();
            if (data.success) this.renderInvitesTable(data.invites);
        } catch (err) { console.error('Error loading invites:', err); }
    }

    renderInvitesTable(invites) {
        const tbody = document.getElementById('invites-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!invites.length) {
            tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:20px;color:#949ba4">No invites found.</td></tr>';
            return;
        }
        invites.forEach(inv => {
            const now       = new Date();
            const expiresAt = inv.expires_at ? new Date(inv.expires_at.replace(' ','T')+'Z') : null;
            const expired   = expiresAt && expiresAt < now;
            const used      = !!inv.used_at;
            const status    = used ? '<span style="color:#23a559">Used</span>'
                            : expired ? '<span style="color:#ed4245">Expired</span>'
                            : '<span style="color:#f0b232">Active</span>';
            const typeLabel = inv.invite_type === 'group' ? '👥 Group' : '👤 Single';
            const row = document.createElement('tr');
            row.style.borderBottom = '1px solid #3f4147';
            row.innerHTML = `
                <td style="padding:10px;font-family:monospace;font-size:.78rem;color:#949ba4">${inv.invite_code.slice(0,12)}…</td>
                <td style="padding:10px;color:#dcddde">${typeLabel}</td>
                <td style="padding:10px"><span class="role-badge role-${inv.role}">${inv.role}</span></td>
                <td style="padding:10px;color:#949ba4">${this.escapeHtml(inv.created_by_name||'—')}</td>
                <td style="padding:10px;color:#949ba4;font-size:.8rem">${expiresAt ? expiresAt.toLocaleString() : '—'}</td>
                <td style="padding:10px">${status}</td>
                <td style="padding:10px">
                    ${(!used && !expired) ? `<button onclick="app.revokeInvite('${inv.invite_code}')"
                        style="border:none;padding:4px 10px;border-radius:4px;font-size:.78rem;cursor:pointer;background:#ed4245;color:#fff;font-weight:600">Revoke</button>` : '—'}
                </td>`;
            tbody.appendChild(row);
        });
    }

    async revokeInvite(code) {
        if (!confirm('Revoke this invite? It will no longer be usable.')) return;
        try {
            const res  = await fetch('api/invites.php', {
                method: 'DELETE', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ invite_code: code }),
            });
            const data = await res.json();
            if (data.success) {
                this.showNotification('Invite revoked.', 'success');
                this.loadAllInvites();
            } else {
                this.showNotification('Error: ' + data.message, 'error');
            }
        } catch (err) { this.showNotification('Error revoking invite', 'error'); }
    }

    renderUsersTable(users) {
        const tbody = document.getElementById('users-table-body');
        if (!tbody) return;
        tbody.innerHTML = '';

        const statusColor = { active: '#23a559', muted: '#f0b232', banned: '#ed4245', restricted: '#949ba4' };

        users.forEach(user => {
            const isSelf  = user.id == currentUser.id;
            const actions = isSelf ? '<span style="color:#949ba4;font-size:.8rem">You</span>' : `
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                    ${user.status === 'muted'
                        ? `<button onclick="app.moderateUser(${user.id},'unmute')" class="mod-btn" style="background:#23a559">Unmute</button>`
                        : `<button onclick="app.moderateUser(${user.id},'mute')"   class="mod-btn" style="background:#f0b232;color:#000">Mute</button>`}
                    ${user.status === 'banned'
                        ? `<button onclick="app.moderateUser(${user.id},'unban')"  class="mod-btn" style="background:#23a559">Unban</button>`
                        : `<button onclick="app.moderateUser(${user.id},'ban')"    class="mod-btn" style="background:#ed4245">Ban</button>`}
                    ${user.status === 'restricted'
                        ? `<button onclick="app.moderateUser(${user.id},'unrestrict')" class="mod-btn" style="background:#5865f2">Unrestrict</button>`
                        : `<button onclick="app.moderateUser(${user.id},'restrict')"   class="mod-btn" style="background:#949ba4">Restrict</button>`}
                </div>`;

            const row = document.createElement('tr');
            row.style.borderBottom = '1px solid #3f4147';
            row.innerHTML = `
                <td style="padding:10px;color:#fff;font-weight:500">${this.escapeHtml(user.username)}</td>
                <td style="padding:10px;color:#949ba4">${this.escapeHtml(user.email || '-')}</td>
                <td style="padding:10px"><span class="role-badge role-${user.role}">${user.role}</span></td>
                <td style="padding:10px"><span style="color:${statusColor[user.status]||'#949ba4'};font-weight:500;text-transform:capitalize">${user.status}</span></td>
                <td style="padding:10px">${actions}</td>
            `;
            tbody.appendChild(row);
        });

        if (!document.getElementById('mod-btn-style')) {
            const style = document.createElement('style');
            style.id = 'mod-btn-style';
            style.textContent = `.mod-btn{border:none;padding:4px 10px;border-radius:4px;font-size:.78rem;cursor:pointer;color:#fff;font-weight:600;transition:opacity .15s}.mod-btn:hover{opacity:.85}`;
            document.head.appendChild(style);
        }
    }

    async moderateUser(userId, action) {
        if (!confirm(`Are you sure you want to ${action} this user?`)) return;
        try {
            const response = await fetch('api/users.php', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ user_id: userId, action }),
            });
            const data = await response.json();
            if (data.success) {
                this.showNotification(data.message, 'success');
                this.loadAllUsers();
            } else {
                this.showNotification('Failed: ' + data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error performing action', 'error');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Channel Creation
    // ══════════════════════════════════════════════════════════════════════════

    async createChannel() {
        const form        = document.getElementById('create-channel-form');
        const formData    = new FormData(form);
        const channelData = {
            name:        formData.get('name'),
            description: formData.get('description'),
            type:        formData.get('type'),
            team_name:   formData.get('team_name'),
        };

        if (!channelData.name || !channelData.type) {
            this.showNotification('Channel name and type are required', 'error');
            return;
        }

        try {
            const response = await fetch('api/channels.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body:    JSON.stringify(channelData),
            });

            if (!response.ok) throw new Error(`Server error (${response.status})`);

            const data = await response.json();
            if (data.success) {
                this.showNotification('Channel created!', 'success');
                document.getElementById('create-channel-modal').style.display = 'none';
                form.reset();
                document.getElementById('team-name-group').style.display = 'none';
                this.loadChannels();
            } else {
                this.showNotification('Failed: ' + data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error: ' + err.message, 'error');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Invite
    // ══════════════════════════════════════════════════════════════════════════

    async createInvite() {
        const form      = document.getElementById('create-invite-form');
        const formData  = new FormData(form);
        const inviteData = {
            email:        formData.get('email'),
            phone:        formData.get('phone'),
            role:         formData.get('role'),
            expiry_hours: formData.get('expiry_hours'),
        };

        try {
            const response = await fetch('api/invites.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(inviteData),
            });
            const data = await response.json();
            if (data.success) {
                document.getElementById('invite-url').value        = data.invite_url;
                document.getElementById('invite-code').value       = data.invite_code;
                document.getElementById('invite-result').style.display = 'block';
                form.style.display = 'none';
            } else {
                this.showNotification('Failed: ' + data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error creating invite', 'error');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Utilities
    // ══════════════════════════════════════════════════════════════════════════

    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(text)));
        return div.innerHTML;
    }

    formatMessage(content) {
        // Escape first, then apply safe markdown-like formatting
        return this.escapeHtml(content)
            .replace(/\n/g, '<br>')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.*?)\*/g, '<em>$1</em>')
            .replace(/`(.*?)`/g, '<code>$1</code>');
    }

    showNotification(message, type = 'info') {
        const n = document.createElement('div');
        n.className   = `notification notification-${type}`;
        n.textContent = message;
        document.body.appendChild(n);
        setTimeout(() => { if (n.parentNode) n.parentNode.removeChild(n); }, 3000);
    }
}

// ── Global helpers ─────────────────────────────────────────────────────────────

function copyInviteLink() {
    const input = document.getElementById('invite-url');
    input.select();
    document.execCommand('copy');
    alert('Invite link copied!');
}

// ── Bootstrap ──────────────────────────────────────────────────────────────────
const app = new CommunityApp();
window.app            = app;
window.copyInviteLink = copyInviteLink;


// ══════════════════════════════════════════════════════════════════════════════
// DMManager — all Direct Messaging UI logic
// Depends on: global `currentUser`, DOM elements defined in index.php
// ══════════════════════════════════════════════════════════════════════════════

class DMManager {
    constructor() {
        this.activeRecipientId   = null;
        this.activeRecipientName = null;
        this.pollInterval        = null;
        this.pendingFile         = null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    escapeHtml(text) {
        if (text === null || text === undefined) return '';
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(String(text)));
        return div.innerHTML;
    }

    formatTime(dateStr) {
        // DB returns UTC without 'Z' — append it for correct local conversion
        const utc = dateStr ? dateStr.replace(' ', 'T') + 'Z' : '';
        return utc ? new Date(utc).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
    }

    showModal(id)  { const m = document.getElementById(id); if (m) m.style.display = 'block'; }
    hideModal(id)  { const m = document.getElementById(id); if (m) m.style.display = 'none';  }

    // ── Open conversations list ────────────────────────────────────────────────

    async openList() {
        this.stopPolling();
        this.activeRecipientId = null;
        this.showModal('dm-list-modal');
        await this.loadConversations();
    }

    async loadConversations() {
        const list = document.getElementById('dm-conversations-list');
        if (!list) return;
        list.innerHTML = '<p class="dm-conv-empty">Loading…</p>';

        try {
            const res  = await fetch('api/dm.php?conversations=1');
            const data = await res.json();

            if (!data.success) {
                list.innerHTML = '<p class="dm-conv-empty">Could not load conversations.</p>';
                return;
            }

            const convs = data.conversations || [];
            if (convs.length === 0) {
                list.innerHTML = '<p class="dm-conv-empty">No conversations yet. Click DM on a member to start one!</p>';
                return;
            }

            list.innerHTML = '';
            convs.forEach(c => {
                const item = document.createElement('div');
                item.className = 'dm-conv-item';
                const avatar = c.avatar || 'assets/images/default-avatar.png';
                const unread = c.unread_count > 0
                    ? `<span class="dm-conv-unread">${c.unread_count}</span>`
                    : '';
                item.innerHTML = `
                    <div class="dm-conv-avatar">
                        <img src="${this.escapeHtml(avatar)}"
                             onerror="this.src='assets/images/default-avatar.png'" alt="Avatar">
                    </div>
                    <div class="dm-conv-info">
                        <div class="dm-conv-name">
                            ${this.escapeHtml(c.username)}
                            <span class="role-badge role-${this.escapeHtml(c.role)}">${this.escapeHtml(c.role)}</span>
                        </div>
                        <div class="dm-conv-last">${this.escapeHtml(c.last_message || 'No messages yet')}</div>
                    </div>
                    ${unread}
                `;
                item.addEventListener('click', () => {
                    this.hideModal('dm-list-modal');
                    this.openConversation(c.user_id, c.username, c.avatar);
                });
                list.appendChild(item);
            });
        } catch (err) {
            list.innerHTML = '<p class="dm-conv-empty">Error loading conversations.</p>';
            console.error('DM loadConversations error:', err);
        }
    }

    // ── Open individual conversation ──────────────────────────────────────────

    openConversation(userId, username, avatar) {
        this.activeRecipientId   = userId;
        this.activeRecipientName = username;

        // Set header
        const nameEl   = document.getElementById('dm-recipient-name');
        const avatarEl = document.getElementById('dm-recipient-avatar');
        const hiddenId = document.getElementById('dm-recipient-id');
        if (nameEl)   nameEl.textContent = username;
        if (avatarEl) avatarEl.src = avatar || 'assets/images/default-avatar.png';
        if (hiddenId) hiddenId.value = userId;

        // Clear input
        const input = document.getElementById('dm-input');
        if (input) input.value = '';
        this.clearFilePreview();

        this.showModal('dm-modal');
        this.loadMessages();
        this.startPolling();
    }

    // ── Load messages for active conversation ─────────────────────────────────

    async loadMessages() {
        if (!this.activeRecipientId) return;
        const container = document.getElementById('dm-messages-container');
        if (!container) return;

        try {
            const res  = await fetch(`api/dm.php?conversation=${this.activeRecipientId}`);
            const data = await res.json();

            if (!data.success) return;

            const msgs   = data.messages || [];
            const wasAtBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 80;

            container.innerHTML = '';

            if (msgs.length === 0) {
                container.innerHTML = `<p class="dm-empty">No messages yet. Say hello! 👋</p>`;
                return;
            }

            msgs.forEach(msg => container.appendChild(this.createMessageEl(msg)));

            // Auto-scroll if was at bottom or freshly opened
            if (wasAtBottom) container.scrollTop = container.scrollHeight;

        } catch (err) {
            console.error('DM loadMessages error:', err);
        }
    }

    createMessageEl(msg) {
        const isSent    = msg.sender_id == currentUser.id;
        const isDeleted = !!msg.is_deleted;
        const el        = document.createElement('div');

        el.className        = `dm-msg ${isSent ? 'dm-sent' : 'dm-received'}`;
        el.dataset.dmId     = msg.id;
        if (isDeleted) el.classList.add('dm-deleted');

        const avatar = msg.sender_avatar || 'assets/images/default-avatar.png';

        // File attachment
        let fileHtml = '';
        if (msg.file_path && !isDeleted) {
            const fType = (msg.file_type || '').split('/')[0];
            const fName = msg.file_path.split('/').pop();
            if (fType === 'image') {
                fileHtml = `<div class="dm-msg-file"><img src="${this.escapeHtml(msg.file_path)}"
                                alt="Image" onclick="window.open('${this.escapeHtml(msg.file_path)}','_blank')"></div>`;
            } else if (fType === 'video') {
                fileHtml = `<div class="dm-msg-file"><video controls><source src="${this.escapeHtml(msg.file_path)}"
                                type="${this.escapeHtml(msg.file_type)}"></video></div>`;
            } else if (fType === 'audio') {
                fileHtml = `<div class="dm-msg-file"><audio controls><source src="${this.escapeHtml(msg.file_path)}"
                                type="${this.escapeHtml(msg.file_type)}"></audio></div>`;
            } else {
                fileHtml = `<div class="dm-msg-file"><a href="${this.escapeHtml(msg.file_path)}"
                                target="_blank">${this.escapeHtml(fName)}</a></div>`;
            }
        }

        const bubbleText = isDeleted
            ? '[Message deleted]'
            : this.escapeHtml(msg.content || '');

        // Only sender (or admin/mod) can delete; server enforces this too
        const canDelete = isSent || currentUser.role === 'admin' || currentUser.role === 'moderator';
        const deleteBtn = (canDelete && !isDeleted)
            ? `<button class="dm-msg-delete-btn" title="Delete">✕</button>`
            : '';

        el.innerHTML = `
            <img src="${this.escapeHtml(avatar)}"
                 onerror="this.src='assets/images/default-avatar.png'"
                 class="dm-msg-avatar" alt="Avatar">
            <div class="dm-msg-meta">
                <div class="dm-msg-bubble">
                    ${bubbleText}
                    ${fileHtml}
                    ${deleteBtn}
                </div>
                <span class="dm-msg-time">${this.formatTime(msg.created_at)}</span>
            </div>
        `;

        const btn = el.querySelector('.dm-msg-delete-btn');
        if (btn) {
            btn.addEventListener('click', () => this.deleteMessage(msg.id, el));
        }

        return el;
    }

    // ── Send message ──────────────────────────────────────────────────────────

    async sendMessage() {
        const input       = document.getElementById('dm-input');
        const recipientId = this.activeRecipientId;
        const content     = input?.value.trim() || '';

        if (!recipientId) {
            app.showNotification('No conversation open', 'error');
            return;
        }

        if (!content && !this.pendingFile) {
            return; // nothing to send
        }

        try {
            let res;

            if (this.pendingFile) {
                // Multipart for file upload
                const fd = new FormData();
                fd.append('recipient_id', recipientId);
                fd.append('content',      content);
                fd.append('file',         this.pendingFile);
                res = await fetch('api/dm.php', { method: 'POST', body: fd });
            } else {
                res = await fetch('api/dm.php', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ recipient_id: recipientId, content }),
                });
            }

            const data = await res.json();

            if (data.success) {
                if (input) input.value = '';
                this.clearFilePreview();
                // Append new message immediately
                const container = document.getElementById('dm-messages-container');
                if (container) {
                    container.appendChild(this.createMessageEl(data.message));
                    container.scrollTop = container.scrollHeight;
                }
            } else {
                app.showNotification('Failed to send: ' + data.message, 'error');
            }
        } catch (err) {
            app.showNotification('Error sending DM', 'error');
        }
    }

    // ── Delete message ────────────────────────────────────────────────────────

    async deleteMessage(dmId, el) {
        if (!confirm('Delete this message?')) return;

        try {
            const res  = await fetch('api/dm.php', {
                method:  'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ dm_id: dmId }),
            });
            const data = await res.json();

            if (data.success) {
                // Update bubble in place — no full re-render needed
                el.classList.add('dm-deleted');
                const bubble = el.querySelector('.dm-msg-bubble');
                if (bubble) {
                    bubble.textContent = '[Message deleted]';
                }
            } else {
                app.showNotification('Could not delete: ' + data.message, 'error');
            }
        } catch (err) {
            app.showNotification('Error deleting DM', 'error');
        }
    }

    // ── File handling ─────────────────────────────────────────────────────────

    handleFileSelect(e) {
        const file = e.target.files[0];
        if (!file) return;

        if (file.size > 10 * 1024 * 1024) {
            app.showNotification('File must be under 10MB', 'error');
            e.target.value = '';
            return;
        }

        this.pendingFile = file;
        const preview = document.getElementById('dm-file-preview');
        if (!preview) return;

        const fType = file.type.split('/')[0];
        let thumb   = '';
        if (fType === 'image') {
            const reader = new FileReader();
            reader.onload = (ev) => {
                preview.innerHTML = `<img src="${ev.target.result}"
                    style="height:36px;border-radius:4px;object-fit:cover"> &nbsp;
                    <span>${this.escapeHtml(file.name)}</span>
                    <button class="remove-dm-file">Remove</button>`;
                preview.style.display = 'flex';
                preview.querySelector('.remove-dm-file')
                       .addEventListener('click', () => this.clearFilePreview());
            };
            reader.readAsDataURL(file);
            return;
        }

        preview.innerHTML = `<span>📄 ${this.escapeHtml(file.name)}</span>
            <button class="remove-dm-file">Remove</button>`;
        preview.style.display = 'flex';
        preview.querySelector('.remove-dm-file')
               .addEventListener('click', () => this.clearFilePreview());
    }

    clearFilePreview() {
        this.pendingFile = null;
        const preview = document.getElementById('dm-file-preview');
        if (preview) { preview.innerHTML = ''; preview.style.display = 'none'; }
        const input   = document.getElementById('dm-file-input');
        if (input) input.value = '';
    }

    // ── Unread badge on sidebar DM button ─────────────────────────────────────

    async refreshBadge() {
        try {
            const res  = await fetch('api/dm.php?unread=1');
            const data = await res.json();
            const badge = document.getElementById('dm-sidebar-badge');
            if (!badge) return;
            const count = data.unread || 0;
            if (count > 0) {
                badge.textContent    = count > 99 ? '99+' : count;
                badge.style.display  = 'inline-block';
            } else {
                badge.style.display  = 'none';
            }
        } catch (_) {}
    }

    // ── Polling for active conversation ───────────────────────────────────────

    startPolling() {
        this.stopPolling();
        // Poll conversation messages every 4 s while DM window is open
        this.pollInterval = setInterval(() => {
            const modal = document.getElementById('dm-modal');
            if (modal && modal.style.display !== 'none' && this.activeRecipientId) {
                this.loadMessages();
            } else {
                this.stopPolling();
            }
        }, 4000);

        // Badge polling every 15 s
        if (!this._badgeInterval) {
            this._badgeInterval = setInterval(() => this.refreshBadge(), 15000);
            this.refreshBadge(); // immediate
        }
    }

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }
}

// ── Bootstrap DM ──────────────────────────────────────────────────────────────
const dm = new DMManager();
window.dm = dm;

