<?php
/*
 * Copyright (c) KUBO Atsuhiro <kubo@iteman.jp>,
 * All rights reserved.
 *
 * This file is part of PHPMentorsWorkflowerBundle.
 *
 * This program and the accompanying materials are made available under
 * the terms of the BSD 2-Clause License which accompanies this
 * distribution, and is available at http://opensource.org/licenses/BSD-2-Clause
 */

namespace PHPMentors\WorkflowerBundle\DependencyInjection;

use PHPMentors\Workflower\Definition\Bpmn2File;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class PHPMentorsWorkflowerExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $loader = new XmlFileLoader($container, new FileLocator(dirname(__DIR__).'/Resources/config'));
        $loader->load('services.xml');

        $this->transformConfigToContainer($config, $container);
    }

    /**
     * {@inheritdoc}
     */
    public function getAlias()
    {
        return 'phpmentors_workflower';
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     */
    private function transformConfigToContainer(array $config, ContainerBuilder $container)
    {
        $childDefinitionClass = $this->getChildDefinitionClass();
        
        $workflowSerializerDefinition = $container->getDefinition('phpmentors_workflower.workflow_serializer');
        $container->setAlias('phpmentors_workflower.workflow_serializer', $config['serializer_service']);
        $container->getAlias('phpmentors_workflower.workflow_serializer')->setPublic($workflowSerializerDefinition->isPublic());

        foreach ($config['workflow_contexts'] as $workflowContextId => $workflowContext) {
            $workflowContextIdHash = sha1($workflowContextId);
            $bpmn2WorkflowRepositoryDefinition = new $childDefinitionClass('phpmentors_workflower.bpmn2_workflow_repository');
            $bpmn2WorkflowRepositoryServiceId = 'phpmentors_workflower.bpmn2_workflow_repository.'.$workflowContextIdHash;
            $container->setDefinition($bpmn2WorkflowRepositoryServiceId, $bpmn2WorkflowRepositoryDefinition);

            $definitionFiles = Finder::create()
                ->files()
                ->in($workflowContext['definition_dir'])
                ->depth('== 0')
                ->sortByName()
                ;
            foreach ($definitionFiles as $definitionFile) {
                $workflowId = Bpmn2File::getWorkflowId($definitionFile->getFilename());
                $bpmn2FileDefinition = new $childDefinitionClass('phpmentors_workflower.bpmn2_file');
                $bpmn2FileDefinition->setArguments(array($definitionFile->getPathname()));
                $bpmn2FileServiceId = 'phpmentors_workflower.bpmn2_file.'.sha1($workflowContextId.$workflowId);
                $container->setDefinition($bpmn2FileServiceId, $bpmn2FileDefinition);

                $bpmn2WorkflowRepositoryDefinition->addMethodCall('add', array(new Reference($bpmn2FileServiceId)));

                $workflowContextDefinition = new $childDefinitionClass('phpmentors_workflower.workflow_context');
                $workflowContextDefinition->setArguments(array($workflowContextId, $workflowId));
                $workflowContextServiceId = 'phpmentors_workflower.workflow_context.'.sha1($workflowContextId.$workflowId);
                $container->setDefinition($workflowContextServiceId, $workflowContextDefinition);

                $processDefinition = new $childDefinitionClass('phpmentors_workflower.process');
                $processDefinition->setArguments(array(
                    new Reference($workflowContextServiceId),
                    new Reference($bpmn2WorkflowRepositoryServiceId),
                    new Reference('phpmentors_workflower.operation_runner_delegate'),
                ));
                $processServiceId = 'phpmentors_workflower.process.'.sha1($workflowContextId.$workflowId);
                $container->setDefinition($processServiceId, $processDefinition);

                $container->getDefinition('phpmentors_workflower.process_factory')->addMethodCall('addProcess', array(new Reference($processServiceId)));
            }
        }
    }
    
    private function getChildDefinitionClass()
    {
        if (class_exists('Symfony\Component\DependencyInjection\ChildDefinition')) {
            return 'Symfony\Component\DependencyInjection\ChildDefinition';
        }
        
        return 'Symfony\Component\DependencyInjection\DefinitionDecorator';
    }
}
