# Reference

## How a copy is classified

1. **`WP_ENVIRONMENT_TYPE`**, from wp-config.php or the server environment, when it is set. `production` turns the guards off; `staging`, `development` and `local` turn them on.
2. Otherwise **`AXLE_STAGING_GUARD_PRODUCTION_HOSTS`**, a list of the live site's domains. A domain on the list is production, anything else is staging. This is the most reliable option when the live site answers on several domains, and on multisite networks with mapped domains.
3. Otherwise the **production lock**, set on the live site from **Tools > Staging Guard** or with `wp axle-staging-guard lock-production`. It stores `sha256( 'axle-staging-guard|' . <live domain> )`. Any copy whose Site Address domain hashes differently is staging. Search-replace rewrites domain names in the database but not their hashes, so the lock survives a database copy. `www.example.com` and `example.com` count as the same domain.
4. With none of these, WordPress assumes production and the plugin stays off.

When the lock is what makes a copy staging, every admin screen shows a warning until each admin dismisses it for that domain, and Site Health lists it as recommended. That is right on a real staging copy, and it is the only sign you would get if the live site's own address changed. Setting `WP_ENVIRONMENT_TYPE` on staging copies makes it explicit and removes the warning.

## Constants

| Constant | Values | Effect |
| --- | --- | --- |
| `AXLE_STAGING_GUARD_MAIL_MODE` | `block`, `redirect`, `allow` | Overrides the email setting. A `redirect` without a valid address falls back to `block` |
| `AXLE_STAGING_GUARD_REDIRECT_TO` | an email address | Where redirected email goes |
| `AXLE_STAGING_GUARD_ALLOWLIST` | addresses and `@domains`, comma separated | Recipients who always get email |
| `AXLE_STAGING_GUARD_HTTP_MODE` | `allow`, `log`, `block` | Overrides the outgoing request setting. An unknown value falls back to `block` |
| `AXLE_STAGING_GUARD_HTTP_ALLOWED_HOSTS` | hosts, comma or space separated, `*.example.com` for subdomains | Hosts that always go through, on top of the built-in ones |
| `AXLE_STAGING_GUARD_HTTP_BLOCKED_HOSTS` | hosts, same format | Hosts that are always blocked, in every mode |
| `AXLE_STAGING_GUARD_PRODUCTION_HOSTS` | domains, comma separated, with a port if they need one | The live site's domains. Decides production or staging before the lock |
| `AXLE_STAGING_GUARD_KEEP_BODIES` | `true`, `false` | Whether the log keeps message bodies |
| `AXLE_STAGING_GUARD_NOINDEX` | `true`, `false` | Whether staging copies are hidden from search engines |
| `AXLE_STAGING_GUARD_LOG_DAYS` | a number | Days a stopped email or request stays in its list. Default 7 |

When a constant is set, its setting on the screen is read-only.

## Hooks

