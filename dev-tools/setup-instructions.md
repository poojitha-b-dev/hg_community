# HG Community Database Setup Instructions

## Prerequisites
- XAMPP, WAMP, MAMP, or similar local server environment
- PHP 7.4 or higher
- MySQL 5.7 or higher

## Step-by-Step Setup

### 1. Start Your Local Server
- **XAMPP**: Start Apache and MySQL from XAMPP Control Panel
- **WAMP**: Start WampServer and ensure both Apache and MySQL are green
- **MAMP**: Start MAMP and ensure servers are running

### 2. Create Database
1. Open phpMyAdmin: `http://localhost/phpmyadmin`
2. Click "New" in left sidebar
3. Database name: `hg_community`
4. Collation: `utf8mb4_general_ci` (recommended)
5. Click "Create"

### 3. Configure Database Connection
Update `config/database.php` with your credentials:

```php
define('DB_HOST', 'localhost');     // Usually localhost
define('DB_USER', 'root');          // Your MySQL username
define('DB_PASS', 'your_password'); // Your MySQL password (if any)
define('DB_NAME', 'hg_community');  // Database name
```

### 4. Set File Permissions
Create uploads directory and set permissions:
- Create folder: `uploads/` in project root
- Set permissions: 755 or 777 (depending on your server)

### 5. Test Database Connection
1. Place project files in your web server directory:
   - **XAMPP**: `C:\xampp\htdocs\hg_community\`
   - **WAMP**: `C:\wamp64\www\hg_community\`
   - **MAMP**: `/Applications/MAMP/htdocs/hg_community/`

2. Access: `http://localhost/hg_community/`

### 6. Database Tables Auto-Creation
The system will automatically create all required tables when you first access any PHP file:
- users
- channels
- messages
- invites
- user_permissions

### 7. Create First Admin User
Since registration requires an invite, you'll need to manually create the first admin:

```sql
INSERT INTO users (username, email, password, role, status) 
VALUES ('admin', 'admin@hackersgurukulcommunity.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'active');
```
Password for above user is: `password`

### 8. Access the Application
1. Go to: `http://localhost/hg_community/login.php`
2. Login with:
   - Username: `admin`
   - Password: `password`

## Common Issues & Solutions

### Issue: "Connection failed"
- Check if MySQL is running
- Verify database credentials in `config/database.php`
- Ensure database `hg_community` exists

### Issue: "Permission denied" for uploads
- Create `uploads/` folder in project root
- Set folder permissions to 755 or 777

### Issue: "Table doesn't exist"
- Tables are created automatically on first access
- If issues persist, manually run the SQL from `config/database.php`

### Issue: Can't login (no admin user)
- Manually insert admin user using the SQL query above
- Or use phpMyAdmin to insert the user record

## Production Deployment Notes
- Change default passwords
- Use environment variables for database credentials
- Enable HTTPS
- Set proper file permissions (755 for folders, 644 for files)
- Configure proper backup strategy

## Next Steps After Setup
1. Login as admin
2. Create invite links for moderators and members
3. Set up team channels
4. Configure user permissions
5. Start inviting students to join the community