# Crosspost: build plan

Written 2026-09-19. Status, 2026-09-20: steps 1 to 3 of the build order are
done, and the Drush command of step 4. The remote ledger and the other
platforms are not.

## What it is

A Drupal module that carries a site's posts to social platforms. It is
middleware: the site owner decides which platforms to connect and whether to
pay for one that charges. The module helps them authenticate and pushes the
post. It ranks no platform, drops none, and works around no refusal.

What sets it apart from the existing modules: it shares a post only once the
public address answers, and it keeps a record per post and platform. So it is
right for static and decoupled sites, where Save in the CMS does not mean
the page exists yet, and it never posts the same thing twice.

## Principles

1. Platform-neutral. Every platform a site owner may want gets an adapter:
   Bluesky, Facebook Page, Instagram, LinkedIn, Mastodon, Reddit, Threads, X.
   Lists are alphabetical everywhere in the interface.
2. Each adapter carries its setup guide inside the module: what to create on
   the platform, review, cost, limits, numbered steps, then the key fields
   and the Connect button. Limits are stated as facts (Instagram needs an
   image and its links are not clickable; X bills for posting access;
   LinkedIn reviews company-page access).
3. No accounts, apps, subscriptions or payments of ours. Each site uses its
   own platform app and keys. Secrets go through the Key module.
4. A refusal or failure is shown in the platform's own words in the log,
   with a link to the guide. It is not retried around.
5. Every guide is checked against the platform's current documentation when
   its adapter is built, not written from memory.

## Screens

1. **Connections** (`/admin/config/services/crosspost`): a row per installed
   adapter with state (Connected, Not connected, Needs attention), the
   account, the last post, and Test / Set up / Disconnect. Opening a row
   shows its guide, then the fields. Tabs: Connections, Settings, Log.
2. **Share tab** on a node of any enabled content type: the text once, a
   character count per connected platform, optional per-platform wording,
   the platforms as checkboxes, the link card read from the page's own
   share tags, and the liveness message. The button reads "Share now" or
   "Share when the page is public".
3. **Log**: per post under the Share form, and site-wide with filters.
   Platform, result (Posted, Waiting, Refused, Failed, Cancelled), when,
   what the platform said, and Try now / Try again / Cancel.

## Architecture

- **Adapter plugin type** (`Plugin/CrosspostAdapter`, attribute-discovered):
  `label()`, `guide()` (the four facts + steps as render arrays),
  `credentialsForm()`, `connect()` / `callback()` for OAuth adapters,
  `whoAmI()` for Test, `limits()` (text length, image required, link
  handling), `post(Announcement): Result`. One class per platform; third
  parties add platforms from their own modules.
- **Connection config entity**: adapter id, account label, key ids, token
  expiry. A platform can hold several connections from the first version
  (two Facebook pages, two Bluesky accounts); each has its own keys, Test
  and Disconnect. Where one sign-in covers several accounts (Facebook lists
  the pages a person manages), the site owner ticks which to connect.
- **Announcement**: a content entity per node and connection: text, state,
  tries, next try, the platform's post id and URL, the platform's reply.
  This is the ledger. Storage behind an interface so a pipeline can keep it
  elsewhere through a "remote ledger" implementation.
- **Liveness check**: fetch the node's public URL (base URL configurable,
  since a static site's public host differs from the CMS), share only on
  200, optionally require the title in the page.
- **Queue + triggers**: a queue worker drains due announcements with
  back-off. Cron for conventional sites; `drush crosspost:share` for
  pipelines, run after a successful deploy.
- **Settings**: the public base URL; per content type, whether it gets the
  Share tab (none on a fresh install), the tag field that feeds hashtags,
  which connections start ticked, and whether sharing waits for a person
  (default) or happens by itself; how often a page's address is checked
  and for how long; how many times a failure is retried. Content types
  (nodes) first; other entities with a public page can follow.
- **Permissions**: administer connections; share content; view the log.
- Drupal 10.3+ and 11. Dependencies: Key. GPL-2.0-or-later. Coding
  standards, PHPStan, kernel and functional tests, GitLab CI template.

## Build order (a schedule, not the scope)

1. Skeleton, adapter plugin type, connection entity, Connections page with
   the guide panel. A "Test" adapter that posts nowhere, for tests.
2. Announcement entity, liveness check, queue, Share tab, log.
3. Bluesky and Mastodon adapters: open APIs, no review, so the whole pipe
   is proven end to end.
4. Drush command for deploy pipelines; remote ledger.
5. LinkedIn, Facebook Page, Threads, Instagram.
6. Reddit, X.
7. README, help pages, drupal.org project page, first alpha; then the
   security advisory coverage application.
