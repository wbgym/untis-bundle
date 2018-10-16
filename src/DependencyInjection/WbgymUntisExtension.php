<?php
/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2016 Leo Feyer
 *
 * @license LGPL-3.0+
 */

/**
 * Wbgym/UntisBundle
 *
 * @author Webteam WBGym <webteam@wbgym.de>
 * @package Untis Bundle
 * @license LGPL-3.0+
 */

namespace Wbgym\UntisBundle\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class WbgymUntisExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $mergedConfig, ContainerBuilder $container)
    {
        $config = new Configuration();
        $processedConfig = $this->processConfiguration($config, $mergedConfig);

        //Add parameters to Configuration
        $container->setParameter('wbgym_untis.api_url',$processedConfig['api_url']);
        $container->setParameter('wbgym_untis.school_code',$processedConfig['school_code']);
        $container->setParameter('wbgym_untis.username',$processedConfig['username']);
        $container->setParameter('wbgym_untis.password',$processedConfig['password']);
        $container->setParameter('wbgym_untis.client_name',$processedConfig['client_name']);
    }
}