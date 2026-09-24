<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Integration;

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\MauticAltchaBundle\Service\AltchaClient;

use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

/**
 * <h1>Class AltchaIntegration</h1>
 *
 * ALTCHA is self-hosted by default: there is no third-party API to
 * authenticate against, so authentication type is "none". Two ways of
 * running it are supported side by side in the same settings form:
 *
 * - Self-hosted: just an "hmac_secret" that Mautic uses to sign/verify
 *   challenges on its own, with no outbound requests at all.
 * - ALTCHA Sentinel: a "sentinel_domain" (your Sentinel instance's base
 *   URL), a "sentinel_api_key", and a "sentinel_api_secret". Sentinel
 *   issues challenges directly to the visitor's browser; Mautic only
 *   verifies the resulting server signature, which is still a local check
 *   (see {@see \MauticPlugin\MauticAltchaBundle\Service\AltchaClient}).
 *
 * IMPORTANT: none of these four fields are added via getRequiredKeyFields().
 * That method's name is literal - Mautic renders every field it returns as
 * mandatory (both an HTML "required" attribute and, in some versions, a
 * NotBlank-style check), which would make it impossible to save the form
 * with only the self-hosted secret filled in (or only the Sentinel fields).
 * Since no single field here is *always* needed - only "one full set or
 * the other" - all four are instead added as plain, optional fields via
 * appendToForm() for the "keys" form area, each with 'required' => false.
 * {@see isConfigured()} enforces the actual "one set or the other" rule at
 * runtime instead.
 *
 * Every method overridden here declares an explicit return type matching
 * AbstractIntegration's own current (typed) signatures - PHP treats
 * omitting a return type in a child method as a fatal error if the parent
 * declares one, so these aren't cosmetic.
 *
 * @package MauticPlugin\MauticAltchaBundle\Integration
 *
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
class AltchaIntegration extends AbstractIntegration {

    public const INTEGRATION_NAME = "Altcha";

    /** {@inheritDoc} */
    public function getName(): string {
        return self::INTEGRATION_NAME;
    }

    /** {@inheritDoc} */
    public function getDisplayName(): string {
        return "ALTCHA";
    }

    /** {@inheritDoc} */
    public function getAuthenticationType(): string {
        return "none";
    }

    /**
     * <h2>getRequiredKeyFields</h2>
     *
     * Deliberately empty - see the class docblock. The four credential
     * fields are added manually via {@see appendToForm()} instead, so none
     * of them are forced to be non-empty simultaneously.
     *
     * @return array
     */
    public function getRequiredKeyFields(): array {
        return [];
    }

    /** {@inheritDoc} */
    public function getSecretKeys(): array {
        return [
            "hmac_secret",
            "sentinel_api_secret"
        ];
    }

    /**
     * <h2>isConfigured</h2>
     *
     * Either the self-hosted HMAC secret OR all three Sentinel credentials
     * must be present. See class docblock for why none go through
     * getRequiredKeyFields().
     *
     * @return bool
     */
    public function isConfigured(): bool {
        $keys = $this->getKeys();

        $sentinelReady   = !empty($keys["sentinel_domain"]) && !empty($keys["sentinel_api_key"]) && !empty($keys["sentinel_api_secret"]);
        $selfHostedReady = !empty($keys["hmac_secret"]);

        return $sentinelReady || $selfHostedReady;
    }

    /**
     * <h2>encryptAndSetApiKeys</h2>
     *
     * Clears Mautic's form HTML cache whenever settings are saved, so every
     * form immediately picks up the new challenge URL on the next page load
     * without requiring a manual SQL UPDATE. Also invalidates the cached
     * Sentinel connectivity status so the next form render rechecks.
     *
     * @param array $keys
     * @param array $settings
     */
    public function encryptAndSetApiKeys(array $keys, Integration $entity): void {
        parent::encryptAndSetApiKeys($keys, $entity);

        // Clear cached form HTML — forms regenerate it on the next page load.
        try {
            $this->em
                ->createQuery("UPDATE Mautic\\FormBundle\\Entity\\Form f SET f.cachedHtml = null")
                ->execute();
        } catch (\Throwable $e) {
            // Non-critical — forms will regenerate their cache on next load.
        }

        // Invalidate cached Sentinel status so the next form render rechecks.
        try {
            $this->cache->delete("altcha_sentinel_status");
        } catch (\Throwable $e) {}
    }

    /**
     * <h2>appendToForm</h2>
     *
     * Renders the settings form with:
     *  - An active-mode badge at the top
     *  - A "Self-hosted" section with the HMAC secret field + generate button
     *  - A "Sentinel" section with the three Sentinel credential fields
     *
     * @param mixed  $builder
     * @param array  $data
     * @param string $formArea
     */
    public function appendToForm(&$builder, $data, $formArea): void {
        if("keys" !== $formArea)
            return;

        // ── Active mode badge ───────────────────────────────────────────────
        $keys            = $this->getKeys();
        $sentinelReady   = !empty($keys["sentinel_domain"]) && !empty($keys["sentinel_api_key"]) && !empty($keys["sentinel_api_secret"]);
        $selfHostedReady = !empty($keys["hmac_secret"]);

        if($sentinelReady) {
            $badgeLabel = "✓ Active mode: ALTCHA Sentinel";
            $badgeClass = "btn btn-success btn-sm";
        } elseif($selfHostedReady) {
            $badgeLabel = "✓ Active mode: Self-hosted";
            $badgeClass = "btn btn-info btn-sm";
        } else {
            $badgeLabel = "⚠ Not configured — fill in one of the sections below";
            $badgeClass = "btn btn-warning btn-sm";
        }

        $builder->add("_mode_badge", ButtonType::class, [
            "label" => $badgeLabel,
            "attr"  => [
                "class"    => $badgeClass,
                "disabled" => "disabled",
                "style"    => "cursor:default;margin-bottom:18px;pointer-events:none;",
            ],
        ]);

        // ── Self-hosted section ─────────────────────────────────────────────
        $builder->add("_section_self_hosted", ButtonType::class, [
            "label" => "Self-hosted (no external service)",
            "attr"  => [
                "class"    => "btn btn-link btn-block text-left",
                "disabled" => "disabled",
                "style"    => "cursor:default;pointer-events:none;font-weight:bold;font-size:13px;border-bottom:2px solid #ddd;padding:0 0 6px 0;margin-bottom:10px;color:#333;",
            ],
        ]);

        $builder->add("hmac_secret", PasswordType::class, [
            "label"      => "strings.altcha.settings.hmac_secret",
            "required"   => false,
            "data"       => $data["hmac_secret"] ?? "",
            "label_attr" => ["class" => "control-label"],
            "attr"       => [
                "class"       => "form-control",
                "tooltip"     => "strings.altcha.settings.hmac_secret.notice",
                "placeholder" => "strings.altcha.settings.hmac_secret.placeholder",
            ],
        ]);

        // "Generate random secret" button — fills the HMAC field client-side.
        $builder->add("_generate_hmac", ButtonType::class, [
            "label" => "strings.altcha.settings.generate_hmac",
            "attr"  => [
                "class"   => "btn btn-default btn-sm",
                "style"   => "margin-bottom:18px;",
                "onclick" => implode("", [
                    "var a=new Uint8Array(32);",
                    "crypto.getRandomValues(a);",
                    "document.getElementById('keys_hmac_secret').value=",
                    "Array.from(a).map(function(b){return b.toString(16).padStart(2,'0')}).join('');",
                    "return false;",
                ]),
            ],
        ]);

        // ── Sentinel section ────────────────────────────────────────────────
        $builder->add("_section_sentinel", ButtonType::class, [
            "label" => "ALTCHA Sentinel (cloud or self-hosted Sentinel instance)",
            "attr"  => [
                "class"    => "btn btn-link btn-block text-left",
                "disabled" => "disabled",
                "style"    => "cursor:default;pointer-events:none;font-weight:bold;font-size:13px;border-bottom:2px solid #ddd;padding:0 0 6px 0;margin-bottom:10px;color:#333;margin-top:8px;",
            ],
        ]);

        $builder->add("sentinel_domain", UrlType::class, [
            "label"      => "strings.altcha.settings.sentinel_domain",
            "required"   => false,
            "data"       => $data["sentinel_domain"] ?? "",
            "label_attr" => ["class" => "control-label"],
            "attr"       => [
                "class"   => "form-control",
                "tooltip" => "strings.altcha.settings.sentinel_domain.notice",
            ],
        ]);

        $builder->add("sentinel_api_key", TextType::class, [
            "label"      => "strings.altcha.settings.sentinel_api_key",
            "required"   => false,
            "data"       => $data["sentinel_api_key"] ?? "",
            "label_attr" => ["class" => "control-label"],
            "attr"       => [
                "class"   => "form-control",
                "tooltip" => "strings.altcha.settings.sentinel_api_key.notice",
            ],
        ]);

        $builder->add("sentinel_api_secret", PasswordType::class, [
            "label"      => "strings.altcha.settings.sentinel_api_secret",
            "required"   => false,
            "data"       => $data["sentinel_api_secret"] ?? "",
            "label_attr" => ["class" => "control-label"],
            "attr"       => [
                "class"       => "form-control",
                "tooltip"     => "strings.altcha.settings.sentinel_api_secret.notice",
                "placeholder" => "strings.altcha.settings.sentinel_api_secret.placeholder",
            ],
        ]);

        // ── Advanced section ────────────────────────────────────────────────
        $builder->add("_section_advanced", ButtonType::class, [
            "label" => "Advanced",
            "attr"  => [
                "class"    => "btn btn-link btn-block text-left",
                "disabled" => "disabled",
                "style"    => "cursor:default;pointer-events:none;font-weight:bold;font-size:13px;border-bottom:2px solid #ddd;padding:0 0 6px 0;margin-bottom:10px;color:#333;margin-top:8px;",
            ],
        ]);

        $builder->add("script_url", TextType::class, [
            "label"      => "strings.altcha.settings.script_url",
            "required"   => false,
            "data"       => $data["script_url"] ?? "",
            "label_attr" => ["class" => "control-label"],
            "attr"       => [
                "class"       => "form-control",
                "tooltip"     => "strings.altcha.settings.script_url.notice",
                "placeholder" => AltchaClient::DEFAULT_SCRIPT_URL,
            ],
        ]);
    }

    /**
     * <h2>getFormNotes</h2>
     *
     * Shows a live Sentinel connectivity status when Sentinel credentials are
     * configured; otherwise shows the generic setup notice.
     *
     * @param string $section
     *
     * @return array{0: string, 1: string}
     */
    public function getFormNotes($section): array {
        if(!in_array($section, ["keys", "custom"], true))
            return parent::getFormNotes($section);

        $keys          = $this->getKeys();
        $sentinelReady = !empty($keys["sentinel_domain"]) && !empty($keys["sentinel_api_key"]) && !empty($keys["sentinel_api_secret"]);

        if($sentinelReady)
            return $this->getSentinelStatusNote($keys);

        return ["strings.altcha.settings.notice", "info"];
    }

    /**
     * <h2>getSentinelStatusNote</h2>
     *
     * Makes a quick HTTP probe to the configured Sentinel challenge endpoint
     * and returns a [translation-key, alert-type] pair describing the result.
     * The result is cached for 5 minutes to avoid a network round-trip on
     * every form render.
     *
     * @param array $keys
     *
     * @return array{0: string, 1: string}
     */
    protected function getSentinelStatusNote(array $keys): array {
        // Check cached result first (5-minute TTL set in set() below).
        try {
            $cached = $this->cache->get("altcha_sentinel_status");
            if(is_array($cached))
                return $cached;
        } catch (\Throwable $e) {}

        $domain = rtrim((string) ($keys["sentinel_domain"] ?? ""), "/");
        $apiKey = (string) ($keys["sentinel_api_key"] ?? "");
        $url    = $domain . "/v1/challenge?apiKey=" . rawurlencode($apiKey);

        $status = "unreachable";
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if($code === 200 && $body !== false) {
                $decoded = json_decode((string) $body, true);
                $status  = isset($decoded["algorithm"]) ? "ok" : "bad_response";
            } elseif($code === 403) {
                $status = "origin_forbidden";
            } elseif($code === 401) {
                $status = "unauthorized";
            } else {
                $status = "unreachable";
            }
        } catch (\Throwable $e) {
            $status = "unreachable";
        }

        $noteMap = [
            "ok"               => ["strings.altcha.settings.sentinel.status.ok",               "success"],
            "bad_response"     => ["strings.altcha.settings.sentinel.status.bad_response",     "warning"],
            "origin_forbidden" => ["strings.altcha.settings.sentinel.status.origin_forbidden", "danger"],
            "unauthorized"     => ["strings.altcha.settings.sentinel.status.unauthorized",     "danger"],
            "unreachable"      => ["strings.altcha.settings.sentinel.status.unreachable",      "danger"],
        ];

        $result = $noteMap[$status] ?? $noteMap["unreachable"];

        try {
            $this->cache->set("altcha_sentinel_status", $result, 300);
        } catch (\Throwable $e) {}

        return $result;
    }

}
