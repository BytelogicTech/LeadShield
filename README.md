# LeadShield

Upload a lead file, clean it, download a safe list for Smartlead, keep a permanent suppression list.

## Run (macOS, two or three terminals)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/dataclean

# Terminal 1 — website
./bin/serve.sh

# Terminal 2 — required for cleaning and imports
./bin/queue.sh

# Terminal 3 — optional, daily cleanup at 02:15
export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"
php artisan schedule:work
```

Open http://127.0.0.1:8000/admin

- Email: `admin@bytelogic.local`
- Password: `password`

## Uploads

1. **Uploads → Upload leads**
2. Choose lead CSV/XLSX
3. Optionally attach a **ListClean** result file (only `clean` emails are kept; dirty emails are removed and added to Suppressions)
4. Keep the queue worker running until status is **completed**
5. Download Final / Gateway / Later / Excel report

## Suppressions

- Add one email or domain
- Import Smartlead campaign export (every email is suppressed)
- Import plain CSV/TXT of emails or `@domain` lines
- Search, filter, delete, export

Suppressions and DNS cache are never auto-deleted.

## Cleanup

Every day (with `schedule:work` running), uploaded files and generated outputs older than **7 days** are deleted.
