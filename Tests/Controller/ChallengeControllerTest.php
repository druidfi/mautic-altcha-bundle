<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Tests\Controller;

use MauticPlugin\MauticAltchaBundle\Controller\ChallengeController;
use MauticPlugin\MauticAltchaBundle\Service\AltchaClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\TestCase;

class ChallengeControllerTest extends TestCase {

    /**
     * OPTIONS preflight returns 204 with CORS headers and never touches AltchaClient.
     *
     * @test
     */
    public function testOptionsPreflightReturnsCorsHeaders(): void {
        $client = $this->createMock(AltchaClient::class);
        $client->expects($this->never())->method("createChallengeForComplexity");

        $request  = new Request([], [], [], [], [], ["REQUEST_METHOD" => "OPTIONS"]);
        $response = (new ChallengeController($client))->__invoke($request);

        $this->assertEquals(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        $this->assertEquals("*", $response->headers->get("Access-Control-Allow-Origin"));
        $this->assertEquals("GET, OPTIONS", $response->headers->get("Access-Control-Allow-Methods"));
        $this->assertNotEmpty($response->headers->get("Access-Control-Allow-Headers"));
    }

    /**
     * GET with no query params uses "medium" complexity and the default expire.
     *
     * @test
     */
    public function testDefaultComplexityAndExpire(): void {
        $client = $this->createMock(AltchaClient::class);
        $client->expects($this->once())
            ->method("createChallengeForComplexity")
            ->with("medium", AltchaClient::DEFAULT_EXPIRE_SECONDS)
            ->willReturn($this->fakeChallenge());

        $response = (new ChallengeController($client))->__invoke(new Request());

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Complexity and expire from the query string are forwarded to AltchaClient.
     *
     * @test
     */
    public function testQueryParamsArePassedThrough(): void {
        $client = $this->createMock(AltchaClient::class);
        $client->expects($this->once())
            ->method("createChallengeForComplexity")
            ->with("high", 300)
            ->willReturn($this->fakeChallenge());

        $response = (new ChallengeController($client))->__invoke(
            new Request(["complexity" => "high", "expire" => "300"])
        );

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Returns 503 when AltchaClient is not configured (returns null).
     *
     * @test
     */
    public function testReturns503WhenNotConfigured(): void {
        $client = $this->createMock(AltchaClient::class);
        $client->method("createChallengeForComplexity")->willReturn(null);

        $response = (new ChallengeController($client))->__invoke(new Request());

        $this->assertEquals(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    /**
     * Successful response includes CORS header and no-store cache control.
     *
     * @test
     */
    public function testSuccessResponseIncludesCorsAndCacheHeaders(): void {
        $client = $this->createMock(AltchaClient::class);
        $client->method("createChallengeForComplexity")->willReturn($this->fakeChallenge());

        $response = (new ChallengeController($client))->__invoke(new Request());

        $this->assertEquals("*", $response->headers->get("Access-Control-Allow-Origin"));
        $this->assertStringContainsString("no-store", $response->headers->get("Cache-Control"));
    }

    /**
     * The expire query parameter is clamped between 30 and 3600 regardless of input.
     *
     * @test
     */
    public function testExpireIsClamped(): void {
        $cases = [
            ["input" => "0",    "expected" => 30],
            ["input" => "29",   "expected" => 30],
            ["input" => "30",   "expected" => 30],
            ["input" => "600",  "expected" => 600],
            ["input" => "3600", "expected" => 3600],
            ["input" => "9999", "expected" => 3600],
        ];

        foreach ($cases as $case) {
            $captured = null;

            $client = $this->createMock(AltchaClient::class);
            $client->method("createChallengeForComplexity")
                ->willReturnCallback(function(string $complexity, int $expire) use (&$captured) {
                    $captured = $expire;
                    return $this->fakeChallenge();
                });

            (new ChallengeController($client))->__invoke(
                new Request(["expire" => $case["input"]])
            );

            $this->assertEquals($case["expected"], $captured, "expire={$case["input"]}");
        }
    }

    /**
     * The response body is valid JSON containing the challenge fields.
     *
     * @test
     */
    public function testResponseBodyIsValidJson(): void {
        $client = $this->createMock(AltchaClient::class);
        $client->method("createChallengeForComplexity")->willReturn($this->fakeChallenge());

        $response = (new ChallengeController($client))->__invoke(new Request());
        $body     = json_decode($response->getContent(), true);

        $this->assertIsArray($body);
        foreach (["algorithm", "challenge", "salt", "signature", "maxNumber"] as $key) {
            $this->assertArrayHasKey($key, $body);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fakeChallenge(): array {
        return [
            "algorithm" => "SHA-256",
            "challenge" => "abc123",
            "salt"      => "def456",
            "signature" => "ghi789",
            "maxNumber" => 100000,
        ];
    }

}
