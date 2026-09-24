<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Tests\Service;

use MauticPlugin\MauticAltchaBundle\Integration\AltchaIntegration;
use MauticPlugin\MauticAltchaBundle\Service\AltchaClient;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use PHPUnit\Framework\TestCase;

class AltchaClientTest extends TestCase {

    /**
     * @test
     */
    public function testIsConfiguredWithSelfHostedSecret(): void {
        $client = $this->createAltchaClient();
        $this->assertTrue($client->isConfigured());
        $this->assertTrue($client->hasSelfHostedSecret());
        $this->assertFalse($client->usesSentinel());
    }

    /**
     * @test
     */
    public function testIsNotConfiguredWhenNoCredentials(): void {
        $client = $this->createAltchaClientWithConfig([]);
        $this->assertFalse($client->isConfigured());
        $this->assertFalse($client->hasSelfHostedSecret());
        $this->assertFalse($client->usesSentinel());
    }

    /**
     * @test
     */
    public function testUsesSentinelWhenAllSentinelFieldsSet(): void {
        $client = $this->createAltchaClientWithConfig([
            "sentinel_domain"     => "https://sentinel.example.com",
            "sentinel_api_key"    => "key_abc123",
            "sentinel_api_secret" => "secret_xyz",
        ]);

        $this->assertTrue($client->isConfigured());
        $this->assertTrue($client->usesSentinel());
        $this->assertFalse($client->hasSelfHostedSecret());
    }

    /**
     * Sentinel is not active when only two of three fields are set.
     *
     * @test
     */
    public function testSentinelRequiresAllThreeFields(): void {
        $client = $this->createAltchaClientWithConfig([
            "sentinel_domain"  => "https://sentinel.example.com",
            "sentinel_api_key" => "key_abc123",
            // sentinel_api_secret missing
        ]);

        $this->assertFalse($client->usesSentinel());
        $this->assertFalse($client->isConfigured());
    }

    /**
     * Property test: for any valid parameters the challenge must contain all required fields.
     *
     * @test
     */
    public function testChallengeStructureCompleteness(): void {
        $client   = $this->createAltchaClient();
        $failures = [];

        for ($i = 0; $i < 100; $i++) {
            $challenge      = $client->createChallenge(rand(1000, 1000000), rand(10, 300));
            $requiredFields = ["algorithm", "challenge", "salt", "signature", "maxNumber"];
            $missing        = array_filter($requiredFields, fn($f) => !array_key_exists($f, $challenge));

            if (!empty($missing)) {
                $failures[] = ["iteration" => $i, "missing" => array_values($missing)];
            }
        }

        $this->assertEmpty($failures, json_encode($failures, JSON_PRETTY_PRINT));
    }

    /**
     * Property test: a correctly solved challenge must be accepted by verify().
     *
     * @test
     */
    public function testValidPayloadAcceptance(): void {
        $client   = $this->createAltchaClient();
        $failures = [];

        for ($i = 0; $i < 10; $i++) { // small — solving PoW is slow
            $challenge = $client->createChallenge(rand(1000, 5000), rand(60, 300));
            $solution  = $this->solveChallenge($challenge);

            if ($solution === null) {
                $failures[] = ["iteration" => $i, "reason" => "Could not solve challenge"];
                continue;
            }

            $payload = base64_encode(json_encode([
                "algorithm" => $challenge["algorithm"],
                "challenge" => $challenge["challenge"],
                "number"    => $solution,
                "salt"      => $challenge["salt"],
                "signature" => $challenge["signature"],
            ]));

            if (!$client->verify($payload)) {
                $failures[] = ["iteration" => $i, "reason" => "Valid payload was rejected"];
            }
        }

        $this->assertEmpty($failures, json_encode($failures, JSON_PRETTY_PRINT));
    }

