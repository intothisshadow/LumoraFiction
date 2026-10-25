# Lumora Fiction security guide

This guide covers the protections an administrator can see and adjust, and what to consider when deploying. For the rest of the admin screens see the [administration guide](ADMINISTRATION.md).

## Sign-in protection

- **Too many failed attempts.** After five wrong passwords from one address, sign-in is paused for fifteen minutes. **Settings > Security** changes the number, the length of the pause, and whether an address that keeps failing is paused for longer each time (on by default; each further batch of failures within a day doubles the pause, up to sixteen times). When a pause starts and the last sign-in named an account, the owner of that account gets an email saying so, at most once an hour; switch this off on the same screen. The sign-in page says the same thing whether or not that account exists.
- **Passwords, sign-in length and HTTPS.** The same screen sets the shortest password allowed (8 to 64 characters, usually 10; it applies to passwords set from then on, never to ones already set, and the sign-up, reset and password forms show the current number), how many hours a sign-in lasts without activity (1 to 720, usually 24; nobody is signed out when it changes), and an **HTTPS only** switch that tells browsers to refuse plain HTTP for the site for a year. Because that cannot easily be undone once browsers have seen it, it can only be switched on from a page opened over HTTPS, and it is off by default.
- **Sites behind a proxy or CDN.** If your site is served through a reverse proxy or a CDN such as Cloudflare, every visit seems to come from the proxy, so the pause above and the registration limits would treat all your visitors as one. List your proxies under **Trusted proxies** and the site reads the visitor's address, and whether they used HTTPS, from the headers the proxy adds. The screen shows the address your own visit came from and the address the site recorded, so you can see whether it works. List only proxies you control, and leave the box empty if you have none: the site believes those headers from anything on the list, and a list that is too wide lets a visitor choose the address that is recorded. Very wide ranges are refused.

## Spam protection

**Settings > Spam protection** guards the registration form, and the password-reset and confirmation-email forms, against programs that fill them in. Every form carries fields only a program would fill in and a note of when it was shown: a form that fills those fields is dropped without a word, and a form sent within a few seconds of loading is sent back with a request to wait. Registration also has a shortest wait between two submissions from one internet address, a cap per address per hour, and a pause for the whole site when a flood of sign-ups arrives from many addresses (the dashboard says so, and you can open registration again from this page). Every submission counts, whether or not it was filled in correctly, and a limit of 0 turns that limit off. You can also refuse throw-away email services (on by default) and your own list of email domains, ask a question that people find easy and programs do not, and, if you choose, have new sign-ups checked by Akismet, an outside service that is off unless you enter a key and turn it on. Visitors who share one address, such as a school, share one allowance for the per-address limits; behind your own proxy or CDN, list it under Settings > Security so each visitor has their own.

## Security events

**Maintenance > Security events** lists what the protections blocked, newest first: sign-ins locked after too many failures, forms that looked automated, and registrations that were refused or dropped. It records the kind of event, the internet address, the time and a short note, and never what a visitor typed. Entries are removed after 30 days, which you can change under Settings > Spam protection.

## Privacy of content and accounts

- A login name is private and used only to sign in; public pages use a separate display name and address.
- Drafts, stories waiting for approval, unpublished and deleted stories answer "page not found" to everyone except their author and the staff who can edit them.
- Plugins and themes run as program code on your site. Only install ones you trust, and see [Alerts](ADMINISTRATION.md) for the problems the site flags about its own setup.
