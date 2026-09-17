# Security Policy

## Reporting a vulnerability

E-mail **plugins@oc-group.eu**, the address listed as `$responsible_mail` in `plugin.php`.

**Do not open a public issue.** This plugin serves public, unauthenticated pages on every
installation running it, so a public report is an exploitation guide until each of them has
updated.

Include, as far as you can:

- the plugin version (`$version` in `plugin.php`) and the ILIAS version
- what an attacker can achieve
- the steps to reproduce it

We confirm receipt, tell you whether we consider the report a vulnerability and why, and keep you
informed until a fix is released. Say in your report if you want to be credited in the release
notes; we will not name you otherwise.

## Scope

This policy covers the plugin's own code. It does not cover the ILIAS core, the web server
configuration an installation runs, or the access permissions an administrator grants: a permalink
serves only what that installation has already made public.
