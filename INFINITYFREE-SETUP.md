# InfinityFree setup

1. Create a MySQL database in your InfinityFree hosting control panel.
2. Open phpMyAdmin for that database and import `storage/infinityfree-database.sql`.
3. Copy `storage/database-config.example.php` to `storage/database-config.php` and enter the host, database name, username and password shown in your hosting panel. Use port 3306 unless the panel says otherwise.
4. Upload index.php, db.php, style.css and storage/.htaccess plus your private storage/database-config.php into the hosting htdocs directory. PHP creates storage/sessions automatically.
5. Open the website and create the first administrator account.

Keep database-config.php private and out of Git. Do not upload database backups or session files. No live records or existing local administrator accounts are included in the SQL schema.
