# MediTrack

A simple PHP clinic management project with plain HTML, tables and CSS.

## Requirements and setup

1. Install PHP 8.1+ with PDO MySQL and MySQL/MariaDB (for example XAMPP).
2. Import `storage/database.sql` through phpMyAdmin to create the local `meditrack` database and tables.
3. Copy `storage/database-config.example.php` to `storage/database-config.php`. Enter your database host, port, name, username and password. XAMPP commonly uses port 3306; the sample uses 3307 to match this project's original computer.
4. Put the project in XAMPP's `htdocs/MediTrack`, start Apache and MySQL, and visit http://localhost/MediTrack/ . Alternatively run `php -S localhost:8000 router.php`.
5. Create the first administrator account, then add doctors and other staff under Staff Accounts.

For InfinityFree, import `storage/infinityfree-database.sql` into the database you created in the hosting panel, and set its connection details in the private configuration file. See `INFINITYFREE-SETUP.md`.

The storage directory must be writable for PHP sessions. Apache must honor `storage/.htaccess` to block downloads of private files. The supplied PHP development router also blocks internal files. Use fictional patient information for this educational project.

## Features

Session login and logout; role-based access; dashboard; patient CRUD and search; doctor appointment scheduling and status management; consultation histories; prescriptions; date-filtered printable reports; profile updates; staff accounts; and activity logs.

Passwords are hashed, forms use CSRF tokens, database queries use prepared statements, and output is HTML escaped. Login retry delays are session-based classroom examples.

All roles can manage patients and appointments. Nurses can read consultations and prescriptions. Doctors and administrators can add consultations and prescriptions and view reports. Only administrators can delete patients, create staff and view logs. Patient deletion removes related appointments, consultations and prescriptions.

## Database transactions

All tables use InnoDB. The transaction helper in `db.php` commits each business operation together with its activity log or rolls everything back on failure:

1. Patient registration/update and activity log.
2. Appointment booking and activity log.
3. Consultation, optional prescription and activity log.

Account changes, patient deletion and appointment status changes also use transactions. A unique doctor/time constraint prevents duplicate scheduled bookings.

Appointment times use Asia/Singapore local time; audit and record timestamps use UTC.

## Verification

PHP syntax checks, database insertion, duplicate booking rejection, prescription relationships, search, cascade deletion and rollback were checked during development. Manually verify each staff role, profile changes, reports and logout in your own environment.

Real connection credentials, SQLite data, session files, backups and local deployment copies are excluded from Git.
