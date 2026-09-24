<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use MauticPlugin\MauticAltchaBundle\Service\AltchaClient;

/**
 * <h1>Class AltchaExtension</h1>
 *
 * Exposes altcha_challenge() to Twig so the field template can build the
 * <altcha-widget>'s "challengeurl" attribute for each ALTCHA field. The
 * returned value is always a URL - never inline JSON - which the widget
 * fetches from live, at the moment it actually loads in a visitor's
 * browser:
 *
 * - our own challenge-issuing endpoint (self-hosted mode), or
 * - Sentinel's own endpoint (Sentinel mode).
 *
 * This matters because Mautic caches an entire form's rendered HTML
 * (`forms.cached_html`) and only regenerates it when the form is saved -
 * not on every page view. A challenge baked directly into that cached HTML
 * would be reused by every visitor until the form's next save, eventually
 * going stale/expired while still being served. A URL sidesteps that
 * entirely, since it's fetched fresh on every actual page view regardless
 * of how old the surrounding HTML is.
 *
 * @package MauticPlugin\MauticAltchaBundle\Twig
 *
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
class AltchaExtension extends AbstractExtension {

    /**
     * <h2>AltchaExtension constructor.</h2>
     *
     * @param AltchaClient $altchaClient
     */
    public function __construct(private readonly AltchaClient $altchaClient) {

    }

    /** {@inheritDoc} */
    public function getFunctions(): array {
        return [
            new TwigFunction("altcha_challenge", [$this, "createChallenge"]),
            new TwigFunction("altcha_script_url", [$this, "getScriptUrl"]),
        ];
    }

    /**
     * <h2>createChallenge</h2>
     *
     * @param string $complexity     One of "low", "medium", "high". Ignored in Sentinel mode.
     * @param int    $expireSeconds  Ignored in Sentinel mode.
     *
     * @return string|null Null when neither self-hosted nor Sentinel credentials are configured yet.
     */
    public function createChallenge(string $complexity = "medium", int $expireSeconds = 600): ?string {
        return $this->altchaClient->buildWidgetChallenge($complexity, $expireSeconds);
    }

    /**
     * <h2>getScriptUrl</h2>
     *
     * Returns the URL to load the ALTCHA widget JS from. Uses the custom
     * URL from integration settings when set, otherwise the jsDelivr CDN.
     *
     * @return string
     */
    public function getScriptUrl(): string {
        return $this->altchaClient->getScriptUrl();
    }

}
