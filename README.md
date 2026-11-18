# LeadShield

Upload a lead file, clean it, download a safe list for Smartlead, keep a permanent suppression list.

## Run (macOS, three terminals)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/dataclean

# Terminal 1 — website
./bin/serve.sh

# Terminal 2 — cleaning jobs (required)
./bin/queue.sh

# Terminal 3 — daily file cleanup
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
php artisan schedule:work
```

Open http://127.0.0.1:8000/admin

- Email: `admin@bytelogic.local`
- Password: `password`

## Upload flow

1. Open **Uploads** → **Upload leads**
2. Choose a CSV/XLSX file
3. Wait on the upload page (progress refreshes automatically)
4. Download **Final CSV**, **Gateway CSV**, **Later CSV**, and the **Excel report**

Keep `./bin/queue.sh` running or cleaning will stay queued.

## Suppressions

Open **Suppressions**:

- **Add one** — single email or domain
- **Import Smartlead export** — CSV with email, lead_status, lead_category, is_bounced, got_reply, sent_time, reply_message (every row is suppressed)
- **Import emails / domains** — CSV/TXT of emails or `@domain` / domain lines
- **Export CSV** — download the full list
- Search and filters on the table; delete one or many rows

Suppressions are permanent and never auto-deleted.
