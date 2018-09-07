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

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Adds the WebUntis bundle configuration structure.
 */
class Configuration implements ConfigurationInterface
{
	 /**
     * Generates the configuration tree builder.
     *
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
    	$treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('wbgym_untis');

        $rootNode
        	->children()
        		->scalarNode('api_url')
        			->cannotBeEmpty()
        			->end()
        		->scalarNode('school_code')
        			->cannotBeEmpty()
        			->end()
        		->scalarNode('username')
        			->cannotBeEmpty()
        			->end()
        		->scalarNode('password')
        			->cannotBeEmpty()
        			->end()
        		->scalarNode('client_name')
        			->cannotBeEmpty()
        			->end()
        	->end()
        ;
        return $treeBuilder;
    }
}