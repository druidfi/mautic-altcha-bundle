<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Tests\EventListener;

use MauticPlugin\MauticAltchaBundle\EventListener\AltchaFormSubscriber;
use MauticPlugin\MauticAltchaBundle\Integration\AltchaIntegration;
use MauticPlugin\MauticAltchaBundle\Service\AltchaClient;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Event\ValidationEvent;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

class AltchaFormSubscriberTest extends TestCase {

    /**
     * A valid ALTCHA payload skips validation failure — no listeners registered,
     * no error set on the event.
     *
     * @test
     */
    public function testValidPayloadPassesValidation(): void {
        $registeredListeners = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method("addListener")
            ->willReturnCallback(function($event, $listener) use (&$registeredListeners) {
                $registeredListeners[] = $event;
            });

        $altchaClient = $this->createMock(AltchaClient::class);
        $altchaClient->method("isConfigured")->willReturn(true);
        $altchaClient->method("verify")->willReturn(true);

        $subscriber      = $this->createSubscriber($eventDispatcher, $altchaClient);
        $validationEvent = new ValidationEvent(new Field(), "valid-payload");

        $subscriber->onFormValidate($validationEvent);

        $this->assertTrue($validationEvent->isValid());
        $this->assertNotContains(LeadEvents::LEAD_POST_SAVE, $registeredListeners);
    }

    /**
     * An invalid payload fails validation and registers the LEAD_POST_SAVE cleanup listener.
     *
     * @test
     */
    public function testInvalidPayloadFailsValidation(): void {
        $registeredListeners = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method("addListener")
            ->willReturnCallback(function($event, $listener, $priority = 0) use (&$registeredListeners) {
                $registeredListeners[] = ["event" => $event, "listener" => $listener, "priority" => $priority];
            });

        $altchaClient = $this->createMock(AltchaClient::class);
        $altchaClient->method("isConfigured")->willReturn(true);
        $altchaClient->method("verify")->willReturn(false);

        $subscriber      = $this->createSubscriber($eventDispatcher, $altchaClient);
        $validationEvent = new ValidationEvent(new Field(), "bad-payload");

        $subscriber->onFormValidate($validationEvent);

        $this->assertFalse($validationEvent->isValid());

        $leadPostSave = array_filter($registeredListeners, fn($l) => $l["event"] === LeadEvents::LEAD_POST_SAVE);
        $this->assertNotEmpty($leadPostSave, "LEAD_POST_SAVE listener must be registered on failure");

        $listener = reset($leadPostSave);
        $this->assertEquals(-255, $listener["priority"]);
    }

    /**
     * Property test: new leads are deleted via kernel.terminate after failed validation.
     *
     * @test
     */
    public function testNewLeadIsDeletedAfterFailedValidation(): void {
        $failures = [];

        for ($i = 0; $i < 100; $i++) {
            $registeredListeners = [];

            $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
            $eventDispatcher->method("addListener")
                ->willReturnCallback(function($event, $listener, $priority = 0) use (&$registeredListeners) {
                    $registeredListeners[] = ["event" => $event, "listener" => $listener];
                });

            $altchaClient = $this->createMock(AltchaClient::class);
            $altchaClient->method("isConfigured")->willReturn(true);
            $altchaClient->method("verify")->willReturn(false);

            $leadDeleteCalled = false;
            $leadModel        = $this->createMock(LeadModel::class);
            $leadModel->method("deleteEntity")->willReturnCallback(function() use (&$leadDeleteCalled) {
                $leadDeleteCalled = true;
            });

            $subscriber = $this->createSubscriber($eventDispatcher, $altchaClient, $leadModel);
            $subscriber->onFormValidate(new ValidationEvent(new Field(), "bad"));

            // Simulate LEAD_POST_SAVE with a new lead
            $leadPostSaveListener = null;
            foreach ($registeredListeners as $l) {
                if ($l["event"] === LeadEvents::LEAD_POST_SAVE) {
                    $leadPostSaveListener = $l;
                    break;
                }
            }

            if ($leadPostSaveListener === null) {
                $failures[] = ["iteration" => $i, "reason" => "LEAD_POST_SAVE not registered"];
                continue;
            }

            $registeredListeners = [];
            $lead = new Lead();
            ($leadPostSaveListener["listener"])(new LeadEvent($lead, true));

            $kernelTerminateListener = null;
            foreach ($registeredListeners as $l) {
                if ($l["event"] === "kernel.terminate") {
                    $kernelTerminateListener = $l;
                    break;
                }
            }

            if ($kernelTerminateListener === null) {
                $failures[] = ["iteration" => $i, "reason" => "kernel.terminate not registered"];
                continue;
            }

            ($kernelTerminateListener["listener"])();

            if (!$leadDeleteCalled) {
                $failures[] = ["iteration" => $i, "reason" => "Lead not deleted"];
            }
        }

        $this->assertEmpty($failures, json_encode($failures, JSON_PRETTY_PRINT));
    }

