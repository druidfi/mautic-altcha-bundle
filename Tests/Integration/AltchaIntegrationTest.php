<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Tests\Integration;

use MauticPlugin\MauticAltchaBundle\Integration\AltchaIntegration;
use PHPUnit\Framework\TestCase;

class AltchaIntegrationTest extends TestCase {

    /**
     * @test
     */
    public function testGetName(): void {
        $this->assertEquals("Altcha", $this->makeIntegration()->getName());
    }

    /**
     * @test
     */
    public function testGetDisplayName(): void {
        $this->assertEquals("ALTCHA", $this->makeIntegration()->getDisplayName());
    }

    /**
     * @test
     */
    public function testGetAuthenticationType(): void {
        $this->assertEquals("none", $this->makeIntegration()->getAuthenticationType());
    }

    /**
     * getRequiredKeyFields() must be empty so self-hosted and Sentinel fields
     * can be filled independently without Mautic forcing all of them at once.
     *
     * @test
     */
    public function testGetRequiredKeyFieldsIsEmpty(): void {
        $this->assertSame([], $this->makeIntegration()->getRequiredKeyFields());
    }

    /**
     * Both hmac_secret and sentinel_api_secret must be in getSecretKeys() so
     * Mautic masks them in the UI and encrypts them at rest.
     *
     * @test
     */
    public function testGetSecretKeys(): void {
        $keys = $this->makeIntegration()->getSecretKeys();
        $this->assertContains("hmac_secret", $keys);
        $this->assertContains("sentinel_api_secret", $keys);
    }

    /**
     * @test
     */
    public function testIsNotConfiguredWithEmptyKeys(): void {
        $this->assertFalse($this->makeIntegrationWithKeys([])->isConfigured());
    }

    /**
     * @test
     */
    public function testIsConfiguredWithHmacSecret(): void {
        $integration = $this->makeIntegrationWithKeys(["hmac_secret" => "any-secret"]);
        $this->assertTrue($integration->isConfigured());
    }

    /**
     * @test
     */
    public function testIsConfiguredWithAllSentinelFields(): void {
        $integration = $this->makeIntegrationWithKeys([
            "sentinel_domain"     => "https://sentinel.example.com",
            "sentinel_api_key"    => "key_abc",
            "sentinel_api_secret" => "secret_xyz",
        ]);
        $this->assertTrue($integration->isConfigured());
    }

    /**
     * isConfigured() must return false when Sentinel fields are only partially filled.
     *
     * @test
     */
    public function testIsNotConfiguredWithPartialSentinelFields(): void {
        $integration = $this->makeIntegrationWithKeys([
            "sentinel_domain"  => "https://sentinel.example.com",
            "sentinel_api_key" => "key_abc",
            // sentinel_api_secret missing
        ]);
        $this->assertFalse($integration->isConfigured());
    }

    /**
     * Property test: any non-empty hmac_secret makes isConfigured() return true.
     *
     * @test
     */
    public function testIsConfiguredForAnyNonEmptyHmacSecret(): void {
        $failures = [];

        for ($i = 0; $i < 100; $i++) {
            $secret      = $this->randomString(rand(20, 64));
            $integration = $this->makeIntegrationWithKeys(["hmac_secret" => $secret]);

            if (!$integration->isConfigured()) {
                $failures[] = ["iteration" => $i, "secret_length" => strlen($secret)];
            }
        }

        $this->assertEmpty($failures, json_encode($failures, JSON_PRETTY_PRINT));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeIntegration(): AltchaIntegration {
        return new class extends AltchaIntegration {
            public function __construct() {}
        };
    }

    private function makeIntegrationWithKeys(array $keys): AltchaIntegration {
        $integration = $this->getMockBuilder(AltchaIntegration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(["getKeys"])
            ->getMock();
        $integration->method("getKeys")->willReturn($keys);
        return $integration;
    }

    private function randomString(int $length): string {
        $chars  = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_";
        $result = "";
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[rand(0, strlen($chars) - 1)];
        }
        return $result;
    }

}