    /**
     * Property test: tampered payloads must always be rejected.
     *
     * @test
     */
    public function testInvalidPayloadRejection(): void {
        $client   = $this->createAltchaClient();
        $failures = [];

        for ($i = 0; $i < 100; $i++) {
            $challenge = $client->createChallenge(rand(1000, 5000), rand(60, 300));
            $type      = rand(1, 4);

            $data = match($type) {
                1 => ["algorithm" => $challenge["algorithm"], "challenge" => $challenge["challenge"], "number" => rand(0, 999999), "salt" => $challenge["salt"], "signature" => $challenge["signature"]],
                2 => ["algorithm" => $challenge["algorithm"], "challenge" => $challenge["challenge"], "number" => 0, "salt" => $challenge["salt"], "signature" => bin2hex(random_bytes(32))],
                3 => ["algorithm" => $challenge["algorithm"], "challenge" => base64_encode(random_bytes(32)), "number" => 0, "salt" => $challenge["salt"], "signature" => $challenge["signature"]],
                default => ["algorithm" => $challenge["algorithm"], "challenge" => $challenge["challenge"], "number" => 0, "salt" => bin2hex(random_bytes(16)), "signature" => $challenge["signature"]],
            };

            if ($client->verify(base64_encode(json_encode($data)))) {
                $failures[] = ["iteration" => $i, "manipulation_type" => $type];
            }
        }

        $this->assertEmpty($failures, json_encode($failures, JSON_PRETTY_PRINT));
    }

    /**
     * @test
     */
    public function testVerifyReturnsFalseForEmptyPayload(): void {
        $this->assertFalse($this->createAltchaClient()->verify(""));
        $this->assertFalse($this->createAltchaClient()->verify("   "));
    }

    /**
     * @test
     */
    public function testVerifyReturnsFalseWhenNotConfigured(): void {
        $client = $this->createAltchaClientWithConfig([]);
        $this->assertFalse($client->verify(base64_encode(json_encode(["algorithm" => "SHA-256"]))));
    }

    /**
     * @test
     */
    public function testCreateChallengeForComplexityReturnsNullWhenNotConfigured(): void {
        $client = $this->createAltchaClientWithConfig([]);
        $this->assertNull($client->createChallengeForComplexity("medium"));
    }

    /**
     * @test
     */
    public function testBuildSentinelChallengeUrl(): void {
        $client = $this->createAltchaClientWithConfig([
            "sentinel_domain"     => "https://sentinel.example.com/",
            "sentinel_api_key"    => "key_abc",
            "sentinel_api_secret" => "secret",
        ]);

        $url = $client->buildWidgetChallenge();
        $this->assertStringStartsWith("https://sentinel.example.com/v1/challenge?apiKey=", $url);
        $this->assertStringContainsString("key_abc", $url);
    }

    /**
     * @test
     */
    public function testGetScriptUrlReturnsDefaultWhenNotSet(): void {
        $this->assertEquals(AltchaClient::DEFAULT_SCRIPT_URL, $this->createAltchaClient()->getScriptUrl());
    }

    /**
     * @test
     */
    public function testGetScriptUrlReturnsCustomUrlWhenSet(): void {
        $custom = "https://assets.example.com/altcha.js";
        $client = $this->createAltchaClientWithConfig([
            "hmac_secret" => "test-hmac-secret-for-unit-tests-1234567890",
            "script_url"  => $custom,
        ]);
        $this->assertEquals($custom, $client->getScriptUrl());
    }

    /**
     * Empty script_url must fall back to the CDN default.
     *
     * @test
     */
    public function testGetScriptUrlIgnoresEmptyString(): void {
        $client = $this->createAltchaClientWithConfig([
            "hmac_secret" => "test-hmac-secret-for-unit-tests-1234567890",
            "script_url"  => "",
        ]);
        $this->assertEquals(AltchaClient::DEFAULT_SCRIPT_URL, $client->getScriptUrl());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createAltchaClient(): AltchaClient {
        return $this->createAltchaClientWithConfig([
            "hmac_secret" => "test-hmac-secret-for-unit-tests-1234567890",
        ]);
    }

    private function createAltchaClientWithConfig(array $keys): AltchaClient {
        $integration = $this->createMock(AbstractIntegration::class);
        $integration->method("getKeys")->willReturn($keys);

        $integrationHelper = $this->createMock(IntegrationHelper::class);
        $integrationHelper->method("getIntegrationObject")
            ->with(AltchaIntegration::INTEGRATION_NAME)
            ->willReturn($integration);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method("generate")->willReturnCallback(
            fn(string $route, array $params) => "/altcha/challenge?" . http_build_query($params)
        );

        return new AltchaClient($integrationHelper, $router);
    }

    private function solveChallenge(array $challenge): ?int {
        $altcha    = new \AltchaOrg\Altcha\V1\Altcha("test-hmac-secret-for-unit-tests-1234567890");
        $algorithm = \AltchaOrg\Altcha\V1\Hasher\Algorithm::from($challenge["algorithm"]);
        $solution  = $altcha->solveChallenge($challenge["challenge"], $challenge["salt"], $algorithm, $challenge["maxNumber"]);

        return $solution ? $solution->number : null;
    }

}
