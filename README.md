# HG Community

HG Community is a lightweight Discord-inspired community platform built for Hackers Gurukul students. The platform supports real-time communication, role-based moderation, direct messaging, file sharing, and invite-only registration using a clean PHP architecture.

---

# Features

## Community Features
- Channel-based messaging
- Direct messaging system
- Typing indicators
- Online presence tracking
- Message search
- Pinned messages
- File uploads
- Responsive UI
- Unread message badges
- Soft-delete messaging system

---

# Role-Based Access Control

## Roles
- Admin
- Moderator
- Member

## Admin & Moderator Features
- Create/manage channels
- Generate invite links
- Ban users
- Mute users
- Restrict users
- Moderate community activity

---

# Authentication System

- Session-based authentication
- Invite-only registration
- Password hashing
- Role-based permissions
- User activity tracking

---

# Tech Stack

## Frontend
- HTML5
- CSS3
- Vanilla JavaScript

## Backend
- PHP 7.4+
- PDO

## Database
- MySQL

## Live Features
- Polling
- Server-Sent Events (SSE)

---

# Project Structure

```txt
hg_community
├─ api
│  ├─ auth.php
│  ├─ channels.php
│  ├─ dm.php
│  ├─ invites.php
│  ├─ messages.php
│  ├─ presence.php
│  ├─ typing.php
│  └─ users.php
├─ assets
│  ├─ css
│  │  ├─ auth.css
│  │  ├─ dm.css
│  │  └─ main.css
│  ├─ images
│  │  └─ default-avatar.png
│  └─ js
│     └─ main.js
├─ config
│  └─ database.php
├─ dev-tools
│  ├─ create-admin.php
│  ├─ setup-database.php
│  ├─ setup-instructions.md
│  └─ test-connection.php
├─ includes
│  └─ auth.php
├─ uploads
│  ├─ avatars
│  │  └─ .gitkeep
│  ├─ dm
│  │  └─ .gitkeep
│  └─ 1779802249_MYPIC1.jpg
├─ index.php
├─ login.php
├─ README.md
├─ register.php
└─ .gitignore
```

---

# Installation

## 1. Clone Repository

```bash
git clone https://github.com/poojitha-b-dev/hg-community.git
cd hg_community
```

---

## 2. Configure Database

Update:

```txt
config/database.php
```

Example:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'hg_community');
```

---

## 3. Create MySQL Database

```sql
CREATE DATABASE hg_community;
```

---

## 4. Run Database Setup

Open:

```txt
http://localhost/hg_community/dev-tools/setup-database.php
```

---

## 5. Create Admin Account

Open:

```txt
http://localhost/hg_community/dev-tools/create-admin.php
```

---

# Deployment

## Recommended Hosting
- Railway

## Database
- Railway MySQL

---

# Security Notes

Delete these files after production deployment:

```txt
dev-tools/create-admin.php
dev-tools/setup-database.php
dev-tools/test-connection.php
```

---

# Future Improvements

- WebSocket integration
- Notifications
- Message reactions
- Voice channels
- Theme customization
- Better mobile optimization

---

# Author

B.Poojitha

# https://github.com/poojitha-b-dev/