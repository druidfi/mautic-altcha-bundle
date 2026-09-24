<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Service;

use AltchaOrg\Altcha\V1\Altcha;
use AltchaOrg\Altcha\V1\ChallengeOptions;
use AltchaOrg\Altcha\V1\Hasher\Algorithm;

use AltchaOrg\Altcha\ServerSignature;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;

use MauticPlugin\MauticAltchaBundle\Integration\AltchaIntegration;

/**
 * <h1>Class AltchaClient</h1>
 *
 * Supports two mutually exclusive ways of running ALTCHA:
 *
 * 1. Self-hosted (default): Mautic generates and signs its own challenge
 *    with a local HMAC secret, and verifies the solution locally too. No
 *    outbound HTTP request is ever made - this is what makes plain ALTCHA
 *    privacy-friendly (no cookies, no external requests, no tracking).
 *
 * 2. ALTCHA Sentinel: challenges are issued directly by a Sentinel instance
 *    (self-hosted or ALTCHA Cloud) rather than by Mautic. The widget is
 *    pointed at Sentinel's own `/v1/challenge` endpoint, and Mautic only
 *    needs to verify the "server signature" Sentinel embeds in the
 *    submitted payload - which is itself a *local*, network-free HMAC
 *    check using the API key's secret, exactly as ALTCHA's own docs
 *    recommend (see https://altcha.org/docs/v2/server-integration/).
 *
 * If both a self-hosted HMAC secret and Sentinel credentials are filled in,
 * Sentinel takes precedence, since it's the more capable of the two.
 *
 * IMPORTANT: in self-hosted mode, the widget's "challengeurl" attribute is a
 * URL pointing at {@see \MauticPlugin\MauticAltchaBundle\Controller\ChallengeController}
 * - it is NOT an inline JSON challenge embedded directly into the form's
 * HTML. That used to be the design here, but Mautic caches an entire
 * form's rendered HTML in `forms.cached_html`
 * (`Mautic\FormBundle\Model\FormModel::generateHtml()`/`getContent()`) and
 * only regenerates it when the form itself is saved - not on every page
 * view or every time preview is opened. A challenge embedded directly in
 * that cached HTML would be reused by every single visitor until the form
 * is next saved, and would eventually expire while still being served -
 * which is exactly what caused "Verification failed. Try again later." to
 * appear on every attempt. Fetching the challenge from a URL at the moment
 * the widget actually loads sidesteps that caching layer entirely - it's
 * the same approach Sentinel mode already uses, and what ALTCHA's own docs
 * describe as the standard "server integration" pattern for any
 * dynamically-cached page.
 *
 * @package MauticPlugin\MauticAltchaBundle\Service
 *
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
class AltchaClient {

    public const DEFAULT_MAX_NUMBER = 100000;
    public const DEFAULT_EXPIRE_SECONDS = 600;

    /** Maps the field's "complexity" property to a proof-of-work maxNumber. Self-hosted mode only - Sentinel controls its own difficulty. */
    private const COMPLEXITY_MAP = [
        "low"    => 50000,
        "medium" => 100000,
        "high"   => 400000
    ];

    public const DEFAULT_SCRIPT_URL = "https://cdn.jsdelivr.net/npm/altcha@3/dist/main/altcha.js";

    private ?string $hmacSecret = null;

    private ?string $sentinelDomain = null;
    private ?string $sentinelApiKey = null;
    private ?string $sentinelApiSecret = null;

    private ?string $scriptUrl = null;

    /**
     * <h2>AltchaClient constructor.</h2>
     *
     * @param IntegrationHelper       $integrationHelper
     * @param UrlGeneratorInterface   $router
     */
    public function __construct(
        IntegrationHelper $integrationHelper,
        private readonly UrlGeneratorInterface $router
    ) {
        $integrationObject = $integrationHelper->getIntegrationObject(AltchaIntegration::INTEGRATION_NAME);

        if($integrationObject instanceof AbstractIntegration) {
            $keys = $integrationObject->getKeys();

            $this->hmacSecret = $keys["hmac_secret"] ?? null;

            $this->sentinelDomain    = $keys["sentinel_domain"] ?? null;
            $this->sentinelApiKey    = $keys["sentinel_api_key"] ?? null;
            $this->sentinelApiSecret = $keys["sentinel_api_secret"] ?? null;

            $this->scriptUrl = !empty($keys["script_url"]) ? (string) $keys["script_url"] : null;
        }
    }

    /**
     * <h2>usesSentinel</h2>
     *
     * @return bool True when all three Sentinel credentials are present. Sentinel takes priority over self-hosted mode when both are configured.
     */
    public function usesSentinel(): bool {
        return !empty($this->sentinelDomain) && !empty($this->sentinelApiKey) && !empty($this->sentinelApiSecret);
    }

    /**
     * <h2>hasSelfHostedSecret</h2>
     *
     * @return bool
     */
    public function hasSelfHostedSecret(): bool {
        return !empty($this->hmacSecret);
    }

    /**
     * <h2>isConfigured</h2>
     *
     * @return bool True if either mode has everything it needs.
     */
    public function isConfigured(): bool {
        return $this->usesSentinel() || $this->hasSelfHostedSecret();
    }

    /**
     * <h2>getScriptUrl</h2>
     *
     * Returns the URL to load the ALTCHA widget JavaScript from. Falls back
     * to the jsDelivr CDN when no custom URL has been configured.
     *
     * @return string
     */
    public function getScriptUrl(): string {
        return $this->scriptUrl ?? self::DEFAULT_SCRIPT_URL;
    }

    /**
     * <h2>buildSentinelChallengeUrl</h2>
     *
     * Builds the URL the widget's "challengeurl" attribute should point at so
     * the *browser* fetches the challenge directly from Sentinel. Mautic
     * itself never talks to Sentinel to issue a challenge - only to verify
     * the resulting payload's signature (and even that check is local, see
     * {@see verify()}).
     *
     * @return string
     */
    public function buildSentinelChallengeUrl(): string {
        $domain = rtrim((string) $this->sentinelDomain, "/");

        return $domain . "/v1/challenge?apiKey=" . rawurlencode((string) $this->sentinelApiKey);
    }

    /**
     * <h2>createChallenge</h2>
     *
     * Self-hosted mode only. Generates a brand new signed challenge. Must be
     * called every time the form is rendered (never cache/reuse a challenge)
     * so that each page load gets its own proof-of-work puzzle and signed
     * nonce.
     *
     * @param int $maxNumber     Upper bound of the random number the widget has to brute-force. Higher = harder/slower.
     * @param int $expireSeconds How long, in seconds, the challenge stays valid for.
     *
     * @return array{algorithm: string, challenge: string, salt: string, signature: string, maxNumber: int}
     */
    public function createChallenge(int $maxNumber = self::DEFAULT_MAX_NUMBER, int $expireSeconds = self::DEFAULT_EXPIRE_SECONDS): array {
        $altcha = new Altcha((string) $this->hmacSecret);

        $challenge = $altcha->createChallenge(new ChallengeOptions(
            algorithm:  Algorithm::SHA256,
            maxNumber:  $maxNumber,
            expires:    new \DateTimeImmutable("+{$expireSeconds} seconds")
        ));

        return [
            "algorithm" => $challenge->algorithm,
            "challenge" => $challenge->challenge,
            "salt"      => $challenge->salt,
            "signature" => $challenge->signature,
            // NOTE: this key MUST be camelCase. The altcha-lib-php v1.1.0
            // release notes are explicit that widgets >= v1.4.0 (we load
            // the current v3 widget) require "maxNumber", not "maxnumber" -
            // an older/lowercase key here makes the widget treat the
            // challenge as malformed and fail immediately client-side with
            // its own generic error state, before ever reaching our server
            // (this was the cause of "Verification failed. Try again
            // later." always appearing, including in preview mode).
            "maxNumber" => $challenge->maxNumber
        ];
    }

    /**
     * <h2>createChallengeForComplexity</h2>
     *
     * Convenience wrapper around {@see createChallenge()} that translates the
     * human-friendly "low"/"medium"/"high" field setting into a maxNumber.
     * Called by {@see \MauticPlugin\MauticAltchaBundle\Controller\ChallengeController}
     * on every single request it receives, so every visitor gets a genuinely
     * fresh challenge regardless of any HTML-level caching upstream.
     *
     * @param string $complexity     One of "low", "medium", "high".
     * @param int    $expireSeconds
     *
     * @return array|null Null when the integration has no HMAC secret configured yet.
     */
    public function createChallengeForComplexity(string $complexity, int $expireSeconds = self::DEFAULT_EXPIRE_SECONDS): ?array {
        if(!$this->hasSelfHostedSecret())
            return null;

        $maxNumber = self::COMPLEXITY_MAP[$complexity] ?? self::COMPLEXITY_MAP["medium"];

        return $this->createChallenge($maxNumber, $expireSeconds);
    }

    /**
     * <h2>buildSelfHostedChallengeUrl</h2>
     *
     * Builds the URL the widget's "challengeurl" attribute should point at
     * in self-hosted mode - our own {@see \MauticPlugin\MauticAltchaBundle\Controller\ChallengeController},
     * not an inline JSON blob. See the class docblock for why this matters.
     *
     * @param string $complexity
     * @param int    $expireSeconds
     *
     * @return string
     */
    public function buildSelfHostedChallengeUrl(string $complexity, int $expireSeconds): string {
        return $this->router->generate("mautic_altcha_challenge", [
            "complexity" => $complexity,
            "expire"     => $expireSeconds
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * <h2>buildWidgetChallenge</h2>
     *
     * The single entry point the Twig extension calls. Returns whatever
     * string the <altcha-widget>'s "challengeurl" attribute should contain -
     * a URL, either to our own challenge endpoint (self-hosted mode) or to
     * Sentinel's (Sentinel mode). In both cases the widget fetches a fresh
     * challenge itself, live, the moment it actually loads in a visitor's
     * browser - it is never baked statically into the form's HTML.
     *
     * @param string $complexity     One of "low", "medium", "high". Ignored in Sentinel mode - Sentinel controls its own difficulty via its Security Group settings.
     * @param int    $expireSeconds  Ignored in Sentinel mode.
     *
     * @return string|null Null when neither mode is configured yet.
     */
    public function buildWidgetChallenge(string $complexity = "medium", int $expireSeconds = self::DEFAULT_EXPIRE_SECONDS): ?string {
        if($this->usesSentinel())
            return $this->buildSentinelChallengeUrl();

        if(!$this->hasSelfHostedSecret())
            return null;

        return $this->buildSelfHostedChallengeUrl($complexity, $expireSeconds);
    }

    /**
     * <h2>verify</h2>
     *
     * Verifies the base64-encoded payload the <altcha-widget> submits in its
     * hidden "altcha" input.
     *
     * - Self-hosted mode: HMAC signature + expiry + proof-of-work check, all
     *   done locally with the HMAC secret. No network call.
     * - Sentinel mode: the payload contains a "server signature" that
     *   Sentinel itself attached (client-side, when the browser talked to
     *   Sentinel to fetch/solve the challenge). We verify *that* signature
     *   locally using the API key's secret as the HMAC key - this is the
     *   approach ALTCHA's own docs recommend, and it means Mautic still
     *   never has to make an outbound request just to validate a
     *   submission.
     *
     * @param string $payload
     *
     * @return bool
     */
    public function verify(string $payload): bool {
        if("" === trim($payload))
            return false;

        if($this->usesSentinel()) {
            try {
                $result = ServerSignature::verifyServerSignature($payload, (string) $this->sentinelApiSecret);

                return (bool) $result->verified;
            } catch(\Throwable $exception) {
                return false;
            }
        }

        if(!$this->hasSelfHostedSecret())
            return false;

        $altcha = new Altcha((string) $this->hmacSecret);

        try {
            return $altcha->verifySolution($payload, true);
        } catch(\Throwable $exception) {
            return false;
        }
    }

}
