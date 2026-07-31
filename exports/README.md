# Export staging directory

This tracked directory keeps the `exports/` folder visible in GitHub and available for deployments that need temporary server-side export storage.

Stockora currently generates CSV exports in `shop/export.php` and streams them directly to the browser; generated files are not stored here permanently.
