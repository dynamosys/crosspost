# Crosspost

Crosspost shares a Drupal site's content to social platforms. It shares a
post only once the page's public address answers, and it keeps a record per
post and platform, so nothing goes out twice and nothing links to a page
that is not there yet. That makes it suitable for static and decoupled
sites, where saving in the CMS does not mean the page is public.

**Status: early development.** The adapter framework exists. The screens,
the record and the platform adapters do not yet. Do not install it on a
production site.

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

## Planned platforms

Bluesky, Facebook Page, Instagram, LinkedIn, Mastodon, Reddit, Threads, X.
Other modules can add platforms: an adapter is a plugin.

## Requirements

Drupal 10.3 or later, or Drupal 11. PHP 8.1 or later.

## Writing an adapter

An adapter is a class in `src/Plugin/CrosspostAdapter` with the
`#[CrosspostAdapter]` attribute, extending
`Drupal\crosspost\Adapter\AdapterBase`. It provides:

- `guide()`: the setup guide.
- `limits()`: what one post may carry.
- `identify()`: asks the platform whose credentials these are.
- `post()`: posts a message and returns the platform's answer as a `Result`.

See `tests/modules/crosspost_test` for the smallest working example.

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

## Licence

GPL-2.0-or-later, like Drupal.
