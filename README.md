# Rules Module — Patriam fork

Patriam's maintained fork of Coldfire's Rules module for NamelessMC 2.2.5. The original module and
support information are available from [Coldfire Design](https://coldfiredzn.com).

## What this fork changes

Rules 1.9.0 replaced the generic Skyfall and Bedwars fresh-install samples with eight short,
StaffCP-editable Patriam sections:

- Community
- Chat
- Roleplay
- Building & Land
- Gameplay & Fair Play
- Economy & Trading
- PvP & Conflict
- Accounts & Security

Rules 1.9.1 adds the third home action. The buttons are **Player Report** (`/cases/new/report`),
**Ban Appeal** (`/cases/new/appeal`), and **Bug Report** (`/cases/new/bug`). The sample Bans button
is not installed. Those routes belong to Patriam's separate cases module; this repository only
supplies the Rules page links.

The released database table is misspelled `rules_catagories`. It remains unchanged so an existing
installation and third-party integrations continue to work. New PHP and bundled-template variables
use `categories`; the legacy `CATAGORIES` Smarty variable is still supplied for custom templates.

## Fresh installation

Copy the contents of `upload/` into the NamelessMC root, then install and enable **Rules** from
StaffCP -> Modules. Configure the page at StaffCP -> Rules.

`onInstall()` creates any absent base table and uses the same idempotent content migration as an
upgrade. `onEnable()` invokes that migration again, so re-enabling is safe and a repeat run is a
no-op.

## Upgrading an installed site

Deploying files does not call `onEnable()` for a module which is already enabled. Use the bundled
CLI entry point after deploying 1.9.1. It is dry-run-only unless `--apply` is present:

```sh
docker exec nameless-php-1 php /data/modules/Rules/cli/migrate-patriam.php --self-test
docker exec nameless-php-1 php /data/modules/Rules/cli/migrate-patriam.php
docker exec nameless-php-1 php /data/modules/Rules/cli/migrate-patriam.php --apply
docker exec nameless-php-1 php /data/modules/Rules/cli/migrate-patriam.php
```

The final dry run should report that no migration is needed. `--root=/path/to/nameless` is available
when the NamelessMC root is not `/data`.

### Migration safety boundary

The upgrader changes or removes an existing row only when all of its fields exactly match a shipped
default. The recognized upgrade sources are:

- the Skyfall introduction;
- the Bedwars and Chat samples;
- the external Hypixel Player Report and Ban Appeal buttons; and
- the LemonCloud Bans button; and
- the Rules 1.9.0 Patriam introduction.

It adds a missing Patriam category by category name, but never overwrites a row already using that
name. It ensures the three exact Patriam name-and-route button pairs exist. If a customized button
uses the same name with another route, the custom row is retained and the canonical button is added
beside it. This deliberately prefers an obvious duplicate for staff to reconcile over silently
destroying a custom destination.

Every apply starts its transaction before taking full locking reads of all three tables, builds a
fresh plan from those locked rows, applies it, re-reads the tables, and must produce an empty second
plan before it commits. A StaffCP edit made after a dry-run preview is therefore preserved or waits
for the migration; a stale row-id plan is never applied.
