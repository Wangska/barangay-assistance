# Private application storage

This directory contains local mail credentials, Google credentials, and uploaded IDs. Apache must enforce the included `.htaccess` (`Require all denied`). For another web server, deny all HTTP access to this directory before deploying, or set APP_STORAGE_DIR to a private directory outside the document root.

Git excludes all contents except this README and `.htaccess`. To configure a fresh clone, copy `config/mail.example.php` here as `mail.php` and `config/textbee.example.php` here as `textbee.php`, then supply your private credentials. Private files must be transferred separately when moving computers; they are not downloaded from GitHub.