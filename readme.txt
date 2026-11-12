=== Axle Staging Guard ===
Contributors: aryanjasala
Tags: staging, environment, email, noindex, api
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Stop staging sites from emailing customers, calling live APIs and showing in Google. Add a password gate and hold risky scheduled tasks.

== Description ==

Every agency has a staging story. A copy of the live database goes to staging, and then:

* a scheduled task emails every customer from the staging site,
* Google indexes staging.example.com and it outranks the real site,
* someone spends an hour editing staging, thinking it was live.

A fourth one is quieter: the copy keeps talking to your payment gateway, CRM, Mailchimp list or Zapier webhooks with the live keys it inherited.

A fifth is the one a client finds out about: someone finds staging.example.com in a certificate log, and the unfinished site is open to anyone with the address.

Axle Staging Guard prevents all five, on every copy that is not the live site.

**On staging, development and local copies it:**

* Blocks all outgoing email, or sends it all to one test address with the real recipients shown in the subject. Cc and Bcc recipients are removed too.
* Lets your own team keep receiving email: list addresses or whole domains (@youragency.com) under "Always deliver to".
* Keeps a list of the last 50 stopped emails (recipient, subject and time), so you can see what would have gone out. Turn on "keep message bodies" to check email content as well.
* Sends a test email from the settings screen, so you can see exactly what happens to email from this copy.
* Blocks outgoing API calls except to hosts you allow, or just logs them. It covers requests made through the WordPress HTTP API, which is what payment gateways, CRM and marketing plugins, webhook plugins, Slack notifications and license checks use. A blocked call fails with a clear error, never a fake success.
* Always lets through what WordPress needs: wordpress.org and its subdomains (updates, translations, the plugin directory), the site's own address and localhost. Add your own hosts, with wildcards like `*.example.com`.
* Keeps a list of the stopped or logged calls: method, host, path, the plugin that made them and how often. No query strings, bodies or headers, and keys in webhook URLs are masked.
* "Test a URL" shows what would happen to a request, without sending it.
* Hides the site from search engines with an `X-Robots-Tag` header, a `noindex, nofollow` robots meta tag and a robots.txt that blocks everything, and turns off the XML sitemap.
* Colours the whole admin bar (orange for staging, blue for development, green for local) and labels it, on the dashboard and on the front end.
* Adds the environment to every browser tab title, for example "[Staging] Posts", so the right tab is easy to find.
* Puts a password in front of the copy. Visitors who are not logged in see a small password screen instead of the site. It is off until you turn it on, and the screen is never cached or indexed. Logged-in users, IP addresses and paths you list (a health check, a webhook) skip it, and wrong passwords lock an address out for a while.
* Makes share links for the gate: one named link per person ("For Priya at the client"), valid for 1 to 30 days, with the time it was last used in the list. Revoke a link and that person is out on their next page view. No account needed.
* Lists every scheduled task (WP-Cron) with its owner and next run, and holds the ones you tick on staging, such as a weekly newsletter export or a subscription renewal. Tasks that look risky are ticked for you, but nothing is held until you save. A held task is listed with when it was due, never dropped silently, and you can run it once by hand. WordPress's own clean-up and privacy tasks cannot be held.
* Adds Site Health checks that warn when email from a staging copy could still reach real people, when staging copies of the live site would not be detected, when a staging copy is open to anyone, and when tasks that look risky still run.
* Has a Help tab on every screen and works with the keyboard and screen readers.

**On the live site it does nothing.**

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

