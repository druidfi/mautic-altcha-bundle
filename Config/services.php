<?php declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Mautic\CoreBundle\Helper\EncryptionHelper;

return static function(ContainerConfigurator $configurator): void {
    $services = $configurator->services()
                             ->defaults()
                             ->autowire()
                             ->autoconfigure()
                             ->public();

    // Mautic 7.2 dropped the legacy "mautic.helper.encryption" string alias.
    // config.php treats FQCN arguments as literal strings (not service references),
    // so we register a local alias that config.php can safely reference by string.
    $services->alias("mautic.altcha.helper.encryption", EncryptionHelper::class);

    $services->load("MauticPlugin\\MauticAltchaBundle\\", "../")
             ->exclude(sprintf("../{%s}", implode(",", MauticCoreExtension::DEFAULT_EXCLUDES)));
};
