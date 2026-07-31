# Import staging directory

This tracked directory keeps the `imports/` folder visible in GitHub and available for deployments that need temporary server-side import storage.

Stockora currently processes CSV/TXT uploads in `shop/import.php` and records their results in the database; uploaded files are not stored here permanently.