Part of [Axle](https://axlewp.com/) by AryanX, a family of free plugins for running WordPress sites safely.

= How it knows which copy is live =

1. If `WP_ENVIRONMENT_TYPE` is set in wp-config.php or in the server environment, it uses that. This is the WordPress standard, and many hosts set it for you.
2. Otherwise, use the **production lock**: on the live site, go to Tools > Staging Guard and click "This is the live site". From then on, any copy of the database running on a different domain is treated as staging, with no wp-config.php change.

The lock stores a hash of the live domain instead of the domain itself. Migration tools that search and replace the domain name in the database leave the hash alone, so the lock survives the copy to staging.

If neither is set, WordPress assumes production and Axle Staging Guard stays off.

= Settings in wp-config.php =

Fix the email behaviour per environment, so a database copy cannot change it:

`define( 'AXLE_STAGING_GUARD_MAIL_MODE', 'redirect' ); // block, redirect or allow`
`define( 'AXLE_STAGING_GUARD_REDIRECT_TO', 'qa@example.com' );`
`define( 'AXLE_STAGING_GUARD_ALLOWLIST', '@youragency.com, client@example.com' );`
`define( 'AXLE_STAGING_GUARD_HTTP_MODE', 'block' ); // allow, log or block`
`define( 'AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS', 'sandbox.paypal.com, *.youragency.com' );`
`define( 'AXLE_STAGING_GUARD_HTTP_BLOCKED_HOSTS', 'hooks.slack.com' ); // always blocked, in every mode`
`define( 'AXLE_STAGING_GUARD_PRODUCTION_HOSTS', 'example.com, shop.example.com' ); // the live domains`
`define( 'AXLE_STAGING_GUARD_KEEP_BODIES', false );`
`define( 'AXLE_STAGING_GUARD_NOINDEX', true );`
`define( 'AXLE_STAGING_GUARD_LOG_DAYS', 7 );`

= WP-CLI =

* `wp axle-staging-guard status` shows the environment, how it was detected, and what is guarded.
* `wp axle-staging-guard lock-production` marks the current domain as the live site.
* `wp axle-staging-guard unlock-production` removes the lock.
* `wp axle-staging-guard log` lists stopped email.
* `wp axle-staging-guard test-email you@example.com` sends a test email and reports what happened to it.
* `wp axle-staging-guard http-log` lists blocked and logged outgoing requests.
* `wp axle-staging-guard test-url https://api.example.com/v1` says what would happen to a request to that URL.
* `wp axle-staging-guard set mail_mode redirect` changes a setting, and `wp axle-staging-guard clear-log` empties the lists.
* `wp axle-staging-guard gate on --password=...`, `gate off` and `gate status` control the password gate.
* `wp axle-staging-guard gate link create "For Priya" --days=7`, `gate link list` and `gate link revoke <id>` manage share links.
* `wp axle-staging-guard hold list`, `hold add <hook>`, `hold remove <hook>`, `hold run <hook>` and `hold log` manage held scheduled tasks.

**Try it before you install.** [try.axlewp.com/axle-staging-guard](https://try.axlewp.com/axle-staging-guard) opens a private WordPress in your browser with this plugin and some demo content.

== Frequently Asked Questions ==

= Does it work with SMTP plugins? =

Yes, for plugins that send through WordPress's own `wp_mail()` function, which is most of them. A few plugins replace `wp_mail()` entirely. Axle Staging Guard detects this and shows a warning on its settings screen, because it cannot intercept those emails.

= Will blocked emails break password resets on staging? =

No. Blocked emails are reported to WordPress as sent, so forms and password resets behave normally. Use redirect mode if you need to receive them.

= What if I copy the staging database to production? =

The production lock is still stored, and the live domain still matches it, so the live site stays unguarded. The search engine setting under Settings > Reading is never changed by this plugin.

= Can I change how the environment is detected? =

Use the `axle_staging_guard_environment` filter. It receives an array with `type` (production, staging, development or local) and `source`.

= What if the live site moves to a new domain? =

Then the production lock no longer matches, and the live site would count as staging. Axle Staging Guard warns about this on every admin screen and in Site Health, so it does not go unnoticed. Lock the new domain from Tools > Staging Guard, or list every live domain in `AXLE_STAGING_GUARD_PRODUCTION_HOSTS` so a move never trips it. `www.` and the bare domain already count as the same.

= Does the email log keep personal data? =

The list of outgoing requests holds none: no query strings, bodies or headers, and parts of a path that look like email addresses, hashes or keys are hidden. The email log keeps who an email was for and its subject, and the message only if you turn that on. Entries are deleted after 7 days, and the email log is part of the WordPress export and erase personal data tools. The live site keeps no log.

= Does it block my payment gateway's test mode? =

It blocks hosts, not modes. `api.stripe.com` and `api.paypal.com` are the same host whether the key is a test key or a live one, so in "Block all except allowed hosts" both are blocked until you allow them. Allow the sandbox host you want to test against (for example `sandbox.paypal.com`), or allow `api.stripe.com` only when the copy has test keys. Not sure which hosts a plugin calls? Use "Log only" for a day and read the list.

= How do I allow a host? =

Add it under Tools > Staging Guard > "Calls to other services" > Allowed hosts, one per line. `api.example.com` allows that host, and `*.example.com` allows every subdomain (but not example.com itself). You can also set `AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS` in wp-config.php, or use the `axle_staging_guard_http_allowed_hosts` filter. "Test a URL" on the same screen shows whether an address would get through.

= Who sees the password screen, and who skips it? =

Visitors who are not logged in. Logged-in users, the IP addresses and ranges you list, and the paths you list skip it. The login screen, WP-Cron and robots.txt always stay open. REST API requests need a logged-in user, for example with an application password. The gate covers the front end, feeds, posting a comment and the REST API. It does not cover admin-ajax.php, admin-post.php or xmlrpc.php. It never runs on the live site.

= How does a share link work? =

Make one under Tools > Staging Guard > Password gate: type who it is for and pick 1 to 30 days. Send the link to that person. Opening it lets them in without the password and without an account, and sends them to the page they asked for. Revoke the link and their access ends on their next page view. Changing the gate password signs out everyone who used the password, but not people with a link.

= Does a held scheduled task run later? =

No. A held task stays on the schedule but is never started on that copy, and it is listed with when it was due. Site Health may report it as late, which is expected. Run it once by hand from Tools > Staging Guard > Scheduled tasks or with `wp axle-staging-guard hold run`. Tasks that WordPress needs to stay healthy cannot be held.

= Does the gate keep personal data? =

The names you give share links, and who made each link, which a person can have exported or erased from the WordPress privacy tools. A visitor who gets in receives one cookie with a signed token. Wrong passwords are counted against a hash of the address for a few minutes.

= Does it affect production? =

No. On the live site requests are never blocked, changed or recorded, whatever the settings say, and there is no list.

= Will updating break my staging site? =

No. Sites that already use the plugin keep "Allow all" for outgoing requests, so nothing changes until you choose. A fresh install starts on "Log only", which lets everything through and records it, so you can see what the site calls before you start blocking. Site Health reminds you while a staging copy allows all calls.

= What does a blocked request look like to the plugin that made it? =

A failed request, like a network error: the WordPress HTTP API returns a WP_Error with the code `axle_staging_guard_http_blocked`. It is never reported as a success.

= What does it not catch? =

Code that does not go through the WordPress HTTP API: a PHP SDK that opens its own cURL connection, `file_get_contents()` on a URL, and anything the visitor's browser sends (analytics scripts, embeds). Server-side requests from WordPress and from plugins that use `wp_remote_get()` and friends are covered.

== Screenshots ==

1. The coloured admin bar on a staging copy.
2. Tools > Staging Guard with the email log.
3. The password gate screen visitors see.
4. Share links, each named and timed, with when it was last used.
5. Scheduled tasks, with the ones to hold on staging.

== Changelog ==

= 2.2.0 =
* New: password gate with share links and held scheduled tasks

= 2.1.0 =
* New: guard outgoing API calls on staging, not only email

= 2.0.1 =
* Fix: label the Tools menu item "Staging Guard"

= 2.0.0 =
* Changed (breaking change): rename to Axle Staging Guard for WordPress.org

= 1.3.0 =
* Fix: make the abilities visible to MCP agents
* New: add abilities so AI agents can tell staging from the live site before doing anything risky

= 1.2.0 =
* New: add filters for email handling, robots.txt, colours and labels, and actions for the production lock
* Fix: warn when the live site counts as staging, and move the email log out of one option

= 1.1.0 =
* New: "Always deliver to" allowlist for addresses and @domains.
* New: send a test email from the settings screen or WP-CLI.
* New: optionally keep message bodies in the email log.
* New: Site Health check.
* New: environment shown in browser tab titles.
* New: redesigned settings screen with status cards.
* Rewritten as PSR-4 classes.

= 1.0.0 =
* First release.
