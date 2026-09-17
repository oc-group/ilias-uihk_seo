# SEO UI plugin for ILIAS
Add SEO functionalities to ILIAS, including multilingual metadata, per-language hreflang tags in the sitemap, and a language switcher in the GlobalScreen metabar.

## Credits

Developed by **OC Open Consulting SB Srl** as part of the Horizon Europe project
[**DIAMETER**](https://www.diameter-eu.org/).

![Funded by the European Union](docs/FundedbytheEU_logo.png)

Funded by the European Union under Grant Agreement No 101177422. Views and opinions expressed are
however those of the author(s) only and do not necessarily reflect those of the European Union or
the European Commission. Neither the European Union nor the granting authority can be held
responsible for them.

## Web Server Requirements

Permalink routing works under both Apache and nginx, but the two are handled differently. On
activation, and again every time you save the configuration, the plugin writes an Apache block into
`assets/apache.conf`, inside its own plugin directory. It does **not** write into
`public/.htaccess`: ILIAS 10's own setup regenerates that file on every build, which would silently
discard a block written there. nginx has no equivalent managed file; its block is only generated in
memory, for the Configuration screen's permalink-routing panel to show, and an administrator pastes
it in by hand.

#### Apache

- **A one-time vhost directive is required**, added once by an administrator to the Apache vhost
  configuration (not `public/.htaccess`). The Configuration screen's permalink-routing panel shows
  this exact snippet, built from this installation's own absolute path:

  ```apache
  <Directory "/path/to/ilias/public">
      IncludeOptional /path/to/ilias/public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/Seo/assets/apache.conf
      RewriteOptions Inherit
  </Directory>
  ```

  Use the **absolute** path shown by the panel, not a path relative to your ILIAS root:
  `IncludeOptional` resolves a relative path against Apache's `ServerRoot`, not against the
  enclosing `<Directory>`, so a relative path here loads nothing and fails silently. This is the
  only step to repeat by hand: once it is in place, every later regeneration of
  `assets/apache.conf` applies automatically on the next Apache reload.
- **`mod_rewrite` must be enabled.** The block is wrapped in `<IfModule mod_rewrite.c>`, so without
  the module it is silently skipped and every permalink 404s.
- **A regenerated `assets/apache.conf` is not picked up until Apache is reloaded**, since
  `IncludeOptional` sits inside a `<Directory>` block, parsed at startup/reload rather than on every
  request. This applies to any content change to the file, including an ordinary configuration save.
  The permalink-routing panel states this explicitly.
- `assets/apache.conf` is owned entirely by the plugin and rewritten in full on every activation and
  configuration save. **Do not edit it by hand.**

#### nginx

An administrator must:

1. Open `Administration` -> `Plugins` -> `Seo` -> `Configure` -> `Configuration`, and copy the
   generated block shown behind the panel's nginx button.
2. Paste it inside the site's `server {}` block, replacing the default
   `location / { try_files $uri $uri/ =404; }` directive.
3. Reload nginx.
4. Repeat these steps whenever `ILIAS_HTTP_PATH` changes, or after any plugin update that changes
   the generated block.

This requires the standard ILIAS PHP-FPM location already present in the server block. Because that
is a regex location and the SEO block is a plain prefix location, nginx checks the regex location
first, so a native `PATH_INFO` script (such as `goto.php/crs/128/`) is never hijacked by the
rewrite.

#### Both web servers

The web server user needs write access to `assets/` for the Apache file. A failed write is never
silent: activation reports it, and so does the configuration screen's save and its
permalink-routing panel. The plugin still activates in that case, with everything but permalink
routing working. nginx needs no write access anywhere, since its block is never written to disk.

## Installation

### Download the plugin

From the ILIAS directory, run:

```sh
mkdir -p public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook
cd public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook
git clone -b release_10 https://github.com/oc-group/ilias-uihk_seo Seo
```

### Install Composer dependencies

```sh
cd Seo
composer install --no-dev
```

### Install the plugin

Return to the ILIAS root directory (one level above `public/`) and run:

```sh
composer du
```

**Never pass `--no-scripts`.** The plugin's object-level metadata editor is reached through a ctrl
base class of its own; until the post-autoload-dump scripts run, ILIAS cannot route to it, and its
forms fail to save with nothing logged.

Then, in ILIAS Administration:

1. Go into `Administration` -> `Extending ILIAS` -> `Plugins`
2. Look for the name of this plugin
3. Click on `Actions` -> `Install`

### Public visibility

A permalink only serves content that an **anonymous** visitor may open. Grant the `Anonymous` role
`read` (and `visible`) on every object you intend to publish, otherwise the page is excluded from the
sitemap and the permalink resolves to the login screen. The **Overview** screen shows the resulting
per-row sitemap status, including `not public`.

## Updating

Replacing the plugin directory does **not** carry over the dependency tree, because it is
gitignored and does not ship with the code. Run all four steps, in this order.

1. Update the sources:

```sh
cd public/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/Seo
git pull
```

2. Reinstall the dependency tree, from the plugin directory:

```sh
composer install --no-dev
```

3. Regenerate the class map and the artifacts. **From the ILIAS directory, not the plugin directory:**

```sh
cd /path/to/ilias
composer du
```

4. In ILIAS Administration:

- `Extending ILIAS` -> `Plugins` -> this plugin -> `Actions` -> `Update`. **Run this after every
  version bump, without exception.** Skipping it does more than postpone the update: the artifacts
  rebuilt in step 3 now carry the new version, ILIAS sees a pending update, and the plugin stops
  loading at the next request. Nothing is logged, and the plugin list still shows it as active; the
  plugin simply contributes nothing to any page until the action is run.
- `Languages` -> `Refresh Languages`, whenever a release renames or adds translation keys. `composer du`
  does not do this. Without it the affected screens display raw key names such as
  `screen_overview_title` instead of their labels.

## Administration

Everything the plugin offers an administrator is reached the ordinary way ILIAS reaches any
plugin's own settings: `Administration` -> `Extending ILIAS` -> `Plugins` -> **Seo** ->
`Configure`. That one entry point opens two tabs.

| Tab | What it opens |
|---|---|
| **Overview** | Every ILIAS object that has SEO metadata. Filter by language, robots and update frequency; bulk-edit robots, priority and frequency; edit or delete a single page's metadata. Also shows each row's sitemap status. |
| **Configuration** | The installation's SEO settings, described below. |

`Configure` opens on Overview when no tab is named, and switches to Configuration when that tab is
clicked.

**Who sees this.** The `Configure` action itself appears for anyone who can reach
`Administration` -> `Extending ILIAS` -> `Plugins` at all: core builds that link purely from the
existence of the plugin's configuration class, with no permission check of its own. Reaching
either tab is a separate, stricter question: both re-check `write` on the administration root on
every request, and a user without it is redirected to their starting page with a "no permission"
message rather than seeing either screen's content.

### Configuration

`Administration` -> `Extending ILIAS` -> `Plugins` -> `Seo` -> `Configure` -> **Configuration**
tab. One screen, one form, two sections:

- **Settings**: website title and sitemap submission to search engines. Saving this form is also
  what regenerates the permalink rewrite files (see Web Server Requirements above).
- **Language**: the active SEO languages, one default (master) plus any secondary ones, with
  a page count and translation percentage per language. Stored as JSON under the `seo_langs`
  config key. The default language is always active, whatever its own checkbox says.

## Cron Jobs

One cron job ships with the plugin; it is **inactive by default** and must be activated under
`Administration` -> `System Settings and Maintenance` -> `Cron Jobs`.

| Job | What it does |
|---|---|
| **Sitemap builder** | Writes `sitemap.xml` in the ILIAS root and adds the `Sitemap:` line to `robots.txt`. When no page is publishable it removes the file and the `robots.txt` line again. Optionally pings search engines (see Settings). |

Without it no `sitemap.xml` is ever produced.

## Public API

This is the funded, open-source base plugin. A separate, closed companion plugin (Site Structure
crawling and analytics) is built against it and consumes a small, deliberate part of this
plugin's code. That integration surface is thirteen methods across four classes, each marked
with an `@api` PHPDoc tag on the method itself: their signatures are not changed casually, because
a closed downstream consumer depends on them. Every method but one only reads; `deleteForPage()`
is the sole write, and it is the plugin's single writer of SEO metadata, so the contract adds no
write path of its own.

`ilSeo`:

- `fetchCrawlableKeys()`: bulk crawlable-key read
- `lastChangeForType()`: object-type last-change lookup
- `fetchAllRows()`: bulk full-row read
- `fetchById()`: single-row metadata read
- `hasPublicAccess()`: public-access test
- `fetchLangsFor()`: languages a page has SEO data for
- `deleteForPage()`: delete one page's stored metadata in every language it has (the sole write)

`ilSeoLinkTargetResolver`:

- `resolveGotoTarget()`, `resolveIntLinkTarget()`, `resolveExtLinkTarget()`: link target resolution

`ilSeoReachability`:

- `unreachableRefIds()`: unreachable ref ids

`ilSeoRobots`:

- `follows()`, `langKey()`: robots follow test and label

Nothing else in this codebase, including any other public method on these same four classes, is
part of that contract.

## Architecture Notes

The plugin injects `<meta>` tags and hreflang `<link>` elements into the page head, and adds a
language switcher to the GlobalScreen metabar when multiple SEO languages are active. Its
administration screens are reached through core's own plugin-configuration route. The plugin's one
ctrl base class is reserved for the object-level metadata editor and the metabar quick form. Every
one of these classes is resolved from the same generated ilCtrl artifacts, which is why
`composer du` must always run its post-autoload-dump scripts (see Installation).
