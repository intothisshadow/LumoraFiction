# Sweep

Cleans out what a long-running archive no longer needs: old chapter versions, expired trash and links, accounts that never confirmed, and tags and fandoms nobody uses. It shows how much of each there is, lists what would go, and removes nothing until you confirm.

## Turning it on

Open **Plugins** and press **Turn on** for Sweep. A **Sweep** entry appears under **Maintenance**.

## Using it

1. Open **Maintenance > Sweep**. Each kind of item is listed with how many can be removed now; a kind with nothing to remove cannot be ticked.
2. Tick the kinds you want and press **Look at what will be removed**. The next page lists what each kind would remove (a few examples, never email addresses) and removes nothing.
3. Tick **Yes, remove them** and press **Remove for good**. This cannot be undone, so take a backup first if you are unsure.

## What each kind removes

- **Old chapter versions.** The earlier versions of a chapter beyond the number kept under **Settings > Retention**. The newest ones stay.
- **Expired trash.** Stories and chapters that have been in the trash longer than the retention period, with the chapters of a removed story. While the period is 0 (keep until deleted) there is nothing to remove.
- **Expired links.** Password-reset and email-confirmation links that have run out and can no longer be used.
- **Never-confirmed accounts.** Accounts that signed up, never confirmed their email address and are older than the age set on this screen (14 days unless you change it). They are removed the way an account is deleted: the name, email address, login and password go, and an empty placeholder row stays. An account that owns a story, that confirmed its address, or that is active or suspended is never listed.
- **Unused tags and fandoms.** Reviewed tags and fandoms that no story uses (a story in the trash counts as using one) and that have no characters or relationships of their own. Tags that writers proposed and nobody has approved yet are left for you to decide on under **Classification**.

Nothing a story still uses is ever offered, and each kind is removed in the same way the site removes it elsewhere, so covers, links and other plugins are handled as usual. A large cleanup is done in passes: if it stops at the limit, run it again.

## Settings

- **Days before a never-confirmed account can be removed**, from 1 to 365.
- **Remind me when this many items can be removed.** About once a week, when an Administrator opens the dashboard, the plugin counts what can be removed. If the number reaches this threshold, each Administrator gets a note in their notifications, unless they have not yet read the last one. Use 0 for no reminder.

Changes to the settings and every cleanup are written to the activity log.

Turning the plugin off removes nothing and leaves the settings in place.
