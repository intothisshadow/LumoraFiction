# Visitor Stats

Shows how often your stories are read, using only this site: no analytics service, no cookies, and nothing that identifies a reader. It adds a card to the dashboard, a **Visitor stats** screen, and a **Views** panel on each story's own screen.

## Turning it on

1. Open **Maintenance > Plugins** and press **Turn on** for Visitor Stats.
2. Open **Visitor stats** in the admin menu, tick **Count views** and press **Save**.

Turning the plugin on collects nothing by itself. Counting starts only when you tick the box, and unticking it stops counting again while keeping the figures so far.

## What is counted

One view is counted each time someone who is **not signed in** opens a story page or a chapter page. These never count:

- Signed-in people, including you and the story's own author.
- Previews, drafts, stories waiting for approval and unpublished stories.
- Search engine robots and other programs that say what they are, and a browser that is only loading a page in the background to be quick.

A reader who opens ten chapters is ten views, not one reader: the plugin does not try to recognise anyone.

## What is kept

Only totals for each day, in the plugin's own database tables. There is no row for a single visit, so nothing can be traced to one reader. Specifically, the plugin never stores:

- an IP address,
- the browser's identifying text (only a label such as Firefox or Mobile),
- the full address of the page a reader came from (only the domain, and only when it is another website).

## The Visitor stats screen

Administrators see views over time (the last 7, 30 or 90 days, the longest in weeks), the most read stories, the websites that sent readers, and breakdowns by browser, device and country.

## Countries

Countries need a free data file that you bring yourself. The plugin bundles nothing and never downloads anything.

1. Create a free MaxMind account and download **GeoLite2 Country** in **CSV format**.
2. Get the file to the server and start the import on the Visitor stats screen, under **Country data**, in whichever of these ways suits your host:
   - **From a file already on the server (the most reliable).** Put the ZIP (its name starts with `GeoLite2`), or the two files `GeoLite2-Country-Blocks-IPv4.csv` and `GeoLite2-Country-Locations-en.csv`, in the folder `storage/visitor-stats/incoming/` inside your site's folder, using FTP or your host's file manager. Reload the screen, and press **Import** beside the file. **Maintenance > System Information** shows where your site's folder is. Nothing is deleted afterwards, so remove the file yourself when you no longer need it.
   - **From a path you type.** Type the full path of a `.zip` file, or of a folder holding the two CSV files, anywhere on the server that PHP is allowed to read, and press **Import from this path**. A shortcut (symbolic link) is refused, and on a host that restricts which folders PHP may read (`open_basedir`) a path outside them is refused with a message that points you to the folder above.
   - **Upload it in the browser.** This works for small files only; a large file often fails because of the host's upload limit, which is why the two ways above exist.
3. The import carries on by itself and shows how far it has got; keep the page open until it says it has finished. The file is read in pieces so a shared host's time limit is never reached. If it stops because of a problem, the message says why and **Continue** tries again. Your existing country data stays in use until the new data is finished.

The country data is a single file under `storage/visitor-stats/`, never part of your database, so backing up or restoring the database does not carry it. Only IPv4 addresses are matched. A visitor's address is used for that one lookup and is never written down. When the data is more than six months old the screen says so, and an alert appears on **Maintenance > Alerts**, the dashboard and the header count; import a fresh download to replace it.

## Who sees what

- **Administrators** see the dashboard card and the Visitor stats screen.
- **Authors** see the Views panel on the screen of their own stories, and nothing about anyone else's.
- **Editors and Administrators** see the Views panel on every story's screen.

## Caching

The count is made by the application when it builds a story or chapter page. If you later put a page cache in front of the site that serves pages without running the application, views served that way are not counted.