| Hook | Type | Use |
| --- | --- | --- |
| `axle_staging_guard_environment` | filter | `( array $environment )`. Change the detected environment, see below |
| `axle_staging_guard_production_hosts` | filter | `( string[] $hosts )`. The live site's domains. Default from `AXLE_STAGING_GUARD_PRODUCTION_HOSTS` |
| `axle_staging_guard_mail_policy` | filter | `( array $policy, string $environment )`. The final email handling: `mode`, `redirect_to`, `allowlist`. For example redirect on staging but block on development. A redirect without a valid address still blocks |
| `axle_staging_guard_allow_email` | filter | `( bool $allow, array $atts )`. Let one email out untouched and unlogged, such as monitoring alerts to your own team |
| `axle_staging_guard_allowlist` | filter | `( string[] $allowlist )`. Addresses and `@domains` that still get email |
| `axle_staging_guard_redirect_subject` | filter | `( string $subject, string[] $stopped, array $atts )`. Subject of a redirected email. Default `[STAGING → original recipients] Subject` |
| `axle_staging_guard_noindex` | filter | `( bool $noindex )`. Whether staging copies are hidden from search engines |
| `axle_staging_guard_robots_txt` | filter | `( string $output )`. The robots.txt a staging copy serves |
| `axle_staging_guard_colours` | filter | `( array $colours )`. Admin bar colour per environment type. Anything but a hex colour falls back to the default |
| `axle_staging_guard_environment_label` | filter | `( string $label, string $type )`. The environment name in the admin bar and on the screens, such as "UAT" |
| `axle_staging_guard_log_size` | filter | `( int $size )`. Stopped emails the log keeps at most. Default 1000 |
| `axle_staging_guard_http_mode` | filter | `( string $mode, string $environment )`. `allow`, `log` or `block`. An unknown value blocks |
| `axle_staging_guard_http_allowed_hosts` | filter | `( string[] $hosts )`. Hosts that always go through. `*.example.com` covers subdomains |
| `axle_staging_guard_http_blocked_hosts` | filter | `( string[] $hosts )`. Hosts that are always blocked, even in allow all and log only |
| `axle_staging_guard_http_default_hosts` | filter | `( string[] $hosts )`. The built-in always-allowed hosts: WordPress.org and this site. Removing WordPress.org stops core updates on this copy |
| `axle_staging_guard_http_decision` | filter | `( array $decision, string $url, array $args )`. The decision for one request: `action` (`allow`, `log`, `block`), `reason`, `host`, `path`, `method`. Never runs on the live site. Do not store `$url`, it may hold a key |
| `axle_staging_guard_http_error_message` | filter | `( string $message, array $entry )`. The message of the `axle_staging_guard_http_blocked` error |
| `axle_staging_guard_http_log_size` | filter | `( int $size )`. Requests the list keeps at most. Default 1000 |
| `axle_staging_guard_log_days` | filter | `( int $days )`. Days a stopped email stays in the log |
| `axle_staging_guard_capability` | filter | `( string $capability )`. Who may see and change Axle Staging Guard. Default `manage_options`, or `manage_network_options` on multisite |
| `axle_staging_guard_mail_stopped` | action | `( array $entry, array $atts )`. Fires when an email is blocked or redirected. `$entry` has `id`, `to`, `subject`, `action`, `delivered` and `body` |
| `axle_staging_guard_http_blocked` | action | `( array $entry )`. A request was blocked. `$entry` has `id`, `method`, `host`, `path`, `action`, `reason` and `caller` |
| `axle_staging_guard_http_logged` | action | `( array $entry )`. A request went through in log only mode and was recorded |
| `axle_staging_guard_production_locked` | action | `( string $domain )`. The production lock was set on this domain |
| `axle_staging_guard_production_unlocked` | action | The production lock was removed |
| `axle_staging_guard_settings_saved` | action | `( array $new, array $old )`. Settings were saved, from the screen, WP-CLI or an ability. The gate's password hash and secret are left out |
| `axle_staging_guard_log_cleared` | action | `( string $list, int $removed )`. A list was cleared by hand: `mail`, `http` or `held` |
| `axle_staging_guard_gate_enabled` | filter | `( bool $on )`. Whether the password gate is on. It cannot turn the gate on for the live site |
| `axle_staging_guard_gate_allow_request` | filter | `( bool $allowed, string $reason, string $path )`. Let a request past the gate. `$reason` is `user`, `ip`, `path`, `session`, `link` or empty |
| `axle_staging_guard_gate_user_allowed` | filter | `( bool $allowed, WP_User $user )`. Whether a logged-in user skips the gate. Default true for every logged-in user |
| `axle_staging_guard_gate_bypass_paths` | filter | `( string[] $paths )`. Paths that skip the gate. `*` matches anything. REST routes are matched as `/wp-json/<route>` |
| `axle_staging_guard_gate_allowed_ips` | filter | `( string[] $ips )`. Addresses and CIDR ranges that skip the gate |
| `axle_staging_guard_gate_client_ip` | filter | `( string $ip )`. The visitor's address. Only `REMOTE_ADDR` is trusted, so behind a proxy or CDN return the real one here |
| `axle_staging_guard_gate_cookie_name` | filter | `( string $name )`. Name of the cookie that remembers a visitor |
| `axle_staging_guard_gate_session_hours` | filter | `( int $hours )`. How long a correct password keeps a visitor in. Default 12, between 1 and 720 |
| `axle_staging_guard_gate_max_attempts` | filter | `( int $attempts )`. Wrong passwords per IP address before a lockout. Default 5 |
| `axle_staging_guard_gate_lockout_minutes` | filter | `( int $minutes )`. How long a lockout lasts. Default 15 |
| `axle_staging_guard_gate_status` | filter | `( int $status )`. HTTP status of the gate screen. Default 401, between 400 and 599 |
| `axle_staging_guard_gate_message` | filter | `( string $text )`. The sentence above the password field |
| `axle_staging_guard_gate_label` | filter | `( string $label )`. The label of the password field |
| `axle_staging_guard_gate_button` | filter | `( string $text )`. The text of the button |
| `axle_staging_guard_gate_min_password_length` | filter | `( int $length )`. Shortest password accepted. Default 8, never below 4 |
| `axle_staging_guard_gate_link_durations` | filter | `( int[] $days )`. The lengths a share link can have. Default 1, 3, 7, 14 and 30. Anything outside 1 to 30 is dropped |
| `axle_staging_guard_gate_max_links` | filter | `( int $max )`. Share links that can exist at once. Default 50 |
| `axle_staging_guard_gate_link_retention_days` | filter | `( int $days )`. Days an expired link stays in the list. Default 30 |
| `axle_staging_guard_gate_link_url` | filter | `( string $url, array $link )`. The address of a share link |
| `axle_staging_guard_gate_turned_on` | action | The gate was turned on |
| `axle_staging_guard_gate_turned_off` | action | The gate was turned off |
| `axle_staging_guard_gate_password_changed` | action | The gate password changed. Everyone who entered the old one is signed out of the gate |
| `axle_staging_guard_gate_denied` | action | `( array $context )`. The gate stopped a request: `path` (no query string), `method` and `error` (empty, `password`, `expired`, `link`, `locked` or `rest`) |
| `axle_staging_guard_gate_unlocked` | action | `( string $ip )`. A visitor got in with the password |
| `axle_staging_guard_gate_locked_out` | action | `( string $ip, int $minutes )`. An address was locked out |
| `axle_staging_guard_gate_link_created` | action | `( array $link )`. A share link was made: `id`, `name`, `created`, `expires`, `last_used`, `uses`, `by` |
| `axle_staging_guard_gate_link_used` | action | `( array $link )`. Someone got in with a share link |
| `axle_staging_guard_gate_link_revoked` | action | `( array $link )`. A share link was revoked |
| `axle_staging_guard_cron_protected_hooks` | filter | `( string[] $hooks )`. Scheduled hooks that are never held. Remove one to allow holding it. Names starting `wp_privacy_` or `recovery_mode_` are protected as well |
| `axle_staging_guard_cron_protected_prefixes` | filter | `( string[] $prefixes )`. Starts of hook names that are never held. Default `wp_privacy_` and `recovery_mode_` |
| `axle_staging_guard_cron_suggestions` | filter | `( string[] $patterns )`. Regular expressions, without delimiters, that pre-tick a task on the Scheduled tasks tab. Default newsletter, export, sync, feed, renewal and subscription payment |
| `axle_staging_guard_cron_held_hooks` | filter | `( string[] $hooks )`. The hooks held on this copy. Protected hooks are removed afterwards |
| `axle_staging_guard_cron_args_summary` | filter | `( string $summary, string $hook, array $args )`. How a held task's arguments are described. Do not store `$args` |
| `axle_staging_guard_cron_list_limit` | filter | `( int $limit )`. Tasks listed at most. Default 500 |
| `axle_staging_guard_held_log_size` | filter | `( int $size )`. Held runs the list keeps at most. Default 1000 |
| `axle_staging_guard_cron_held` | action | `( array $entry )`. A task was held and listed: `id`, `hook`, `due`, `args` (a summary) and `owner` |
| `axle_staging_guard_cron_hold_changed` | action | `( string[] $held, string[] $previous )`. The held list was saved |
| `axle_staging_guard_cron_run_once` | action | `( string $hook, array $args )`. A task is about to run once by hand |
| `axle_staging_guard.copiedMessage` | JS filter | `( string $message, string $link )`. What is announced after Copy link |
| `axle_staging_guard.confirmMessage` | JS filter | `( string $message, Element $step )`. What is announced when an in-place confirm step opens |
| `axle_staging_guard.announced` | JS action | `( string $message )`. Something was announced to screen readers |

