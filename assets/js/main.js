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
                // Reset to single by default
                document.getElementById('invite-email-group').style.display = 'block';
                document.getElementById('invite-email').required = true;
            });
            createInviteForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.createInvite();
            });
            // Toggle email field based on invite type
            createInviteForm.querySelectorAll('input[name="invite_type"]').forEach(radio => {
                radio.addEventListener('change', (e) => {
                    const emailGroup = document.getElementById('invite-email-group');
                    const emailInput = document.getElementById('invite-email');
                    if (e.target.value === 'group') {
                        emailGroup.style.display = 'none';
                        emailInput.required = false;
                        emailInput.value = '';
                    } else {
                        emailGroup.style.display = 'block';
                        emailInput.required = true;
                    }
                });
            });
        }

        // Connections
        const connectionsBtn = document.getElementById('connections-btn');
        if (connectionsBtn) connectionsBtn.addEventListener('click', () => connections.open());

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
        document.getElementById('current-channel-id').value    = channelId;

        const channel     = this.channels.find(c => c.id == channelId);
        const isAnnounce  = channel?.type === 'announcement';
        const isTeam      = channel?.type === 'team';
        const canPost     = currentUser.role === 'admin' || currentUser.role === 'moderator';
        const inputArea   = document.querySelector('.message-input-container');
        const announceBar = document.getElementById('announce-readonly-bar');

        if (isAnnounce && !canPost) {
            inputArea.style.display  = 'none';
            if (announceBar) announceBar.style.display = 'flex';
        } else {
            inputArea.style.display  = 'block';
            if (announceBar) announceBar.style.display = 'none';
        }

        // Show/hide manage team button
        const manageTeamBtn = document.getElementById('manage-team-btn');
        if (manageTeamBtn) {
            manageTeamBtn.style.display = (isTeam && canPost) ? 'inline-flex' : 'none';
            manageTeamBtn.onclick = () => this.openTeamManager(channelId, channelName);
        }

        this.clearUnreadBadge(channelId);
        this.currentChannelId = channelId;
        this.messages         = [];
        this.startTypingSSE(channelId);
        this.loadMessages();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Messages — load, render, send
    // ══════════════════════════════════════════════════════════════════════════

    async openTeamManager(channelId, channelName) {
        const modal = document.getElementById('team-manager-modal');
        document.getElementById('team-manager-title').textContent = `👥 ${channelName} — Members`;
        modal.style.display = 'block';
        modal.dataset.channelId = channelId;
        this.loadTeamMembers(channelId);
    }

    async loadTeamMembers(channelId) {
        const list = document.getElementById('team-members-list');
        list.innerHTML = '<p style="color:#949ba4;text-align:center;padding:20px">Loading…</p>';

        try {
            // Load current members and all users in parallel
            const [membRes, usersRes] = await Promise.all([
                fetch('api/channels.php', {
                    method: 'PATCH', headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ action: 'list_members', channel_id: channelId, user_id: 0 }),
                }),
                fetch('api/users.php'),
            ]);
            const membData  = await membRes.json();
            const usersData = await usersRes.json();

            const members    = membData.members || [];
            const memberIds  = new Set(members.map(m => m.id));
            const allUsers   = (usersData.users || []).filter(u => u.role !== 'admin');
            const nonMembers = allUsers.filter(u => !memberIds.has(u.id));

            list.innerHTML = '';

            // Current members section
            if (members.length) {
                list.innerHTML += `<div style="color:#949ba4;font-size:.78rem;font-weight:600;
                    text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Current Members</div>`;
                members.forEach(m => {
                    list.innerHTML += `
                        <div style="display:flex;align-items:center;gap:10px;padding:8px;
                                    background:#2b2d31;border-radius:8px;margin-bottom:6px">
                            <img src="${m.avatar}" onerror="this.src='assets/images/default-avatar.png'"
                                 style="width:34px;height:34px;border-radius:50%;object-fit:cover">
                            <div style="flex:1">
                                <div style="color:#fff;font-weight:600;font-size:.88rem">${this.escapeHtml(m.username)}</div>
                                <span class="role-badge role-${m.role}">${m.role}</span>
                            </div>
                            <button onclick="app.removeTeamMember(${channelId},${m.id})"
                                    style="background:#ed4245;color:#fff;border:none;border-radius:6px;
                                           padding:4px 10px;font-size:.78rem;cursor:pointer;font-weight:600">
                                Remove
                            </button>
                        </div>`;
                });
            }

            // Add members section
            if (nonMembers.length) {
                list.innerHTML += `<div style="color:#949ba4;font-size:.78rem;font-weight:600;
                    text-transform:uppercase;letter-spacing:.5px;margin:16px 0 8px">Add Members</div>`;
                nonMembers.forEach(u => {
                    list.innerHTML += `
                        <div style="display:flex;align-items:center;gap:10px;padding:8px;
                                    background:#2b2d31;border-radius:8px;margin-bottom:6px">
                            <img src="${u.avatar||'assets/images/default-avatar.png'}"
                                 onerror="this.src='assets/images/default-avatar.png'"
                                 style="width:34px;height:34px;border-radius:50%;object-fit:cover">
                            <div style="flex:1">
                                <div style="color:#fff;font-weight:600;font-size:.88rem">${this.escapeHtml(u.username)}</div>
                                <span class="role-badge role-${u.role}">${u.role}</span>
                            </div>
                            <button onclick="app.addTeamMember(${channelId},${u.id})"
                                    style="background:#23a559;color:#fff;border:none;border-radius:6px;
                                           padding:4px 10px;font-size:.78rem;cursor:pointer;font-weight:600">
                                + Add
                            </button>
                        </div>`;
                });
            }

            if (!members.length && !nonMembers.length) {
                list.innerHTML = '<p style="color:#949ba4;text-align:center;padding:20px">No users found.</p>';
            }
        } catch (e) {
            list.innerHTML = '<p style="color:#ed4245;text-align:center;padding:20px">Error loading members.</p>';
        }
    }

    async addTeamMember(channelId, userId) {
        try {
            const res  = await fetch('api/channels.php', {
                method: 'PATCH', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'add_member', channel_id: channelId, user_id: userId }),
            });
            const data = await res.json();
            if (data.success) {
                this.showNotification('Member added!', 'success');
                this.loadTeamMembers(channelId);
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (e) { this.showNotification('Error adding member', 'error'); }
    }

    async removeTeamMember(channelId, userId) {
        if (!confirm('Remove this member from the team?')) return;
        try {
            const res  = await fetch('api/channels.php', {
                method: 'PATCH', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'remove_member', channel_id: channelId, user_id: userId }),
            });
            const data = await res.json();
            if (data.success) {
                this.showNotification('Member removed.', 'success');
                this.loadTeamMembers(channelId);
                await this.loadChannels(); // refresh sidebar in case they lost access
            } else {
                this.showNotification(data.message, 'error');
            }
        } catch (e) { this.showNotification('Error removing member', 'error'); }
    }

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

        // Click member name/avatar → open profile
        el.addEventListener('click', (e) => {
            if (!e.target.classList.contains('member-dm-btn')) {
                this.openProfile(user);
            }
        });

        const dmBtn = el.querySelector('.member-dm-btn');
        if (dmBtn) {
            dmBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                dm.openConversation(user.id, user.username, user.avatar);
            });
        }

        return el;
    }

    async openProfile(user) {
        const modal    = document.getElementById('profile-modal');
        const avatarEl = document.getElementById('profile-modal-avatar');
        const nameEl   = document.getElementById('profile-modal-username');
        const roleEl   = document.getElementById('profile-modal-role');
        const bioEl    = document.getElementById('profile-modal-bio');
        const joinedEl = document.getElementById('profile-modal-joined');
        const dmBtn    = document.getElementById('profile-modal-dm-btn');
        const connDiv  = document.getElementById('profile-modal-connection');

        nameEl.textContent  = user.username;
        roleEl.innerHTML    = `<span class="role-badge role-${user.role}">${user.role}</span>`;
        bioEl.textContent   = 'Loading…';
        avatarEl.src        = 'assets/images/default-avatar.png';
        if (connDiv) connDiv.innerHTML = '';
        if (dmBtn)   dmBtn.style.display = 'none';
        modal.style.display = 'block';

        try {
            const [profileRes, statusRes] = await Promise.all([
                fetch(`api/users.php?profile=${user.id}`),
                user.id != currentUser.id ? fetch(`api/connections.php?status=${user.id}`) : Promise.resolve(null),
            ]);

            const profileData = await profileRes.json();
            const u = profileData.success ? profileData.user : user;

            // Avatar visibility
            const vis = u.avatar_visibility || 'everyone';
            const showAvatar = vis === 'everyone' || vis === 'connections';
            avatarEl.src = (showAvatar && u.avatar && u.avatar !== 'assets/images/default-avatar.png')
                ? u.avatar + '?t=' + Date.now()
                : 'assets/images/default-avatar.png';

            bioEl.textContent    = u.bio || 'No bio yet.';
            joinedEl.textContent = u.created_at
                ? 'Joined ' + new Date(u.created_at.replace(' ','T')+'Z').toLocaleDateString(undefined, {year:'numeric', month:'long'})
                : '';

            // Connection status (not shown for own profile)
            if (user.id != currentUser.id && connDiv && statusRes) {
                const connData = await statusRes.json();
                const status   = connData.status || 'none';
                let connHtml   = '';

                if (status === 'accepted') {
                    connHtml = `
                        <span style="color:#23a559;font-size:.85rem;font-weight:600">✓ Connected</span>
                        <button onclick="connections.remove(${user.id})" 
                            style="margin-left:8px;background:transparent;border:1px solid #4f545c;
                                   color:#949ba4;border-radius:6px;padding:4px 10px;
                                   font-size:.78rem;cursor:pointer">Remove</button>`;
                    if (dmBtn) {
                        dmBtn.style.display = 'block';
                        dmBtn.onclick = () => {
                            modal.style.display = 'none';
                            dm.openConversation(user.id, user.username, u.avatar);
                        };
                    }
                } else if (status === 'pending_sent') {
                    connHtml = `<span style="color:#f0b232;font-size:.85rem">⏳ Request sent</span>`;
                } else if (status === 'pending_received') {
                    connHtml = `<span style="color:#f0b232;font-size:.85rem">📩 Wants to connect</span>
                        <button onclick="connections.acceptFromProfile(${user.id})"
                            style="margin-left:8px;background:#23a559;color:#fff;border:none;
                                   border-radius:6px;padding:4px 12px;font-size:.78rem;cursor:pointer;font-weight:600">Accept</button>`;
                } else {
                    connHtml = `<button onclick="connections.request(${user.id})"
                        style="background:#5865f2;color:#fff;border:none;border-radius:6px;
                               padding:6px 16px;font-size:.85rem;font-weight:600;cursor:pointer">
                        + Connect</button>`;
                }
                connDiv.innerHTML = connHtml;
            }
        } catch (_) {
            bioEl.textContent = user.bio || 'No bio yet.';
        }
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
                let totalUnread = 0;
                Object.entries(data.unread).forEach(([channelId, count]) => {
                    this.updateUnreadBadge(channelId, count);
                    totalUnread += count;
                });
                // Update browser tab title
                document.title = totalUnread > 0
                    ? `(${totalUnread}) HG Community`
                    : 'HG Community';
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
        const usernameVal  = document.getElementById('settings-username').value.trim();
        const phoneVal     = document.getElementById('settings-phone').value.trim();
        const bioEl        = document.getElementById('settings-bio');
        const visibilityEl = document.getElementById('settings-avatar-visibility');
        const currentPw    = document.getElementById('settings-current-password').value;
        const newPw        = document.getElementById('settings-new-password').value;

        // Validate username format if user typed one
        if (usernameVal && !/^[a-z0-9._]{3,30}$/.test(usernameVal)) {
            this.showNotification('Username must be 3–30 chars, only a–z 0–9 . _', 'error');
            document.getElementById('settings-username').focus();
            return;
        }

        // Validate passwords if user is trying to change password
        if (newPw && !currentPw) {
            this.showNotification('Current password is required to set a new one.', 'error');
            return;
        }
        if (newPw && newPw.length < 8) {
            this.showNotification('New password must be at least 8 characters.', 'error');
            return;
        }

        const data = { avatar_visibility: visibilityEl?.value || 'everyone' };
        if (usernameVal)  data.username         = usernameVal;
        if (phoneVal)     data.phone            = phoneVal;
        if (bioEl)        data.bio              = bioEl.value; // allow empty to clear bio
        if (currentPw)    data.current_password = currentPw;
        if (newPw)        data.new_password     = newPw;

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
        const statusDesc  = {
            active:     'Full access',
            muted:      'Read only — cannot send messages',
            restricted: 'Public channels only — no DMs or posting',
            banned:     'No access — completely blocked',
        };

        users.forEach(user => {
            const isSelf  = user.id == currentUser.id;
            const isAdmin = user.role === 'admin';

            let actions;
            if (isSelf) {
                actions = '<span style="color:#949ba4;font-size:.8rem">You</span>';
            } else if (isAdmin) {
                actions = '<span style="color:#949ba4;font-size:.8rem">Admin — cannot be moderated</span>';
            } else {
                actions = `<div style="display:flex;gap:5px;flex-wrap:wrap;">
                    ${user.status === 'muted'
                        ? `<button onclick="app.moderateUser(${user.id},'unmute')"  class="mod-btn" style="background:#23a559" title="Restore ability to send messages">Unmute</button>`
                        : `<button onclick="app.moderateUser(${user.id},'mute')"    class="mod-btn" style="background:#f0b232;color:#1a1a1a" title="Prevent user from sending any messages">Mute</button>`}
                    ${user.status === 'restricted'
                        ? `<button onclick="app.moderateUser(${user.id},'unrestrict')" class="mod-btn" style="background:#5865f2" title="Restore full access">Unrestrict</button>`
                        : `<button onclick="app.moderateUser(${user.id},'restrict')"   class="mod-btn" style="background:#4f545c" title="Limit to public channels only — no DMs or posting">Restrict</button>`}
                    ${user.status === 'banned'
                        ? `<button onclick="app.moderateUser(${user.id},'unban')"   class="mod-btn" style="background:#23a559" title="Restore full access">Unban</button>`
                        : `<button onclick="app.moderateUser(${user.id},'ban')"     class="mod-btn" style="background:#ed4245" title="Completely block from the platform">Ban</button>`}
                </div>`;
            }

            const statusLabel = user.status || 'active';
            const row = document.createElement('tr');
            row.style.borderBottom = '1px solid #3f4147';
            row.innerHTML = `
                <td style="padding:10px;color:#fff;font-weight:500">${this.escapeHtml(user.username)}</td>
                <td style="padding:10px;color:#949ba4;font-size:.82rem">${this.escapeHtml(user.email || '—')}</td>
                <td style="padding:10px"><span class="role-badge role-${user.role}">${user.role}</span></td>
                <td style="padding:10px">
                    <span style="color:${statusColor[statusLabel]||'#949ba4'};font-weight:500;text-transform:capitalize"
                          title="${statusDesc[statusLabel]||''}">${statusLabel}</span>
                </td>
                <td style="padding:10px">${actions}</td>`;
            tbody.appendChild(row);
        });

        if (!document.getElementById('mod-btn-style')) {
            const style = document.createElement('style');
            style.id = 'mod-btn-style';
            style.textContent = `.mod-btn{border:none;padding:5px 11px;border-radius:4px;font-size:.78rem;cursor:pointer;color:#fff;font-weight:600;transition:opacity .15s}.mod-btn:hover{opacity:.82}
            .status-legend-item{font-size:.78rem;color:#949ba4;display:flex;align-items:center;gap:4px;cursor:help}`;
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
        const inviteType = document.querySelector('input[name="invite_type"]:checked')?.value || 'single';
        const email      = document.getElementById('invite-email').value.trim();
        const role       = document.getElementById('invite-role').value;
        const hours      = document.getElementById('expiry-hours').value;

        // Single invites require an email
        if (inviteType === 'single' && !email) {
            this.showNotification('Email is required for single-person invites.', 'error');
            document.getElementById('invite-email').focus();
            return;
        }

        const payload = {
            invite_type:  inviteType,
            email:        inviteType === 'single' ? email : null,
            role,
            expiry_hours: parseInt(hours),
        };

        try {
            const res  = await fetch('api/invites.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('invite-url').value = data.invite_url;
                // Show hard expiry time in user's local timezone
                const expiresLocal = new Date(data.expires_at.replace(' ', 'T') + 'Z').toLocaleString();
                document.getElementById('invite-expires-display').textContent = expiresLocal;
                document.getElementById('invite-result').style.display = 'block';
                document.getElementById('create-invite-form').style.display = 'none';
            } else {
                this.showNotification('Failed: ' + data.message, 'error');
            }
        } catch (err) {
            this.showNotification('Error creating invite', 'error');
        }
    }

    resetInviteForm() {
        document.getElementById('create-invite-form').reset();
        document.getElementById('create-invite-form').style.display = 'block';
        document.getElementById('invite-result').style.display      = 'none';
        // Reset email field visibility
        document.getElementById('invite-email-group').style.display = 'block';
        document.getElementById('invite-email').required = true;
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


// ══════════════════════════════════════════════════════════════════════════════
// ConnectionsManager
// ══════════════════════════════════════════════════════════════════════════════
class ConnectionsManager {

    constructor() {
        this._pollInterval = null;
        this.startPoll();
    }

    // ── Open connections modal ────────────────────────────────────────────────
    open() {
        document.getElementById('connections-modal').style.display = 'block';
        this._bindTabs();
        this._showTab('my-connections');
    }

    // ── Tab switching ─────────────────────────────────────────────────────────
    _bindTabs() {
        document.querySelectorAll('.conn-tab').forEach(btn => {
            btn.onclick = () => this._showTab(btn.dataset.tab);
        });
        const search = document.getElementById('discover-search');
        if (search) {
            search.oninput = (e) => {
                const term = e.target.value.toLowerCase();
                document.querySelectorAll('#discover-list .conn-card').forEach(card => {
                    card.style.display = card.dataset.name.includes(term) ? '' : 'none';
                });
            };
        }
    }

    _showTab(tab) {
        document.querySelectorAll('.conn-tab').forEach(btn => {
            const active = btn.dataset.tab === tab;
            btn.style.background = active ? '#5865f2' : 'transparent';
            btn.style.color      = active ? '#fff'    : '#949ba4';
        });
        document.querySelectorAll('.conn-tab-content').forEach(el => {
            el.style.display = el.id === `tab-${tab}` ? 'block' : 'none';
        });
        if (tab === 'my-connections') this.loadConnections();
        if (tab === 'requests')        this.loadRequests();
        if (tab === 'discover')        this.loadDiscover();
    }

    // ── Load my connections ───────────────────────────────────────────────────
    async loadConnections() {
        const el = document.getElementById('connections-list');
        if (!el) return;
        el.innerHTML = '<p style="color:#949ba4;grid-column:1/-1;text-align:center;padding:30px">Loading…</p>';
        try {
            const [connRes, presRes] = await Promise.all([
                fetch('api/connections.php?list=1'),
                fetch('api/presence.php'),
            ]);
            const data     = await connRes.json();
            const presData = await presRes.json();
            const onlineIds = new Set((presData.online || []).map(u => u.id));

            if (!data.connections.length) {
                el.innerHTML = '<p style="color:#949ba4;grid-column:1/-1;text-align:center;padding:30px">No connections yet. Discover people to connect!</p>';
                return;
            }
            el.innerHTML = data.connections.map(u => {
                const isOnline  = onlineIds.has(u.id);
                const onlineDot = isOnline
                    ? `<span style="display:inline-block;width:10px;height:10px;background:#23a559;
                                   border-radius:50%;border:2px solid #2b2d31;margin-right:4px"></span>`
                    : '';
                return `
                <div class="conn-card" data-name="${this._esc(u.username).toLowerCase()}"
                     style="background:#2b2d31;border-radius:10px;padding:14px;text-align:center">
                    <div style="position:relative;display:inline-block;margin-bottom:8px">
                        <img src="${this._esc(u.avatar)}" onerror="this.src='assets/images/default-avatar.png'"
                             style="width:52px;height:52px;border-radius:50%;object-fit:cover;
                                    border:2px solid ${isOnline ? '#23a559' : '#5865f2'};cursor:pointer"
                             onclick="app.openProfile({id:${u.id},username:'${this._esc(u.username)}',role:'${u.role}',avatar:'${this._esc(u.avatar)}'})">
                        ${isOnline ? `<span style="position:absolute;bottom:1px;right:1px;width:12px;height:12px;
                            background:#23a559;border-radius:50%;border:2px solid #2b2d31"></span>` : ''}
                    </div>
                    <div style="font-weight:600;color:#fff;font-size:.88rem;margin-bottom:2px">
                        ${onlineDot}${this._esc(u.username)}
                    </div>
                    <div style="margin-bottom:10px"><span class="role-badge role-${u.role}">${u.role}</span></div>
                    <div style="display:flex;gap:6px;justify-content:center">
                        <button onclick="dm.openConversation(${u.id},'${this._esc(u.username)}','${this._esc(u.avatar)}')"
                                style="background:#5865f2;color:#fff;border:none;border-radius:6px;
                                       padding:5px 12px;font-size:.78rem;font-weight:600;cursor:pointer">
                            💬 Message
                        </button>
                        <button onclick="connections.remove(${u.id})"
                                style="background:transparent;border:1px solid #4f545c;color:#949ba4;
                                       border-radius:6px;padding:5px 10px;font-size:.78rem;cursor:pointer">
                            Remove
                        </button>
                    </div>
                </div>`;
            }).join('');
        } catch (e) { el.innerHTML = '<p style="color:#ed4245;text-align:center;padding:20px">Error loading connections.</p>'; }
    }

    // ── Load incoming requests ────────────────────────────────────────────────
    async loadRequests() {
        const el = document.getElementById('requests-list');
        if (!el) return;
        el.innerHTML = '<p style="color:#949ba4;text-align:center;padding:30px">Loading…</p>';
        try {
            const res  = await fetch('api/connections.php?requests=1');
            const data = await res.json();
            this._updateRequestsBadge(data.requests.length);
            if (!data.requests.length) {
                el.innerHTML = '<p style="color:#949ba4;text-align:center;padding:30px">No pending requests.</p>';
                return;
            }
            el.innerHTML = data.requests.map(r => `
                <div style="display:flex;align-items:center;gap:12px;padding:12px;
                            background:#2b2d31;border-radius:10px;margin-bottom:8px">
                    <img src="${this._esc(r.avatar)}" onerror="this.src='assets/images/default-avatar.png'"
                         style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #5865f2">
                    <div style="flex:1">
                        <div style="font-weight:600;color:#fff">${this._esc(r.username)}</div>
                        <div><span class="role-badge role-${r.role}">${r.role}</span></div>
                    </div>
                    <div style="display:flex;gap:6px">
                        <button onclick="connections.accept(${r.request_id})"
                                style="background:#23a559;color:#fff;border:none;border-radius:6px;
                                       padding:6px 14px;font-size:.82rem;font-weight:600;cursor:pointer">
                            Accept
                        </button>
                        <button onclick="connections.decline(${r.request_id})"
                                style="background:transparent;border:1px solid #4f545c;color:#949ba4;
                                       border-radius:6px;padding:6px 12px;font-size:.82rem;cursor:pointer">
                            Decline
                        </button>
                    </div>
                </div>`).join('');
        } catch (e) { el.innerHTML = '<p style="color:#ed4245;text-align:center;padding:20px">Error loading requests.</p>'; }
    }

    // ── Discover people ───────────────────────────────────────────────────────
    async loadDiscover() {
        const el = document.getElementById('discover-list');
        if (!el) return;
        el.innerHTML = '<p style="color:#949ba4;grid-column:1/-1;text-align:center;padding:30px">Loading…</p>';
        try {
            const res  = await fetch('api/connections.php?people=1');
            const data = await res.json();
            if (!data.people.length) {
                el.innerHTML = '<p style="color:#949ba4;grid-column:1/-1;text-align:center;padding:30px">You\'re connected with everyone!</p>';
                return;
            }
            el.innerHTML = data.people.map(u => `
                <div class="conn-card" data-name="${this._esc(u.username).toLowerCase()}"
                     style="background:#2b2d31;border-radius:10px;padding:14px;text-align:center">
                    <img src="${this._esc(u.avatar)}" onerror="this.src='assets/images/default-avatar.png'"
                         style="width:52px;height:52px;border-radius:50%;object-fit:cover;
                                border:2px solid #4f545c;margin-bottom:8px;cursor:pointer"
                         onclick="app.openProfile({id:${u.id},username:'${this._esc(u.username)}',role:'${u.role}',avatar:'${this._esc(u.avatar)}'})">
                    <div style="font-weight:600;color:#fff;font-size:.88rem;margin-bottom:4px">${this._esc(u.username)}</div>
                    <div style="color:#949ba4;font-size:.76rem;margin-bottom:10px;
                                overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
                                padding:0 4px">${this._esc(u.bio || 'No bio yet')}</div>
                    <button onclick="connections.request(${u.id})"
                            style="background:#5865f2;color:#fff;border:none;border-radius:6px;
                                   padding:6px 16px;font-size:.82rem;font-weight:600;cursor:pointer;width:100%">
                        + Connect
                    </button>
                </div>`).join('');
        } catch (e) { el.innerHTML = '<p style="color:#ed4245;text-align:center;padding:20px">Error loading people.</p>'; }
    }

    // ── Actions ───────────────────────────────────────────────────────────────
    async request(userId) {
        try {
            const res  = await fetch('api/connections.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'request', user_id: userId }),
            });
            const data = await res.json();
            if (data.success) {
                app.showNotification('Connection request sent!', 'success');
                this.loadDiscover();
            } else {
                app.showNotification(data.message, 'error');
            }
        } catch (e) { app.showNotification('Error sending request', 'error'); }
    }

    async accept(requestId) {
        try {
            const res  = await fetch('api/connections.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'accept', request_id: requestId }),
            });
            const data = await res.json();
            if (data.success) {
                app.showNotification('Connection accepted!', 'success');
                this.loadRequests();
            } else {
                app.showNotification(data.message, 'error');
            }
        } catch (e) { app.showNotification('Error accepting request', 'error'); }
    }

    // Called from profile modal when there's a pending_received request
    async acceptFromProfile(userId) {
        try {
            const res  = await fetch(`api/connections.php?requests=1`);
            const data = await res.json();
            const req  = data.requests.find(r => r.id == userId);
            if (req) await this.accept(req.request_id);
            else app.showNotification('Request not found', 'error');
        } catch (e) { app.showNotification('Error', 'error'); }
    }

    async decline(requestId) {
        try {
            const res  = await fetch('api/connections.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'decline', request_id: requestId }),
            });
            const data = await res.json();
            if (data.success) {
                app.showNotification('Request declined.', 'success');
                this.loadRequests();
            }
        } catch (e) { app.showNotification('Error declining request', 'error'); }
    }

    async remove(userId) {
        if (!confirm('Remove this connection?')) return;
        try {
            const res  = await fetch('api/connections.php', {
                method: 'DELETE', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ user_id: userId }),
            });
            const data = await res.json();
            if (data.success) {
                app.showNotification('Connection removed.', 'success');
                this.loadConnections();
                document.getElementById('profile-modal').style.display = 'none';
            }
        } catch (e) { app.showNotification('Error removing connection', 'error'); }
    }

    // ── Badge polling ─────────────────────────────────────────────────────────
    startPoll() {
        this._checkBadge();
        this._pollInterval = setInterval(() => this._checkBadge(), 20000);
    }

    async _checkBadge() {
        try {
            const res  = await fetch('api/connections.php?requests=1');
            const data = await res.json();
            this._updateRequestsBadge(data.requests?.length || 0);
        } catch (_) {}
    }

    _updateRequestsBadge(count) {
        const sidebarBadge = document.getElementById('conn-requests-badge');
        const modalBadge   = document.getElementById('requests-badge');
        [sidebarBadge, modalBadge].forEach(el => {
            if (!el) return;
            if (count > 0) { el.textContent = count; el.style.display = 'inline'; }
            else             el.style.display = 'none';
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    _esc(str) {
        return String(str || '').replace(/'/g,"&#39;").replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
}

// ── Bootstrap Connections ─────────────────────────────────────────────────────
const connections = new ConnectionsManager();
window.connections = connections;

// ══════════════════════════════════════════════════════════════════════════════
// GroupChatsManager — informal private group chats
// ══════════════════════════════════════════════════════════════════════════════
class GroupChatsManager {

    constructor() {
        this.activeGroupId   = null;
        this.activeGroupName = null;
        this.pollInterval    = null;
        this._bindDmTabs();
    }

    // ── Wire the DM list modal tabs ───────────────────────────────────────────
    _bindDmTabs() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.dm-main-tab');
            if (!btn) return;
            const tab = btn.dataset.tab;
            document.querySelectorAll('.dm-main-tab').forEach(b => {
                b.style.background = b.dataset.tab === tab ? '#5865f2' : 'transparent';
                b.style.color      = b.dataset.tab === tab ? '#fff'    : '#949ba4';
            });
            document.getElementById('dm-tab-dms').style.display    = tab === 'dms'    ? 'block' : 'none';
            document.getElementById('dm-tab-groups').style.display = tab === 'groups' ? 'block' : 'none';
            if (tab === 'groups') this.loadGroupList();
        });
    }

    // ── Group list ────────────────────────────────────────────────────────────
    async loadGroupList() {
        const el = document.getElementById('group-chats-list');
        if (!el) return;
        el.innerHTML = '<p class="dm-conv-empty">Loading…</p>';
        try {
            const res  = await fetch('api/group_chats.php?list=1');
            const data = await res.json();
            const groups = data.groups || [];
            if (!groups.length) {
                el.innerHTML = '<p class="dm-conv-empty">No group chats yet. Create one!</p>';
                return;
            }
            el.innerHTML = '';
            groups.forEach(g => {
                const item = document.createElement('div');
                item.className = 'dm-conv-item';
                const unread = g.unread_count > 0
                    ? `<span class="dm-conv-unread">${g.unread_count}</span>` : '';
                item.innerHTML = `
                    <div class="dm-conv-avatar" style="background:#5865f2;border-radius:50%;
                         width:40px;height:40px;display:flex;align-items:center;justify-content:center;
                         font-size:1.1rem;flex-shrink:0">👥</div>
                    <div class="dm-conv-info">
                        <div class="dm-conv-name">${this._esc(g.name)}</div>
                        <div class="dm-conv-last">${this._esc(g.last_message || 'No messages yet')}</div>
                    </div>
                    ${unread}`;
                item.addEventListener('click', () => this.openGroup(g.id, g.name));
                el.appendChild(item);
            });
        } catch (e) { el.innerHTML = '<p class="dm-conv-empty">Error loading groups.</p>'; }
    }

    // ── Open a group conversation ─────────────────────────────────────────────
    async openGroup(groupId, groupName) {
        this.activeGroupId   = groupId;
        this.activeGroupName = groupName;
        document.getElementById('group-chat-title').textContent = groupName;
        document.getElementById('group-messages').innerHTML     = '<p style="color:#949ba4;text-align:center;padding:20px">Loading…</p>';
        document.getElementById('dm-list-modal').style.display  = 'none';
        document.getElementById('group-chat-modal').style.display = 'block';

        await this._loadMessages();
        this._startPoll();
    }

    async _loadMessages() {
        try {
            const res  = await fetch(`api/group_chats.php?messages=${this.activeGroupId}`);
            const data = await res.json();
            if (!data.success) return;

            // Update member count
            const members = data.members || [];
            document.getElementById('group-member-count').textContent = `${members.length} members`;
            document.getElementById('group-chat-modal').dataset.isCreator =
                (data.group.created_by == currentUser.id) ? '1' : '0';
            document.getElementById('group-chat-modal').dataset.groupId = this.activeGroupId;

            const container = document.getElementById('group-messages');
            const wasBottom = container.scrollHeight - container.scrollTop - container.clientHeight < 60;

            const msgs = data.messages || [];
            if (!msgs.length) {
                container.innerHTML = '<p style="color:#949ba4;text-align:center;padding:20px">No messages yet. Say hello!</p>';
                return;
            }

            container.innerHTML = '';
            msgs.forEach(m => container.appendChild(this._createMsgEl(m)));
            if (wasBottom || true) container.scrollTop = container.scrollHeight;
        } catch (e) { console.error('Group load error', e); }
    }

    _createMsgEl(m) {
        const isMine = m.sender_id == currentUser.id;
        const div    = document.createElement('div');
        div.className = `dm-msg ${isMine ? 'dm-sent' : 'dm-received'}`;
        const ts  = m.created_at ? new Date(m.created_at.replace(' ','T')+'Z').toLocaleString([],{hour:'2-digit',minute:'2-digit'}) : '';
        const del = (!m.is_deleted && isMine)
            ? `<button class="dm-msg-delete-btn" onclick="groupChats.deleteMessage(${m.id})">✕</button>` : '';
        div.innerHTML = `
            <img class="dm-msg-avatar" src="${this._esc(m.sender_avatar)}"
                 onerror="this.src='assets/images/default-avatar.png'">
            <div class="dm-msg-meta">
                ${!isMine ? `<span style="color:#949ba4;font-size:.72rem;padding:0 3px">${this._esc(m.sender_username)}</span>` : ''}
                <div class="dm-msg-bubble" style="${m.is_deleted?'opacity:.5':''}">
                    ${this._esc(m.content)}
                    ${del}
                </div>
                <span class="dm-msg-time">${ts}</span>
            </div>`;
        return div;
    }

    // ── Send message ──────────────────────────────────────────────────────────
    async sendMessage() {
        const input   = document.getElementById('group-message-input');
        const content = input.value.trim();
        if (!content || !this.activeGroupId) return;
        input.value = '';
        try {
            const res  = await fetch('api/group_chats.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'send', group_id: this.activeGroupId, content }),
            });
            const data = await res.json();
            if (data.success) {
                const container = document.getElementById('group-messages');
                container.appendChild(this._createMsgEl(data.message));
                container.scrollTop = container.scrollHeight;
            } else {
                app.showNotification(data.message, 'error');
            }
        } catch (e) { app.showNotification('Error sending message', 'error'); }
    }

    async deleteMessage(msgId) {
        try {
            const res  = await fetch('api/group_chats.php', {
                method: 'DELETE', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ message_id: msgId }),
            });
            const data = await res.json();
            if (data.success) this._loadMessages();
        } catch (e) {}
    }

    // ── Create group ──────────────────────────────────────────────────────────
    async openCreateGroup() {
        document.getElementById('group-name-input').value = '';
        document.getElementById('create-group-modal').style.display = 'block';
        await this._loadConnectionPicker('group-member-picker', []);
    }

    async _loadConnectionPicker(containerId, exclude) {
        const el = document.getElementById(containerId);
        el.innerHTML = '<p style="color:#949ba4;text-align:center;padding:16px">Loading…</p>';
        try {
            const res  = await fetch('api/connections.php?list=1');
            const data = await res.json();
            const conns = (data.connections || []).filter(c => !exclude.includes(c.id));
            if (!conns.length) {
                el.innerHTML = '<p style="color:#949ba4;text-align:center;padding:12px">No connections available.</p>';
                return;
            }
            el.innerHTML = conns.map(c => `
                <label style="display:flex;align-items:center;gap:10px;padding:8px;
                              border-radius:6px;cursor:pointer;transition:background .1s"
                       onmouseover="this.style.background='#383a40'" onmouseout="this.style.background=''">
                    <input type="checkbox" value="${c.id}" style="width:16px;height:16px;cursor:pointer">
                    <img src="${this._esc(c.avatar)}" onerror="this.src='assets/images/default-avatar.png'"
                         style="width:30px;height:30px;border-radius:50%;object-fit:cover">
                    <span style="color:#fff;font-size:.88rem">${this._esc(c.username)}</span>
                    <span class="role-badge role-${c.role}" style="margin-left:auto">${c.role}</span>
                </label>`).join('');
        } catch (e) { el.innerHTML = '<p style="color:#ed4245;text-align:center">Error loading.</p>'; }
    }

    async createGroup() {
        const name    = document.getElementById('group-name-input').value.trim();
        const checked = [...document.querySelectorAll('#group-member-picker input:checked')];
        const members = checked.map(c => parseInt(c.value));

        if (!name) { app.showNotification('Group name required', 'error'); return; }
        if (!members.length) { app.showNotification('Select at least one member', 'error'); return; }

        try {
            const res  = await fetch('api/group_chats.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'create', name, members }),
            });
            const data = await res.json();
            if (data.success) {
                app.showNotification('Group created!', 'success');
                document.getElementById('create-group-modal').style.display = 'none';
                this.openGroup(data.group_id, name);
            } else {
                app.showNotification(data.message, 'error');
            }
        } catch (e) { app.showNotification('Error creating group', 'error'); }
    }

    // ── Group info + add member ───────────────────────────────────────────────
    async openGroupInfo() {
        const groupId   = this.activeGroupId;
        const isCreator = document.getElementById('group-chat-modal').dataset.isCreator === '1';
        document.getElementById('group-info-title').textContent = this.activeGroupName;
        document.getElementById('group-info-modal').style.display = 'block';

        const res  = await fetch(`api/group_chats.php?messages=${groupId}`);
        const data = await res.json();
        const members = data.members || [];
        const memberIds = members.map(m => m.id);

        const membersEl = document.getElementById('group-info-members');
        membersEl.innerHTML = `<div style="color:#949ba4;font-size:.78rem;font-weight:600;
            text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Members</div>` +
            members.map(m => `
                <div style="display:flex;align-items:center;gap:10px;padding:8px;
                            background:#2b2d31;border-radius:8px;margin-bottom:6px">
                    <img src="${this._esc(m.avatar)}" onerror="this.src='assets/images/default-avatar.png'"
                         style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                    <span style="flex:1;color:#fff;font-size:.88rem">${this._esc(m.username)}</span>
                    <span class="role-badge role-${m.role}">${m.role}</span>
                </div>`).join('');

        const addSection = document.getElementById('group-add-member-section');
        if (isCreator) {
            addSection.style.display = 'block';
            await this._loadConnectionPicker('group-add-picker', memberIds);
            // Replace checkboxes with Add buttons
            document.querySelectorAll('#group-add-picker input[type=checkbox]').forEach(chk => {
                const btn = document.createElement('button');
                btn.textContent = '+ Add';
                btn.style.cssText = 'background:#23a559;color:#fff;border:none;border-radius:6px;padding:4px 10px;font-size:.78rem;cursor:pointer;font-weight:600;margin-left:auto';
                btn.onclick = () => this._addMember(groupId, parseInt(chk.value));
                chk.parentElement.replaceChild(btn, chk);
            });
        } else {
            addSection.style.display = 'none';
        }
    }

    async _addMember(groupId, userId) {
        try {
            const res  = await fetch('api/group_chats.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'add_member', group_id: groupId, user_id: userId }),
            });
            const data = await res.json();
            if (data.success) {
                app.showNotification('Member added!', 'success');
                this.openGroupInfo();
            } else {
                app.showNotification(data.message, 'error');
            }
        } catch (e) { app.showNotification('Error adding member', 'error'); }
    }

    async leaveGroup() {
        if (!confirm('Leave this group? You won\'t be able to see its messages anymore.')) return;
        try {
            const res  = await fetch('api/group_chats.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({ action: 'leave', group_id: this.activeGroupId }),
            });
            const data = await res.json();
            if (data.success) {
                app.showNotification('Left group.', 'success');
                this.backToList();
            }
        } catch (e) { app.showNotification('Error', 'error'); }
    }

    backToList() {
        this._stopPoll();
        document.getElementById('group-chat-modal').style.display = 'none';
        document.getElementById('dm-list-modal').style.display    = 'block';
        // Switch to groups tab
        document.querySelectorAll('.dm-main-tab').forEach(b => {
            b.style.background = b.dataset.tab === 'groups' ? '#5865f2' : 'transparent';
            b.style.color      = b.dataset.tab === 'groups' ? '#fff'    : '#949ba4';
        });
        document.getElementById('dm-tab-dms').style.display    = 'none';
        document.getElementById('dm-tab-groups').style.display = 'block';
        this.loadGroupList();
    }

    // ── Polling ───────────────────────────────────────────────────────────────
    _startPoll() {
        this._stopPoll();
        this.pollInterval = setInterval(() => this._loadMessages(), 3000);
    }

    _stopPoll() {
        if (this.pollInterval) { clearInterval(this.pollInterval); this.pollInterval = null; }
    }

    _esc(str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str||'')));
        return d.innerHTML;
    }
}

// ── Bootstrap GroupChats ──────────────────────────────────────────────────────
const groupChats = new GroupChatsManager();
window.groupChats = groupChats;