    /**
     * Existing leads (isNew = false) must NOT be deleted after failed validation.
     *
     * @test
     */
    public function testExistingLeadIsNotDeleted(): void {
        $registeredListeners = [];

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method("addListener")
            ->willReturnCallback(function($event, $listener, $priority = 0) use (&$registeredListeners) {
                $registeredListeners[] = ["event" => $event, "listener" => $listener];
            });

        $altchaClient = $this->createMock(AltchaClient::class);
        $altchaClient->method("isConfigured")->willReturn(true);
        $altchaClient->method("verify")->willReturn(false);

        $leadDeleteCalled = false;
        $leadModel        = $this->createMock(LeadModel::class);
        $leadModel->method("deleteEntity")->willReturnCallback(function() use (&$leadDeleteCalled) {
            $leadDeleteCalled = true;
        });

        $subscriber = $this->createSubscriber($eventDispatcher, $altchaClient, $leadModel);
        $subscriber->onFormValidate(new ValidationEvent(new Field(), "bad"));

        // Find LEAD_POST_SAVE listener and fire it with isNew=false
        $leadPostSaveListener = null;
        foreach ($registeredListeners as $l) {
            if ($l["event"] === LeadEvents::LEAD_POST_SAVE) {
                $leadPostSaveListener = $l;
                break;
            }
        }
        $this->assertNotNull($leadPostSaveListener);

        $registeredListeners = [];
        ($leadPostSaveListener["listener"])(new LeadEvent(new Lead(), false));

        $hasKernelTerminate = !empty(array_filter($registeredListeners, fn($l) => $l["event"] === "kernel.terminate"));
        $this->assertFalse($hasKernelTerminate, "kernel.terminate must not be registered for existing leads");
        $this->assertFalse($leadDeleteCalled);
    }

    /**
     * When ALTCHA is not configured, validation always passes (field is not active).
     *
     * @test
     */
    public function testValidationSkippedWhenNotConfigured(): void {
        $altchaClient = $this->createMock(AltchaClient::class);
        $altchaClient->method("isConfigured")->willReturn(false);
        $altchaClient->expects($this->never())->method("verify");

        $subscriber      = $this->createSubscriber($this->createMock(EventDispatcherInterface::class), $altchaClient);
        $validationEvent = new ValidationEvent(new Field(), "");

        $subscriber->onFormValidate($validationEvent);

        $this->assertTrue($validationEvent->isValid());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createSubscriber(
        EventDispatcherInterface $eventDispatcher,
        AltchaClient $altchaClient,
        ?LeadModel $leadModel = null
    ): AltchaFormSubscriber {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method("trans")->willReturn("ALTCHA verification failed.");

        $integration = $this->createMock(AbstractIntegration::class);
        $integration->method("getKeys")->willReturn(["hmac_secret" => "test-key"]);
        $integration->method("getTranslator")->willReturn($translator);

        $integrationHelper = $this->createMock(IntegrationHelper::class);
        $integrationHelper->method("getIntegrationObject")
            ->with(AltchaIntegration::INTEGRATION_NAME)
            ->willReturn($integration);

        $request          = $this->createMock(Request::class);
        $request->request = new InputBag(["altcha" => "invalid-payload"]);

        $requestStack = $this->createMock(RequestStack::class);
        $requestStack->method("getCurrentRequest")->willReturn($request);

        return new AltchaFormSubscriber(
            $eventDispatcher,
            $altchaClient,
            $leadModel ?? $this->createMock(LeadModel::class),
            $requestStack,
            $integrationHelper
        );
    }

}
