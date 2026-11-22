# The bundled wp-email-senders package

MailOptin sends through any SMTP server, Gmail and Microsoft 365 (free), and Postmark, Amazon SES, SendGrid, Mailgun
and Brevo (Premium), using [mailoptin/wp-email-senders](https://github.com/mailoptin/wp-email-senders). FuseWP uses the
same package.

This plugin doesn't install the package with Composer. A copy of it lives in `src/EmailSenders/`, with its namespace
renamed from `MailOptin\EmailSenders` to `MailOptin\Core\EmailSenders`. The copy is committed like any other code
and autoloaded by the plugin's normal `MailOptin\Core\` → `src/` rule.

## Why a renamed copy

- **No clashes with FuseWP.** A site can run MailOptin and FuseWP together, each with its own version of the
  package. With the same class names, PHP would load whichever plugin's copy came first and the other plugin would
  run a version it wasn't built for. Renamed, the two copies never meet.
- **No build changes.** The edition builds (mailoptin-bucket) don't rename vendor code, so a Composer dependency
  couldn't be renamed there without adding php-scoper or Strauss to every build.
- **One copy for MailOptin.** The free plugin ships it, and MailOptin Premium uses this copy for its providers.

The package registers nothing in WordPress (no options, hooks, routes or tables). Everything WordPress-facing lives in
`src/Sending/`: credentials, sign-in, the queue sender, sending the site's emails and the email log.

## The rule

**Never edit `src/EmailSenders/` by hand.** Change the package, then copy it in. Every file there starts with
"Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there." The next sync replaces the
whole folder, so a hand edit is lost, and `--check` reports it.

## Updating the package

1. **Change it in the package repo.** Edit `mailoptin/wp-email-senders`, run its tests (`composer install && vendor/bin/phpunit`), and merge
   once CI passes on PHP 7.4 to 8.4.
2. **Tag a version** so each plugin's copy names a release:

   ```
   cd wp-email-senders
   git checkout main && git pull
   git tag v1.1.0 && git push origin v1.1.0
   ```

3. **Copy it in:**

   ```
   cd wp-email-senders && git checkout v1.1.0
   cd ../core
   php bin/sync-email-senders.php ../wp-email-senders
   git diff --stat src/EmailSenders
   ```

   The script:
   - empties `src/EmailSenders/`, so files removed from the package go here too;
   - copies every PHP file from the package's `src/`;
   - renames the namespace and adds the header line;
   - writes `src/EmailSenders/VERSION` with the tag, the commit and the time.

   It warns if the package has uncommitted changes, because the copy then matches no commit.

4. **Test.** Run the sending tests with WordPress's test suite:

   ```
   vendor/bin/phpunit --no-configuration --bootstrap tests/bootstrap.php tests/Sending
   ```

5. **Commit** `src/EmailSenders/` with a message naming the version, for example "Update wp-email-senders to v1.1.0".

FuseWP updates on its own schedule, and the two plugins never need to update together.

## Checking the copy hasn't drifted

```
cd wp-email-senders && git checkout <commit from src/EmailSenders/VERSION>
cd ../core
php bin/sync-email-senders.php ../wp-email-senders --check
```

`--check` changes nothing. It compares `src/EmailSenders/` with what the script would copy from that checkout.
- **Exit 0:** they match.
- **Exit 1:** they don't. It lists each file that is missing, different or extra; usually a hand edit, or a sync from
  a different version.

The check compares against whatever the package checkout holds, so check out the commit recorded in `VERSION` first.
It's suitable for CI.

## Changing the package safely

Whatever this plugin calls is the package's public interface. Changing it breaks this plugin at the next sync, and
FuseWP at its next update:
- `Senders::make()` and `Senders::ids()`
- `SenderInterface`
- `Message::make()`
- the `SendResult` statuses and its `throttled` and `retry_after` fields
- `WebhookSetupInterface::install_webhook()`
- `Webhooks::make()`, `Webhooks\Request`, the `Webhooks\Result` fields and the `DeliveryEvent` types and fields

Prefer adding to changing. When something has to change, give it a new major version, say so in the package's PR, and
update `src/Sending/` in the same commit as the sync.

## Where it's used here

| File | Uses |
| --- | --- |
| `src/Sending/SendingStore.php` | `Senders::make()` for SMTP, Gmail and Microsoft 365, `Fields`, `ConnectionResult`, `OAuth\Token` |
| `src/Sending/Sending.php` | `OAuth\Provider` (sign-in), `SiteMailer`, `Message::make()` (test email), `SendResult` |
| `src/Sending/ServiceSender.php` | `Message::make()` and `SendResult` → the send queue's results |
| `src/Sending/SendingScreen.php` | `Fields::oauth()` (setup steps), `SiteMailer::current()` |

MailOptin Premium uses this copy too, for Postmark, Amazon SES, SendGrid, Mailgun and Brevo: `Senders::make()`,
`WebhookSetupInterface` and `Webhooks` in its `src/Sending/`.

## Working on the package with a real site

The package is its own repo, so you develop and push there; this plugin only ever gets a copy.

1. **Clone it next to this plugin**, so the relative paths above work:

   ```
   wp-content/plugins/
     mailoptin/            ← this repo (mailoptin/core)
     wp-email-senders/     ← git clone git@github.com:mailoptin/wp-email-senders.git
   ```

   It isn't a WordPress plugin (no plugin header), so WordPress ignores the folder.

2. **Branch in the package**, make the change and add a test there. Its tests need no WordPress:
   `composer install && vendor/bin/phpunit`. The SMTP tests start a local server and need Python's aiosmtpd
   (`pip install aiosmtpd`).

3. **Point the site at your clone.** In `wp-config.php`:

   ```php
   define('MAILOPTIN_EMAIL_SENDERS_DIR', __DIR__ . '/wp-content/plugins/wp-email-senders');
   ```

   MailOptin (and Premium) now run the clone's code directly, so each edit shows on the next page load, with no sync.
   Each `MailOptin\Core\EmailSenders\X` becomes the clone's `MailOptin\EmailSenders\X` under MailOptin's name
   (`src/Sending/PackageSource.php`). Remove the constant, or set it to `false`, and the bundled copy in
   `src/EmailSenders/` is used again. A path without `src/Senders.php` is ignored, with an admin notice.

   Development only. Don't set it on a site where FuseWP also loads a development clone: the two would share the
   package's own class names.

4. **Push the package branch and open its PR** from the package folder, as with any repo.

5. **Once it's merged**, sync from the merged commit or tag (Updating the package, above), so `VERSION` names a real
   commit, commit `src/EmailSenders/` here, and remove the constant to check the bundled copy behaves the same.