## Changing the detected environment

```php
add_filter( 'axle_staging_guard_environment', function ( array $environment ) {
	// [ 'type' => 'production|staging|development|local', 'source' => 'constant|hosts|lock|default' ]
	if ( str_ends_with( $_SERVER['HTTP_HOST'] ?? '', '.test' ) ) {
		return array( 'type' => 'local', 'source' => 'filter' );
	}
	return $environment;
} );
```

## Abilities

On WordPress 6.9 and later, Axle Staging Guard registers [abilities](https://developer.wordpress.org/apis/abilities/), which AI agents find and run through the [MCP adapter](https://github.com/WordPress/mcp-adapter) and anything else through the REST API under `/wp-abilities/v1/`. Each one is marked public, which the MCP adapter needs before it lists an ability.

| Ability | Does | Needs |
| --- | --- | --- |
| `axle-staging-guard/get-environment` | Read-only. Production, staging, development or local, how that was decided, and what happens to email, outgoing requests and search engines. An agent should ask this before sending email, calling an outside service, importing data or changing settings | `edit_posts`. Where redirected email goes is only shown with the Axle Staging Guard capability |
| `axle-staging-guard/get-mail-log` | Read-only. Stopped email, newest first. Input: `limit` | the Axle Staging Guard capability |
| `axle-staging-guard/get-http-log` | Read-only. Stopped and recorded outgoing requests, most recent first: method, host, path, caller and count. Input: `limit` | the Axle Staging Guard capability |
| `axle-staging-guard/test-url` | Read-only. Says what would happen to a request to `url`: allowed, logged or blocked, and why. Sends and records nothing | the Axle Staging Guard capability |
| `axle-staging-guard/send-test-email` | Sends a test email to `to` and says what happened to it. On the live site it really sends | the Axle Staging Guard capability |
| `axle-staging-guard/get-gate` | Read-only. Whether the password gate is on, whether a password is set, the open IPs and paths, and how many share links work | the Axle Staging Guard capability |
| `axle-staging-guard/set-gate` | Turns the gate on or off (`enabled`), and can set `password`. Idempotent | the Axle Staging Guard capability |
| `axle-staging-guard/list-gate-links` | Read-only. Share links: name, whether it works, expiry, last use and visits. The address is never listed | the Axle Staging Guard capability |
| `axle-staging-guard/create-gate-link` | Makes a named share link (`name`, `days` 1 to 30) and returns its address | the Axle Staging Guard capability |
| `axle-staging-guard/revoke-gate-link` | Destructive, idempotent (REST `DELETE`). Revokes the link `id` | the Axle Staging Guard capability |
| `axle-staging-guard/list-held-tasks` | Read-only. The hooks held on this copy and the runs held recently. Input: `limit` | the Axle Staging Guard capability |
| `axle-staging-guard/hold-task` | Holds or releases the scheduled task `hook` (`held` true or false). Protected hooks are refused. Idempotent | the Axle Staging Guard capability |
| `axle-staging-guard/clear-log` | Destructive, idempotent (REST `DELETE`). Empties the `mail`, `http` or `held` list, or `all` of them. The email, requests and tasks themselves are not affected | the Axle Staging Guard capability |

## WP-CLI

```sh
wp axle-staging-guard status [--format=json]       # environment, how it was detected, what is guarded
wp axle-staging-guard lock-production              # mark the current domain as the live site
wp axle-staging-guard unlock-production
wp axle-staging-guard log [--format=table|csv|json|count]
wp axle-staging-guard test-email <to>              # prints blocked, redirected, partly_blocked or sent
wp axle-staging-guard http-log [--format=table|csv|json|count]
wp axle-staging-guard test-url <url> [--method=GET] [--format=json]   # prints allowed, logged or blocked, and why
wp axle-staging-guard set <setting> <value>        # mail_mode, redirect_to, allowlist, keep_bodies, noindex, http_mode, http_allowlist, http_blocklist
wp axle-staging-guard clear-log [--list=mail|http|all]

wp axle-staging-guard gate on [--password=<password>]   # needs a password, set now or earlier
wp axle-staging-guard gate off
wp axle-staging-guard gate status [--format=json]
wp axle-staging-guard gate link create <name> [--days=7] [--porcelain]   # prints the link
wp axle-staging-guard gate link list [--format=table|csv|json|ids|count]
wp axle-staging-guard gate link revoke <id>

wp axle-staging-guard hold list [--format=table|csv|json|ids|count]      # every scheduled task and whether it is held
wp axle-staging-guard hold add <hook>...
wp axle-staging-guard hold remove <hook>...
wp axle-staging-guard hold run <hook>                                   # run a task once, now
wp axle-staging-guard hold log [--format=table|csv|json|count]
wp axle-staging-guard hold clear-log
```

## Password gate

On **Tools > Staging Guard > Password gate** you can put one shared password in front of a copy that is not the live site. Visitors who are not logged in see a small page with the site's name and a password field. It is off until you turn it on, it needs a password before it can be turned on, and it never runs on the live site, whatever a filter says.

**What the gate screen does.** It answers `401` (`429` during a lockout), sends `X-Robots-Tag: noindex, nofollow`, a robots meta tag and no-cache headers, and defines `DONOTCACHEPAGE`. It loads nothing from the theme and nothing from outside, so it shows the site's name and nothing else. It works without JavaScript, is labelled for screen readers and is translatable. After a wrong password the page says so. After 5 wrong passwords from one address, that address is locked out for 15 minutes, even for the right password. Both numbers have filters. The counter is kept in a transient under a hash of the address, never the address.

**What it covers.** The front end, feeds, posting a comment and the REST API. `admin-ajax.php`, `admin-post.php` and `xmlrpc.php` are not pages and are left alone.

**Who skips the gate**

| Who | How |
| --- | --- |
| Logged-in users | Always, unless `axle_staging_guard_gate_user_allowed` says no. REST requests need a logged-in user too, for example with an application password. A cookie login without a REST nonce counts as not logged in |
| IP addresses | A list of addresses and CIDR ranges (IPv4 and IPv6). Only `REMOTE_ADDR` is trusted. Behind a proxy or CDN, return the real address from `axle_staging_guard_gate_client_ip` |
| Paths | A list such as `/health` or `/webhooks/*`. A `*` matches anything. Paths are cleaned first, so `/health/../about` does not count as `/health`. A REST route is matched as `/wp-json/<route>` |
| Always open | `/robots.txt`, `/favicon.ico`, `/wp-login.php` and `/wp-cron.php` |

**Share links.** Make a link for one person: a name ("For Priya at the client") and a length from 1 to 30 days. The link carries a signed token. Opening it sets a signed, HTTP-only cookie for the length of the link, and sends the visitor to the page they asked for with the token removed. No account is needed. The list shows when each link was last used and how many visits it had. Revoking a link deletes its record, which also ends every cookie it set, on the next page view. The password works the same way: it sets a signed cookie for 12 hours, bound to the password, so changing the password signs everyone out.

**Where it is stored.** In the plugin's settings option: the password as a WordPress password hash, a signing secret, the lists and the links. The link itself is never stored. It is built from the record, so a person who manages the plugin can copy it again.

## Scheduled tasks

On **Tools > Staging Guard > Scheduled tasks**, every WP-Cron task is listed with its owner (the plugin or theme that registered it, when that can be traced), its next run, how often it repeats and a summary of its arguments. Tick the tasks to hold on this copy and save.

**Suggestions.** Tasks whose names contain newsletter, export, sync, feed, renewal or subscription and payment are ticked for you. A suggestion holds nothing until you save, and Site Health asks about suggestions nobody has decided on yet. The patterns have a filter.

**Held means not run.** WP-Cron asks WordPress for the tasks that are due. Held tasks are left out of that answer, so they are never started and never reschedule themselves. They stay on the schedule, which makes Site Health report them as late. That is expected on a copy. The first time a held run is due, it is listed under **Held tasks** with when it was due, its owner and a summary of its arguments: numbers and true or false as they are, text as its length, lists as their size. The values are never kept. Tasks started by `wp cron event run` are held too, because the callbacks of held hooks are taken off for that command. This plugin's own `wp axle-staging-guard hold run` is not held.

**Run once.** The screen and `wp axle-staging-guard hold run <hook>` run a task now, with its own arguments, the way WP-Cron would: a repeating task is rescheduled and a single event is removed. The task may contact the outside service it belongs to, which the screen says before it asks.

**Never held.** `wp_scheduled_delete`, `wp_scheduled_auto_draft_delete`, `delete_expired_transients`, `wp_privacy_delete_old_export_files`, `recovery_mode_clean_expired_keys`, `wp_delete_temp_updater_backups`, this plugin's own clean-up, and any hook starting `wp_privacy_` or `recovery_mode_`. Update checks may be held. `axle_staging_guard_cron_protected_hooks` and `axle_staging_guard_cron_protected_prefixes` change the lists.

## Email

Email is handled on the `wp_mail` filter (recipients, subject, Cc and Bcc) and `pre_wp_mail` (stopping a blocked email and reporting it as sent, so password resets and forms behave normally). SMTP plugins that send through `wp_mail()` are covered. A plugin that replaces `wp_mail()` entirely cannot be intercepted; the settings screen and Site Health both say so.

## Outgoing requests

On a copy that is not live, requests made through the WordPress HTTP API (`wp_remote_get()`, `wp_remote_post()` and `wp_remote_request()`, which WordPress, WooCommerce gateways, CRM and marketing plugins, webhook plugins and license checks use) are checked on `pre_http_request`, before anything is sent. It runs first, so no other plugin's short circuit sees a request that is stopped.

**Modes**

| Mode | What happens to a host that is not allowed |
| --- | --- |
| Allow all | Goes through, nothing recorded. The default for sites that already had the plugin, so an update never changes what a staging site can reach |
| Log only | Goes through and is recorded. The default for a fresh install |
| Block all except allowed hosts | Fails with a `WP_Error` (code `axle_staging_guard_http_blocked`) and is recorded |

A fresh install starts on log only rather than block. Blocking everything on first activation also stops premium plugin updates and license checks, OAuth logins that exchange a token on the server, and oEmbed, which would leave a site that looks broken before anyone chose that. Log only changes nothing, and after a day of normal use the list shows which services the site calls, so the allowlist is written from facts. Site Health keeps recommending a decision while the mode is allow all.

**Order of rules.** For each request: the blocklist first, which wins in every mode and over the allowlist. Then the hosts that are always allowed. Then your allowlist. Then the mode.

**Hosts that are always allowed**

| Host | Why |
| --- | --- |
| `wordpress.org`, `*.wordpress.org` | `api.wordpress.org` is core, plugin and theme update checks, translations and the plugin directory. `downloads.wordpress.org` serves the update packages. Without them Dashboard > Updates, Site Health and plugin installs fail. They are read-only and act on nobody's behalf |
| `w.org`, `*.w.org` | WordPress.org's short domain: plugin and emoji assets (`ps.w.org`, `s.w.org`) |
| this site's own host, and the `www.` variant | Loopback requests: WP-Cron spawning, the REST API health check in Site Health, and loopback editing checks. Both Site Address and WordPress Address are used |
| `localhost`, `127.0.0.0/8`, `::1`, `*.localhost` | Same machine: local object caches over HTTP, local search services, dev containers |

Everything else is left to the allowlist on purpose: other private network addresses can be production services (a shared database API or queue), and the same goes for any third-party host. Payment, CRM and webhook hosts such as `api.stripe.com`, `api.paypal.com`, `*.mailchimp.com`, `api.hubapi.com`, `*.salesforce.com`, `hooks.zapier.com`, `hook.make.com`, `hooks.slack.com` and analytics endpoints are therefore blocked in block mode unless you list them.

**Host patterns.** `api.example.com` matches that host. `*.example.com` matches every subdomain at any depth, not `example.com` itself, so list both when you need both. Ports, schemes and paths do not count. A bare `*` and `*.com` are refused because they would switch the guard off. Pasting a whole URL works: only its host is kept.

**The blocklist** is optional and empty by default. Hosts on it are blocked in every mode, so "allow all except these" is possible. It also beats the allowlist.

**What the list stores.** One entry per distinct method, host, path and caller, with a count and first and last seen times, so a cron job in a loop is one row. It never stores query strings, bodies or headers. Path parts that look like secrets or identifiers (Slack and Discord webhook tokens, Telegram bot tokens, Mailchimp's hash of an email address, anything with an `@`) are replaced with `{token}`, `{hash}` or `{email}`. The caller (`plugin:woocommerce`, `mu-plugin:sync`, `theme:my-theme`) is the nearest frame in the backtrace inside a plugin or theme folder, and is only worked out for requests that are stopped or recorded.

**What it cannot see.** Code that does not use the WordPress HTTP API: a PHP SDK that opens its own cURL handle or socket, `file_get_contents()` on a URL, and requests the browser makes. Test mode of a payment gateway is not special: `sandbox.paypal.com` or `api.stripe.com` with a test key are hosts like any other, so allow the ones you want to test against. If `WP_HTTP_BLOCK_EXTERNAL` is set, WordPress applies it after this check.

## Data

| Where | Contents |
| --- | --- |
| `axle_staging_guard_settings` option (autoloaded) | Email mode, test address, allowlist, keep-bodies and noindex switches, outgoing request mode and host lists, production lock hash |
| `axle_sg_mail` posts (private) | One per stopped email: recipients, subject, what happened and, only if keep-bodies is on, the message. Deleted after 7 days, and never more than 1,000 |
| `axle_sg_http` posts (private) | One per distinct outgoing request: method, host, masked path, caller, count and first seen. Deleted 7 days after they were last seen, and never more than 1,000 |
| `axle_staging_guard_lock_notice_dismissed` user meta | Which domain each admin hid the lock warning for |
| `axle_staging_guard_steps_dismissed` user meta | Whether each admin hid the Get started card |
| `axle_sg_held` posts (private) | One per held run: task name, when it was due, owner and a summary of its arguments. Deleted after 7 days, and never more than 1,000 |
| Settings option, gate keys | The password hash, the signing secret, the allowed IPs and paths, and the share links: name, dates, visits and who made it. Links expired for 30 days are removed |
| `axle_sg_gate_fail_*` transients | Wrong password counters, under a hash of the address, for 15 minutes |

The `axle_staging_guard_purge_log` cron event runs hourly. Uninstalling removes all of it on every site of a multisite network.

## Privacy

The log can hold personal data: email addresses, subjects and, if you turn it on, message bodies, which may include password reset links. So:

- message bodies are off by default
- entries are deleted after 7 days (`AXLE_STAGING_GUARD_LOG_DAYS`)
- the log takes part in **Tools > Export Personal Data** and **Tools > Erase Personal Data**
- **Settings > Privacy** gets suggested text for your privacy policy

The request list holds no personal data by design: no query strings, bodies or headers, and path parts that look like email addresses, hashes or keys are masked. It is deleted on the same schedule, and the suggested privacy policy text mentions it, but it needs no export or erase entry. The held task list keeps no values either, only the task name, its owner and a summary of the arguments. The live site keeps no log at all.

The share links hold a name an administrator typed, which can be a person's name, and the ID of the user who made the link. They take part in **Tools > Export Personal Data**, which lists the links a person made, and **Tools > Erase Personal Data**, which clears the name and the maker but keeps the link working. Visitors who get in receive one cookie with a signed token and are not otherwise tracked.

## Multisite

On a network, only network admins (`manage_network_options`) can see and change Axle Staging Guard, so one site admin on a staging network cannot switch email back on for everyone. Put `WP_ENVIRONMENT_TYPE` or `AXLE_STAGING_GUARD_PRODUCTION_HOSTS` with every mapped domain in wp-config.php, and it covers the whole network. The password gate, its share links and the held tasks are saved per site, so each site of a network has its own password and its own schedule.

## WordPress VIP

VIP caches `robots.txt`. After the first deploy of Axle Staging Guard to a VIP staging environment, flush it from **Settings > Reading** by toggling "Discourage search engines" on and off.
