# Optional core patch — "ConnectWise" staff nav tab

osTicket has **no plugin hook** for adding a tab to the staff top navigation
(the tabs are hardcoded in `include/class.nav.php` → `StaffNav::getTabs()`, and
staff pages fire no signal a plugin can use). The plugin dashboard is therefore
reached by URL (`/scp/connectwise.php`) unless you add a nav tab manually.

This is the **only change the plugin needs outside its own folder**, it is
optional, and it must be **re-applied after an osTicket core upgrade** (upgrades
overwrite `class.nav.php`).

## Patch

In `include/class.nav.php`, inside `StaffNav::getTabs()`, add the guarded block
just before the `apps` tab:

```php
            // ConnectWise Integration dashboard — added by the ConnectWise plugin.
            // Admin-only, and only when the plugin's scp endpoint is present.
            if ($thisstaff->isAdmin() && defined('ROOT_DIR') && is_file(ROOT_DIR.'scp/connectwise.php'))
                $this->tabs['connectwise'] = array('desc'=>__('ConnectWise'),'href'=>'connectwise.php','title'=>__('ConnectWise Integration'));
            if (!is_null($this->getRegisteredApps()))
                $this->tabs['apps']=array('desc'=>__('Applications'),'href'=>'apps.php','title'=>__('Applications'));
```

## Notes

- **Admin-only:** the tab is shown only to admins; the endpoint enforces the
  same check server-side regardless.
- **Self-hiding:** the `is_file()` guard means the tab disappears automatically
  if the plugin's `scp/connectwise.php` endpoint is removed.
- **Reversible:** delete the added `if` block to remove the tab. No data or
  schema is involved.
- After applying, hard-refresh the staff panel (Ctrl+F5) to clear the cached
  navigation markup.
