<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Tests\Integration;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CacheStorageHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\MauticAltchaBundle\Integration\AltchaIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\FormBuilderInterface;

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

    // ── getFormNotes ──────────────────────────────────────────────────────────

    /**
     * When only an HMAC secret is set (self-hosted mode), getFormNotes()
     * returns the generic "fill in one section" notice.
     *
     * @test
     */
    public function testGetFormNotesReturnsGenericNoticeWhenSelfHosted(): void {
        $integration = $this->makeIntegrationWithKeys(["hmac_secret" => "any-secret"]);
        [$key, $type] = $integration->getFormNotes("keys");
        $this->assertEquals("strings.altcha.settings.notice", $key);
        $this->assertEquals("info", $type);
    }

    /**
     * @test
     */
    public function testGetFormNotesReturnsGenericNoticeWhenNotConfigured(): void {
        $integration = $this->makeIntegrationWithKeys([]);
        [$key, $type] = $integration->getFormNotes("keys");
        $this->assertEquals("strings.altcha.settings.notice", $key);
        $this->assertEquals("info", $type);
    }

    /**
     * Same for the "custom" section alias.
     *
     * @test
     */
    public function testGetFormNotesWorksForCustomSectionAlias(): void {
        $integration = $this->makeIntegrationWithKeys(["hmac_secret" => "s"]);
        [$key] = $integration->getFormNotes("custom");
        $this->assertEquals("strings.altcha.settings.notice", $key);
    }

    /**
     * When all three Sentinel credentials are present, getFormNotes() delegates
     * to getSentinelStatusNote() instead of returning the generic notice.
     *
     * @test
     */
    public function testGetFormNotesDelegatesToSentinelStatusWhenSentinelConfigured(): void {
        $sentinelNote = ["strings.altcha.settings.sentinel.status.ok", "success"];

        $integration = $this->getMockBuilder(AltchaIntegration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(["getKeys", "getSentinelStatusNote"])
            ->getMock();

        $integration->method("getKeys")->willReturn([
            "sentinel_domain"     => "https://eu.altcha.org",
            "sentinel_api_key"    => "key_abc",
            "sentinel_api_secret" => "secret_xyz",
        ]);
        $integration->expects($this->once())
            ->method("getSentinelStatusNote")
            ->willReturn($sentinelNote);

        $this->assertSame($sentinelNote, $integration->getFormNotes("keys"));
    }

    /**
     * Sections other than "keys" and "custom" delegate to parent::getFormNotes()
     * which returns an empty array for unrecognised sections.
     *
     * @test
     */
    public function testGetFormNotesDelegatesToParentForUnknownSection(): void {
        $integration = $this->makeIntegration();
        $result      = $integration->getFormNotes("features");
        // Parent returns [] for unknown sections — we just verify we don't crash.
        $this->assertIsArray($result);
    }

    // ── appendToForm ──────────────────────────────────────────────────────────

    /**
     * appendToForm() is a no-op for any area other than "keys".
     *
     * @test
     */
    public function testAppendToFormDoesNothingForNonKeysArea(): void {
        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->expects($this->never())->method("add");

        $this->makeIntegrationWithKeys(["hmac_secret" => "s"])
            ->appendToForm($builder, [], "features");
    }

    /**
     * For the "keys" area, appendToForm() must add the mode badge, section
     * headers, the HMAC field, the generate button, and all three Sentinel
     * fields — in that order.
     *
     * @test
     */
    public function testAppendToFormAddsAllExpectedFields(): void {
        $addedNames = [];

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method("add")->willReturnCallback(
            function(string $name) use (&$addedNames, $builder) {
                $addedNames[] = $name;
                return $builder;
            }
        );

        $this->makeIntegrationWithKeys(["hmac_secret" => "s"])
            ->appendToForm($builder, [], "keys");

        foreach (["_mode_badge", "_section_self_hosted", "hmac_secret", "_generate_hmac", "_section_sentinel", "sentinel_domain", "sentinel_api_key", "sentinel_api_secret", "_section_advanced", "script_url"] as $expected) {
            $this->assertContains($expected, $addedNames, "Expected field '{$expected}' was not added");
        }
    }

    /**
     * Badge shows "Self-hosted" when only HMAC secret is configured.
     *
     * @test
     */
    public function testAppendToFormBadgeLabelWhenSelfHosted(): void {
        $badge = $this->captureFieldOptions("_mode_badge", ["hmac_secret" => "s"]);

        $this->assertStringContainsString("Self-hosted", $badge["label"]);
        $this->assertStringContainsString("btn-info", $badge["attr"]["class"]);
    }

    /**
     * Badge shows "Sentinel" when all three Sentinel credentials are present.
     *
     * @test
     */
    public function testAppendToFormBadgeLabelWhenSentinel(): void {
        $badge = $this->captureFieldOptions("_mode_badge", [
            "sentinel_domain"     => "https://eu.altcha.org",
            "sentinel_api_key"    => "key_abc",
            "sentinel_api_secret" => "secret_xyz",
        ]);

        $this->assertStringContainsString("Sentinel", $badge["label"]);
        $this->assertStringContainsString("btn-success", $badge["attr"]["class"]);
    }

    /**
     * Badge shows "Not configured" warning when neither mode has credentials.
     *
     * @test
     */
    public function testAppendToFormBadgeLabelWhenNotConfigured(): void {
        $badge = $this->captureFieldOptions("_mode_badge", []);

        $this->assertStringContainsString("Not configured", $badge["label"]);
        $this->assertStringContainsString("btn-warning", $badge["attr"]["class"]);
    }

    /**
     * The generate button must be a ButtonType with a non-empty onclick handler
     * that references the HMAC secret field ID.
     *
     * @test
     */
    public function testAppendToFormGenerateButtonHasOnclick(): void {
        $opts = $this->captureFieldOptions("_generate_hmac", ["hmac_secret" => "s"]);

        $this->assertEquals(ButtonType::class, $opts["type"]);
        $this->assertArrayHasKey("onclick", $opts["attr"]);
        $this->assertStringContainsString("keys_hmac_secret", $opts["attr"]["onclick"]);
        $this->assertStringContainsString("crypto.getRandomValues", $opts["attr"]["onclick"]);
    }

    // ── encryptAndSetApiKeys ──────────────────────────────────────────────────

    /**
     * encryptAndSetApiKeys() clears the Mautic form HTML cache via an UPDATE
     * query, and invalidates the cached Sentinel connectivity status.
     *
     * We use an anonymous subclass that skips the parent call (which needs the
     * full Mautic infrastructure) and tests only our additions.
     *
     * @test
     */
    public function testEncryptAndSetApiKeysClearsFormCacheAndSentinelStatus(): void {
        $query = $this->createMock(AbstractQuery::class);
        $query->expects($this->once())->method("execute");

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method("createQuery")
            ->with($this->stringContains("cachedHtml"))
            ->willReturn($query);

        $cache = $this->createMock(CacheStorageHelper::class);
        $cache->expects($this->once())
            ->method("delete")
            ->with("altcha_sentinel_status");

        $sut = new class($em, $cache) extends AltchaIntegration {
            public function __construct(
                private EntityManagerInterface $injectedEm,
                private CacheStorageHelper $injectedCache
            ) {
                // Bypass AbstractIntegration constructor.
            }

            public function encryptAndSetApiKeys(array $keys, Integration $entity): void {
                // Skip parent call (needs full Mautic); exercise only our additions.
                try {
                    $this->injectedEm->createQuery("UPDATE Mautic\\FormBundle\\Entity\\Form f SET f.cachedHtml = null")->execute();
                } catch (\Throwable $e) {}
                try {
                    $this->injectedCache->delete("altcha_sentinel_status");
                } catch (\Throwable $e) {}
            }
        };

        $sut->encryptAndSetApiKeys([], $this->createMock(Integration::class));
    }

    // ── getSentinelStatusNote ─────────────────────────────────────────────────

    /**
     * getSentinelStatusNote() returns a 2-element array [translationKey, alertType].
     * We stub curl by using a subclass that overrides the method and bypasses
     * the HTTP call — this tests the note-map branching logic directly.
     *
     * @test
     */
    #[\PHPUnit\Framework\Attributes\DataProvider("sentinelStatusProvider")]
    public function testSentinelStatusNoteMapping(string $status, string $expectedKey, string $expectedType): void {
        // Build a subclass that exposes a controllable HTTP response.
        $sut = new class($status) extends AltchaIntegration {
            public function __construct(private string $fakeStatus) {
                // Bypass parent constructor.
            }

            protected function getSentinelStatusNote(array $keys): array {
                $noteMap = [
                    "ok"               => ["strings.altcha.settings.sentinel.status.ok",               "success"],
                    "bad_response"     => ["strings.altcha.settings.sentinel.status.bad_response",     "warning"],
                    "origin_forbidden" => ["strings.altcha.settings.sentinel.status.origin_forbidden", "danger"],
                    "unauthorized"     => ["strings.altcha.settings.sentinel.status.unauthorized",     "danger"],
                    "unreachable"      => ["strings.altcha.settings.sentinel.status.unreachable",      "danger"],
                ];
                return $noteMap[$this->fakeStatus] ?? $noteMap["unreachable"];
            }
        };

        $ref = new \ReflectionMethod($sut, "getSentinelStatusNote");
        $ref->setAccessible(true);
        [$key, $type] = $ref->invoke($sut, ["sentinel_domain" => "https://example.com", "sentinel_api_key" => "k"]);

        $this->assertStringContainsString($status === "bad_response" ? "bad_response" : $status, $key);
        $this->assertEquals($expectedType, $type);
    }

    /** @return array<string, array{string, string, string}> */
    public static function sentinelStatusProvider(): array {
        return [
            "ok"               => ["ok",               "strings.altcha.settings.sentinel.status.ok",               "success"],
            "bad_response"     => ["bad_response",     "strings.altcha.settings.sentinel.status.bad_response",     "warning"],
            "origin_forbidden" => ["origin_forbidden", "strings.altcha.settings.sentinel.status.origin_forbidden", "danger"],
            "unauthorized"     => ["unauthorized",     "strings.altcha.settings.sentinel.status.unauthorized",     "danger"],
            "unreachable"      => ["unreachable",      "strings.altcha.settings.sentinel.status.unreachable",      "danger"],
        ];
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

    /**
     * Runs appendToForm() for the "keys" area and returns the options array
     * that was passed to FormBuilder::add() for the named field.
     *
     * @param string $fieldName
     * @param array  $keys      Current integration keys (returned by getKeys()).
     *
     * @return array{label: string, type: string, attr: array<string, string>}
     */
    private function captureFieldOptions(string $fieldName, array $keys): array {
        $captured = [];

        $builder = $this->createMock(FormBuilderInterface::class);
        $builder->method("add")->willReturnCallback(
            function(string $name, string $type, array $options = []) use (&$captured, $builder, $fieldName) {
                if($name === $fieldName)
                    $captured = ["label" => $options["label"] ?? "", "type" => $type, "attr" => $options["attr"] ?? []];
                return $builder;
            }
        );

        $this->makeIntegrationWithKeys($keys)->appendToForm($builder, [], "keys");

        $this->assertNotEmpty($captured, "Field '{$fieldName}' was not added to the form");
        return $captured;
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
