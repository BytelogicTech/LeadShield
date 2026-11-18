# LeadShield

Simple internal tool: upload a lead file, clean it, download a safe list for Smartlead, and keep a permanent suppression list.

## Run (macOS)

Use three terminals with Homebrew PHP 8.4:

```bash
# Terminal 1 — web app
./bin/serve.sh

# Terminal 2 — background jobs (needed for cleaning)
./bin/queue.sh

# Terminal 3 — daily cleanup scheduler
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
php artisan schedule:work
```

Open http://127.0.0.1:8000/admin

- Email: `admin@bytelogic.local`
- Password: `password` (you will be asked to change it on first login)

## Notes

- Queues use the **database** driver (no Redis, no Horizon).
- Suppression list and DNS cache are never auto-deleted.
- Uploaded files and outputs older than 7 days are deleted daily.
