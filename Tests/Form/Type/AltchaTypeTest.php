<?php declare(strict_types=1);

namespace MauticPlugin\MauticAltchaBundle\Tests\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\MauticAltchaBundle\Form\Type\AltchaType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Validator\Validation;

class AltchaTypeTest extends TestCase {

    private FormFactoryInterface $formFactory;

    protected function setUp(): void {
        $this->formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->addType(new YesNoButtonGroupType())
            ->getFormFactory();
    }

    /**
     * @test
     */
    public function testComplexityFieldAcceptsValidValues(): void {
        foreach (["low", "medium", "high"] as $complexity) {
            $form = $this->formFactory->create(AltchaType::class, ["complexity" => $complexity]);
            $form->submit(["complexity" => $complexity, "expire" => 600, "auto" => "onsubmit", "display" => "standard", "hideFooter" => false, "hideLogo" => false]);

            $this->assertTrue($form->isValid(), "Form invalid for complexity={$complexity}");
        }
    }

    /**
     * @test
     */
    public function testDisplayFieldAcceptsValidValues(): void {
        foreach (["standard", "bar", "floating", "overlay", "invisible"] as $display) {
            $form = $this->formFactory->create(AltchaType::class, ["display" => $display]);
            $form->submit(["complexity" => "medium", "expire" => 600, "auto" => "onsubmit", "display" => $display, "hideFooter" => false, "hideLogo" => false]);

            $this->assertTrue($form->isValid(), "Form invalid for display={$display}");
        }
    }

    /**
     * @test
     */
    public function testAutoFieldAcceptsValidValues(): void {
        foreach (["onload", "onsubmit", "off"] as $auto) {
            $form = $this->formFactory->create(AltchaType::class, ["auto" => $auto]);
            $form->submit(["complexity" => "medium", "expire" => 600, "auto" => $auto, "display" => "standard", "hideFooter" => false, "hideLogo" => false]);

            $this->assertTrue($form->isValid(), "Form invalid for auto={$auto}");
        }
    }

    /**
     * @test
     */
    public function testExpireFieldAcceptsIntegerValues(): void {
        foreach ([30, 120, 600, 3600] as $expire) {
            $form = $this->formFactory->create(AltchaType::class, ["expire" => $expire]);
            $form->submit(["complexity" => "medium", "expire" => $expire, "auto" => "onsubmit", "display" => "standard", "hideFooter" => false, "hideLogo" => false]);

            $this->assertTrue($form->isValid(), "Form invalid for expire={$expire}");
        }
    }

    /**
     * @test
     */
    public function testBlockPrefix(): void {
        $type = new AltchaType();
        $this->assertEquals("Altcha", $type->getBlockPrefix());
    }

}
