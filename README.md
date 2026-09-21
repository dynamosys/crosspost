# Crosspost

Crosspost shares a Drupal site's content to social platforms. It shares a
post only once the page's public address answers, and it keeps a record per
post and platform, so nothing goes out twice and nothing links to a page
that is not there yet. That makes it suitable for static and decoupled
sites, where saving in the CMS does not mean the page is public.

**Status: early development.** Connecting accounts, the settings, the Share
tab, the log, sharing by itself, and the Bluesky and Mastodon adapters work
and are tested. The other platforms are not written yet. Do not install it
on a production site.

## What it is, and is not

Crosspost is middleware between your site and the platforms. Which platforms
to connect, and whether to pay for one that charges for posting access, is
the site owner's decision. The module ranks no platform. Each platform's
adapter carries a setup guide inside the module: what to create on the
platform, what it reviews, what it costs, what a post there can carry, and
the steps. A platform's refusal is shown in its own words and is not worked
around.

It is not a login module. It does not sign visitors in with social accounts.

Your site uses your own platform apps and keys. The module has no service,
account or subscription of its own.

## Platforms

Working: Bluesky, Mastodon. Planned: Facebook Page, Instagram, LinkedIn,
Reddit, Threads, X. A platform can hold several accounts or pages. Other
modules can add platforms: an adapter is a plugin.

## Using it

1. **Connections** (Configuration, Web services, Crosspost): open a
   platform's guide, follow its steps, press Connect. Secrets are kept in
   the [Key](https://www.drupal.org/project/key) module; Crosspost stores
   only which key to use.
2. **Settings**: tick the content types that get a Share tab. On a static
   or decoupled site, enter the public address of the site. Per type, choose
   the tag field that suggests hashtags, the accounts that start ticked, and
   whether a newly published page waits for a person or is shared by itself.
3. **Share tab** on a page: write the words once, tick the accounts, press
   the button. A page that is not public yet waits until its address
   answers. The log under the form shows where the page went and what each
   platform said.
4. Waiting pages go out when cron runs. A deploy pipeline can run
   `drush crosspost:share` right after the deploy instead.

## Requirements

Drupal 10.3 or later, or Drupal 11. PHP 8.1 or later. The Key module.

## Writing an adapter

An adapter is a class in `src/Plugin/CrosspostAdapter` with the
`#[CrosspostAdapter]` attribute, extending
`Drupal\crosspost\Adapter\AdapterBase`. It provides:

- `guide()`: the setup guide, checked against the platform's documentation.
- `limits()`: what one post may carry.
- `credentialFields()`: what it needs to know to connect an account.
- `identify()`: asks the platform whose credentials these are.
- `post()`: posts a message and returns the platform's answer as a `Result`.

`HttpAdapterBase` helps with platforms reached over HTTP. See
`src/Plugin/CrosspostAdapter/Mastodon.php` for a short real adapter, and
`tests/modules/crosspost_test` for one that posts nowhere.

## Development

The repository works with
[ddev-drupal-contrib](https://github.com/ddev/ddev-drupal-contrib):

```
ddev config --project-type=drupal11 --docroot=web --php-version=8.3 --project-name=crosspost
ddev add-on get ddev/ddev-drupal-contrib
ddev start && ddev poser && ddev symlink-project
ddev phpunit
ddev phpcs
```

The plan is in [docs/PLAN.md](docs/PLAN.md).

## Maintainers

Built and supported by [Dynamosys](https://www.drupal.org/dynamosys).

## License

GPL-2.0-or-later, like Drupal.
