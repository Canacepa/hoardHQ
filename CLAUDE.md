# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Symfony 8.0 on PHP >= 8.4, Doctrine ORM 3, Twig, Symfony UX (Turbo, Live Components, Twig Components, Stimulus), AssetMapper (no Node/webpack), Tailwind v4 via `symfonycasts/tailwind-bundle`, Mercure hub for Turbo broadcasts.

**Database mismatch:** `.env` points `DATABASE_URL` at a local **MySQL 8** (`127.0.0.1:3306/HoardHQ`), but `compose.yaml` only defines **Postgres** (a Flex recipe default) plus Mercure. Migrations are generated against whichever platform is configured, so use the MySQL from `.env` (or override in `.env.local`) and don't rely on `docker compose` for the DB. Mail is `null://null` in dev.

A parent `D:\Code\CLAUDE.md` also applies (Ruflo/claude-flow conventions); this project is a plain Symfony app in the `hoardHQ` subdirectory.

## Commands

```bash
docker compose up -d mercure              # Mercure hub only; DB is the MySQL from .env
composer install
php bin/console doctrine:migrations:migrate
php bin/console tailwind:build --watch    # compile assets/styles/app.css (required for styling)
symfony serve                             # or: php -S 127.0.0.1:8000 -t public
php bin/console importmap:install         # after changing importmap.php

php bin/console make:migration            # after entity changes
vendor/bin/phpunit                        # config: phpunit.dist.xml
vendor/bin/phpunit tests/Path/FooTest.php --filter testName   # single test
```

No `tests/` content exists yet beyond `tests/bootstrap.php`. No linter is configured.

## Architecture

Collection-hoarding app: users own **Collections**, which hold **Items** and are tagged with **Categories**.

- Entities (`src/Entity`) are named in the plural (`Collections`, `Categories`, `Items`) — note the class `Collections` is not the Doctrine `Collection` interface it imports. `Collections` has an owner plus separate `contributors` and `viewers` many-to-many user sets (join tables `collections_contributors` / `collections_viewers`); the permission model is intended to be owner > contributor > viewer.
- Authentication: form login (`SecurityController`), firewall `main`, user provider keyed on `username` (not email). Registration + email verification via `symfonycasts/verify-email-bundle` (`RegistrationController`, `Security/EmailVerifier`). `access_control` in `config/packages/security.yaml` is empty — authorization is done per-action with `#[IsGranted('ROLE_USER')]` on controllers, so new routes are public unless annotated.
- `Collections` and `Items` carry `#[Broadcast]`; `templates/broadcast/*.stream.html.twig` render the Turbo Streams published through Mercure on create/update/remove. Those templates are still the generated stubs.
- UI: `templates/base.html.twig`, reusable Twig Components in `templates/components/` (`Button`, `Navbar`, `Alert/*`, from `ux-toolkit`/shadcn styling; Tailwind merging via `twig-tailwind-extra`). Stimulus controllers live in `assets/controllers/`; JS deps are pinned in `importmap.php`, not npm.

## Work in progress

`CollectionsController` is partially built: `show`/`edit` render templates that don't exist yet (`collections/show`, `collections/edit`), and `newCollection` doesn't redirect after a successful flush. Several controllers/entities/templates have uncommitted changes — check `git status` before assuming a feature is finished.
