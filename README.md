# Mautic ALTCHA Plugin

Adds a self-hosted, privacy-friendly **ALTCHA** (proof-of-work) CAPTCHA field
to Mautic forms, built the same way as
[FireMultimedia's Multi-CAPTCHA bundle](https://github.com/FireMultimedia/mautic-multi-captcha-bundle)
(hCaptcha / reCAPTCHA / Turnstile) so it slots into Mautic exactly like those
providers - but without ever calling a third-party API.

## Why ALTCHA is different from hCaptcha/reCAPTCHA/Turnstile

The other CAPTCHA plugins all POST the visitor's token to a third party
(`https://api.hcaptcha.com/siteverify`, Google, Cloudflare) to find out if the
submission was human. **Plain, self-hosted ALTCHA never does this.** Instead:

1. Mautic generates a small proof-of-work puzzle (a random salt + max number)
   and signs it with an HMAC secret that only your server knows.
2. The visitor's browser (the `<altcha-widget>` JS component) brute-forces
   the answer - a cheap operation for a real browser, a deliberately
   wasteful one to run at scale for a spam bot.
3. On submit, Mautic re-checks the HMAC signature and recomputes the hash
   locally. No network call, no cookies, no third-party tracking, GDPR
   friendly by design.

That's also why the ALTCHA field has no "explicit consent" toggle like the
other providers: there's no third-party script or cookie to consent to.

This plugin also supports **ALTCHA Sentinel** as an optional upgrade - see
below.

### Self-hosted vs. Sentinel, at a glance

| | Self-hosted (default) | ALTCHA Sentinel |
|---|---|---|
| Who issues the challenge | Mautic, locally | A Sentinel instance (self-hosted or ALTCHA Cloud) |
| Who verifies the submission | Mautic, locally | Mautic, locally (checks Sentinel's signature) |
| Outbound requests from Mautic | None | None (verification is a local signature check, not an API call) |
| Extra protection | Proof-of-work only | Proof-of-work + Sentinel's Adaptive Captcha, Threat Intelligence, Classifier, rate limiting, etc. |
| What you configure | One HMAC secret | Sentinel domain + API Key + API Key Secret |
| Cost | Free | Depends on your Sentinel plan/hosting |

If you fill in **both**, this plugin prefers Sentinel.

## Installation

1. Copy this `MauticAltchaBundle` folder into `plugins/` in your Mautic root
   (so the path is `plugins/MauticAltchaBundle/...`), **or** publish it as a
   Composer package and run:
   ```
   composer require druidfi/mautic-altcha-bundle
   ```
2. Make sure `altcha-org/altcha` (^2.0) is installed - it's declared as a
   dependency in `composer.json`, so a plain `composer install` inside your
   Mautic root will pull it in.
3. Clear the cache:
   ```
   php bin/console cache:clear
   ```
4. Go to **Settings → Plugins**, click **Install/Upgrade Plugins**. You
   should see a new "ALTCHA" plugin appear.

## Configuration

Go to **Settings → Plugins → ALTCHA**. You'll see four fields; fill in
*either* the first one, *or* the last three - not necessarily all four.

### Option A: Self-hosted (just an HMAC secret)

Fill in **HMAC Secret**. This is *not* an API key - it never leaves your
server. Generate a long, random string, for example:

```
openssl rand -hex 32
```

Treat it like any other application secret (don't commit it, don't reuse it
across environments). If it ever leaks, anyone who has it could forge valid
"human" submissions - though even then they'd only bypass the CAPTCHA, not
gain any other access.

Leave the three Sentinel fields blank.

### Option B: ALTCHA Sentinel

If you run (or subscribe to) [ALTCHA Sentinel](https://altcha.org/docs/v2/sentinel/),
fill in these three fields instead and leave **HMAC Secret** blank:

1. **Sentinel Domain / Base URL** - your Sentinel instance's base URL, e.g.
   `https://sentinel.example.com` for a self-hosted install, or your ALTCHA
   Cloud region's URL. No trailing slash, no path - Mautic appends
   `/v1/challenge` itself when it builds the widget URL.
2. **Sentinel API Key** - from Sentinel's **API Keys** admin section
   (`https://<your-sentinel-domain>/admin` → Configuration → API Keys). It
   looks like `key_...`. Create a Security Group first if you haven't (that's
   where you configure Sentinel's actual anti-spam rules), then create an API
   Key bound to it.
3. **Sentinel API Key Secret** - shown alongside the API Key when you create
   it. Unlike the API Key itself, this value never leaves your server.

With Sentinel configured, the widget fetches its challenge directly from
your Sentinel instance instead of from Mautic, and gains Sentinel's extra
features (Adaptive Captcha, Threat Intelligence, Classifier, rate limiting,
etc., depending on how your Security Group is configured). The
**Complexity** and **Challenge expiry** field settings (see below) are
ignored in this mode, since Sentinel controls its own difficulty.

### Add the field to a form

Open any Mautic form in the builder and drag in the **ALTCHA** field. Under
its **Properties** tab you can set:

| Setting | What it does |
|---|---|
| **Complexity** | Low / Medium / High - how much proof-of-work the visitor's browser has to compute. Higher = more spam-resistant but slightly slower, especially on old phones. |
| **Challenge expiry** | How long (seconds) a generated challenge stays valid. If the visitor takes longer than this to submit, they solve a new one automatically. |
| **Start solving** | *On page load* (recommended, default) solves the challenge in the background as the visitor reads the form, so submission feels instant. *On submit* solves at submit time and then auto-submits — avoid this if you want the visitor to click Submit explicitly. *Off* requires a manual checkbox click. |
| **Floating widget** | Show the widget as a small floating badge instead of an inline box. |
| **Hide footer / Hide ALTCHA logo** | Cosmetic toggles for the widget chrome. |

### Advanced: Widget JS URL

By default the widget JavaScript is loaded from the jsDelivr CDN
(`https://cdn.jsdelivr.net/npm/altcha@3/dist/main/altcha.js`). If you need to
serve it from your own infrastructure (strict CSP, GDPR environments, or
offline installs), set **Widget JS URL** in the plugin settings to a
self-hosted copy and leave blank to revert to the CDN default.

The field will only appear in the builder once step 1 (HMAC secret) is done -
this mirrors how the hCaptcha/reCAPTCHA/Turnstile fields hide themselves
until their keys are configured.

## How it works internally (for developers)

- `Controller/ChallengeController.php` - a **public, unauthenticated**,
  invokable endpoint (`/altcha/challenge`) that issues a fresh challenge on
  every single request. This exists because Mautic caches an entire form's
  *rendered HTML* (`FormModel::generateHtml()`/`getContent()`) and only
  regenerates it when the form is saved - not on every page view. A
  challenge embedded directly into that HTML would be reused by every
  visitor until the form's next save. Pointing the widget at this URL
  instead sidesteps that caching entirely, the same way Sentinel mode
  already works.
- `Integration/AltchaIntegration.php` - registers the "ALTCHA" integration
  with `getAuthenticationType() === 'none'`. Deliberately returns an
  **empty** `getRequiredKeyFields()` - that method's name is literal, and
  Mautic renders every field it lists as mandatory. Since no single field
  here is *always* needed (only "the self-hosted secret, or the full
  Sentinel set"), all credential fields are added as plain, optional fields
  via `appendToForm()` for the "keys" tab, and `isConfigured()` is
  overridden with the actual "one set or the other" logic. The settings form
  also shows an active-mode badge, section dividers, a client-side "Generate
  random secret" button, and — when Sentinel credentials are saved — a live
  connectivity status note that probes the Sentinel endpoint and reports the
  result. `encryptAndSetApiKeys()` is overridden to clear Mautic's form HTML
  cache on every settings save so forms immediately pick up a new challenge
  URL.
- `Service/AltchaClient.php` - the only class that talks to the
  [`altcha-org/altcha`](https://github.com/altcha-org/altcha-lib-php)
  library. Self-hosted mode uses its **V1 API** (`AltchaOrg\Altcha\V1\*`,
  the classic flat `{algorithm, challenge, salt, signature, maxNumber}`
  challenge shape); Sentinel mode uses
  `AltchaOrg\Altcha\ServerSignature::verifyServerSignature()` to check the
  signature Sentinel embeds in the submitted payload, locally, using the
  API Key Secret. `AltchaClient::buildWidgetChallenge()` is the single
  method the Twig layer calls, and it always returns a URL - our own
  challenge endpoint in self-hosted mode, or Sentinel's in Sentinel mode.
- `Resources/views/Integration/altcha.html.twig` - renders
  `<altcha-widget challenge="...">`. In ALTCHA v3 the `challenge` attribute
  accepts either an inline JSON object **or** a URL string — the widget
  detects which it received automatically. The older `challengeurl` attribute
  (v2 and earlier) is no longer used.

## Security notes

- **Keep the `altcha-org/altcha` composer package up to date.** A signature-
  bypass advisory was published for `altcha-lib-php`
  ([GHSA-82w8-65qw-gch6](https://github.com/altcha-org/altcha-lib-php/security/advisories/GHSA-82w8-65qw-gch6)).
  This plugin pins `^2.0`, which includes the fix; run
  `composer update altcha-org/altcha` periodically regardless.
- The HMAC secret and the Sentinel API Key Secret are stored encrypted at
  rest via Mautic's own `mautic.helper.encryption`, and masked in the
  settings UI. The Sentinel API Key itself is *not* masked, since it's
  designed to be sent to the visitor's browser as part of the widget's
  challenge URL and isn't sensitive on its own - only its Secret is.

## Compatibility

Mautic 5, 6, and 7 (same `AppVersion`-based branch selection as the
reference Multi-CAPTCHA bundle). PHP 8.1+.

## License

GPL-3.0, consistent with the reference plugin this was modeled on.
