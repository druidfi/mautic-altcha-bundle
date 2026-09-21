<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

use MauticPlugin\MauticAltchaBundle\Service\AltchaClient;

/**
 * <h1>Class ChallengeController</h1>
 *
 * A public (unauthenticated), read-only endpoint that the <altcha-widget>
 * fetches a fresh challenge from directly, in self-hosted mode.
 *
 * This exists because Mautic caches an entire form's RENDERED HTML in
 * `forms.cached_html` (see `Mautic\FormBundle\Model\FormModel::generateHtml()`
 * / `getContent()`) and only regenerates it when the form itself is saved -
 * not on every page view, and not every time preview is opened. A challenge
 * embedded directly into that cached HTML would be reused by every visitor
 * until the form is next saved, and would eventually expire while still
 * being served - which is exactly what caused "Verification failed. Try
 * again later." to appear on every attempt, deterministically, regardless
 * of what the challenge JSON itself contained. Pointing the widget's
 * "challengeurl" attribute at this endpoint instead (a URL, fetched live by
 * the visitor's browser at the moment the widget actually loads) sidesteps
 * that caching layer entirely - the same approach ALTCHA Sentinel already
 * uses, and what ALTCHA's own docs describe as the standard "server
 * integration" pattern for any dynamically-cached page.
 *
 * Deliberately a plain, invokable class (`__invoke()`), not a
 * Mautic\CoreBundle\Controller\FormController with a named *Action method -
 * Mautic's own plugin-config docs specifically show a bare `SomeClass::class`
 * reference (assuming an invokable controller) as the pattern for routes
 * registered under the "public" firewall, as opposed to the `[SomeClass,
 * 'methodName']` array pairing used for "main"/authenticated routes. Using
 * the array form here instead would make the route fail to resolve.
 * Beyond matching that convention, this route also needs no session, no
 * CSRF token, and no authentication (it has to work for anonymous visitors
 * on a public form), and it never renders a view or touches Mautic's admin
 * UI plumbing.
 *
 * @package MauticPlugin\MauticAltchaBundle\Controller
 *
 * @license GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
class ChallengeController {

    /** Hard floor/ceiling on the requested expiry, regardless of what's passed in the query string. */
    private const MIN_EXPIRE_SECONDS = 30;
    private const MAX_EXPIRE_SECONDS = 3600;

    /**
     * <h2>ChallengeController constructor.</h2>
     *
     * @param AltchaClient $altchaClient
     */
    public function __construct(private readonly AltchaClient $altchaClient) {

    }

    /**
     * <h2>__invoke</h2>
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function __invoke(Request $request): JsonResponse {
        // Handle CORS preflight — the widget's fetch() call from an embedded
        // third-party domain triggers an OPTIONS preflight before the real GET.
        if("OPTIONS" === $request->getMethod()) {
            $response = new JsonResponse(null, 204);
            $response->headers->set("Access-Control-Allow-Origin", "*");
            $response->headers->set("Access-Control-Allow-Methods", "GET, OPTIONS");
            $response->headers->set("Access-Control-Allow-Headers", "Content-Type, Accept");
            return $response;
        }

        $complexity    = (string) $request->query->get("complexity", "medium");
        $expireSeconds = (int) $request->query->get("expire", (string) AltchaClient::DEFAULT_EXPIRE_SECONDS);
        $expireSeconds = max(self::MIN_EXPIRE_SECONDS, min(self::MAX_EXPIRE_SECONDS, $expireSeconds));

        $challenge = $this->altchaClient->createChallengeForComplexity($complexity, $expireSeconds);

        if($challenge === null) {
            // Either self-hosted mode isn't configured, or Sentinel is in
            // use instead - in the latter case the widget should never end
            // up pointed here in the first place (see AltchaClient::buildWidgetChallenge()),
            // but fail safely and explicitly either way rather than
            // returning something the widget would silently misinterpret.
            return new JsonResponse(["error" => "ALTCHA is not configured for self-hosted challenges."], 503);
        }

        $response = new JsonResponse($challenge);

        // A fresh challenge every time, unconditionally - never let any
        // layer (browser, reverse proxy, CDN) cache this response, or we'd
        // reintroduce the exact staleness problem this endpoint exists to
        // avoid.
        $response->headers->set("Cache-Control", "no-store, no-cache, must-revalidate, max-age=0");
        $response->headers->set("Pragma", "no-cache");

        // Mautic forms are routinely embedded on third-party domains (a
        // client's WordPress site, a landing page host, etc.) - the
        // widget's fetch() call to this endpoint would otherwise be
        // blocked cross-origin. The response contains no sensitive or
        // user-specific data (just a generic, signed proof-of-work puzzle),
        // so a permissive origin here doesn't weaken anything.
        $response->headers->set("Access-Control-Allow-Origin", "*");

        return $response;
    }

}
