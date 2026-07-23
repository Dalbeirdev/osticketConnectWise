# Deploying to Hostinger — cwp.foxeraclub.com

This migrates the working local osTicket + ConnectWise plugin to the Hostinger
subdomain **https://cwp.foxeraclub.com**. It is a *migration* (upload files +
import the database), not a fresh install — so the plugin stays installed with
all 9 migrations applied and the `list.yaml` fix already in place.

**Two files are prepared for you** (in the local `_deploy-hostinger/` folder —
kept out of git because they contain secrets):

| File | What it is | Size |
|------|-----------|------|
| `connectwise-site.zip` | the whole osTicket app (plugin + scp endpoints included) | ~50 MB |
| `connectwise-db.sql` | full database dump; helpdesk URL already rewritten to the new domain | ~0.2 MB |

> ⚠️ You must do the steps below yourself — they require logging into your
> Hostinger account and creating a database password, which the assistant
> cannot handle on your behalf.

---

## 1. hPanel — set the PHP version

Hostinger → **Advanced → PHP Configuration** → set the subdomain to **PHP 8.1
or 8.2**. Under *PHP extensions* make sure these are enabled:
`mysqli`, `pdo_mysql`, `gd`, `mbstring`, `curl`, `json`, `intl`, `xml`,
`fileinfo`, `phar`, `openssl` (and `imap` only if you will pipe email in).

## 2. hPanel — create the MySQL database

Hostinger → **Databases → MySQL Databases** → create one. Write down the four
values Hostinger gives you — you'll need them in step 5:

- **Database name** (e.g. `u123456789_connectwise`)
- **Database user** (often the same as the name)
- **Password** (you set this — choose a strong one)
- **Host** — on Hostinger shared hosting this is almost always `localhost`

## 3. Upload the site files

Hostinger → **Files → File Manager** → open the subdomain's document root
(usually `public_html` for `cwp.foxeraclub.com`, or
`domains/cwp.foxeraclub.com/public_html`).

1. Delete the placeholder `default.php` / `index.html` if present.
2. **Upload** `connectwise-site.zip`.
3. **Right-click → Extract** into the current folder.
4. Confirm the files sit at the document root — you should see `scp/`,
   `include/`, `api/`, `index.php` directly inside `public_html`, **not**
   nested inside an extra `ConnectWise/` or `site/` folder. If they are nested,
   move them up one level.
5. Delete the now-empty zip.

*(Prefer FTP? Use FileZilla with the FTP credentials from hPanel → Files → FTP
Accounts, and upload the extracted `site/` contents instead of the zip.)*

## 4. Import the database

Hostinger → **Databases → phpMyAdmin** → select the database you created →
**Import** tab → choose `connectwise-db.sql` → **Go**.

This creates all 89 osTicket tables (17 of them the plugin's), the admin
account, and sets the helpdesk URL to `https://cwp.foxeraclub.com/`.

## 5. Point the app at the new database

In File Manager, edit **`include/ost-config.php`** and change only these four
lines to the values from step 2:

```php
define('DBHOST','localhost');                 // Hostinger DB host
define('DBNAME','u123456789_connectwise');    // your DB name
define('DBUSER','u123456789_connectwise');    // your DB user
define('DBPASS','the-password-you-set');      // your DB password
```

**Do NOT change `SECRET_SALT`** — it must stay exactly as shipped or encrypted
data in the database will not decrypt.

Then set the file back to read-only: File Manager → right-click
`ost-config.php` → **Permissions → 0644** (owner read/write, others read).

## 6. First load

Browse to **https://cwp.foxeraclub.com/** — the support center should load, and
**https://cwp.foxeraclub.com/scp/** should show the staff login.

Sign in with the existing admin account (username **dalbeir**, the password you
set during the local install). Then:

- **Admin Panel → Manage → Plugins** — confirm *ConnectWise Integration* is
  listed and **Active**.
- Open **https://cwp.foxeraclub.com/scp/connectwise.php** — the plugin
  dashboard. It will show *"No ConnectWise client registered yet"* until step 8.

## 7. Cron (the sync scheduler)

Hostinger → **Advanced → Cron Jobs** → add a job every 5 minutes:

```
*/5 * * * *  /usr/bin/php /home/USER/domains/cwp.foxeraclub.com/public_html/api/cron.php
```

(Replace `USER` and the path with the real absolute path shown in File Manager.
Alternatively point it at the plugin's own `cron.php`.)

## 8. Register the ConnectWise tenant

On the plugin dashboard click **+ Add Client** and enter:

- **Company ID + Public Key** joined with `+` (e.g. `mycompany+AbCdEf123`) —
  from *ConnectWise → System → Members → API Members*
- **Private Key**
- **API Client ID** — from developer.connectwise.com
- **Site URL** — e.g. `https://na.myconnectwise.net`

Click **Test Connection**. On success, run a small import first
(Import Filters → last 7 days), verify, then enable auto-import.

---

## Post-deploy checklist

- [ ] https://cwp.foxeraclub.com/ loads (support center)
- [ ] https://cwp.foxeraclub.com/scp/ login works
- [ ] Plugin shows **Active** in Manage → Plugins
- [ ] `scp/connectwise.php` dashboard renders
- [ ] Tickets queue opens without error *(the `list.yaml` fix is included)*
- [ ] Cron job runs (check the plugin Logs after a few minutes)
- [ ] Test Connection succeeds for the registered client

## Rollback

Nothing here touches the local XAMPP install — it keeps working. To redo the
remote deploy, drop the Hostinger database, delete the uploaded files, and
repeat from step 2.

## Security notes

- After go-live, delete `connectwise-site.zip` and `connectwise-db.sql` from
  the server if you uploaded them there.
- The dump contains the admin password hash and the encryption salt — never
  commit it to a public repository or share it.
- Force HTTPS in hPanel (Hostinger provides free SSL for the subdomain, already
  active).
