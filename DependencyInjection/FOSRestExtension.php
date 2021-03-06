<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\DependencyInjection;

use FOS\RestBundle\View\ViewHandler;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\Validator\Constraint;

/**
 * @internal
 */
class FOSRestExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration($container->getParameter('kernel.debug'));
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration($container->getParameter('kernel.debug'));
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('view.xml');
        $loader->load('routing.xml');
        $loader->load('request.xml');
        $loader->load('serializer.xml');

        $container->getDefinition('fos_rest.routing.loader.controller')->addArgument($config['routing_loader']['default_format']);

        $container->getDefinition('fos_rest.routing.loader.yaml_collection')->replaceArgument(4, $config['routing_loader']['default_format']);
        $container->getDefinition('fos_rest.routing.loader.xml_collection')->replaceArgument(4, $config['routing_loader']['default_format']);

        $container->getDefinition('fos_rest.routing.loader.yaml_collection')->replaceArgument(2, $config['routing_loader']['include_format']);
        $container->getDefinition('fos_rest.routing.loader.xml_collection')->replaceArgument(2, $config['routing_loader']['include_format']);
        $container->getDefinition('fos_rest.routing.loader.reader.action')->replaceArgument(3, $config['routing_loader']['include_format']);
        $container->getDefinition('fos_rest.routing.loader.reader.action')->replaceArgument(5, $config['routing_loader']['prefix_methods']);

        foreach ($config['service'] as $key => $service) {
            if ('validator' === $service && empty($config['body_converter']['validate'])) {
                continue;
            }

            if (null !== $service) {
                if ('view_handler' === $key) {
                    $container->setAlias('fos_rest.'.$key, new Alias($service, true));
                } else {
                    $container->setAlias('fos_rest.'.$key, $service);
                }
            }
        }

        $this->loadForm($config, $loader, $container);
        $this->loadException($config, $loader, $container);
        $this->loadBodyConverter($config, $loader, $container);
        $this->loadView($config, $loader, $container);

        $this->loadBodyListener($config, $loader, $container);
        $this->loadFormatListener($config, $loader, $container);
        $this->loadVersioning($config, $loader, $container);
        $this->loadParamFetcherListener($config, $loader, $container);
        $this->loadAllowedMethodsListener($config, $loader, $container);
        $this->loadAccessDeniedListener($config, $loader, $container);
        $this->loadZoneMatcherListener($config, $loader, $container);

        // Needs RequestBodyParamConverter and View Handler loaded.
        $this->loadSerializer($config, $container);
    }

    private function loadForm(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!empty($config['disable_csrf_role'])) {
            $loader->load('forms.xml');

            $definition = $container->getDefinition('fos_rest.form.extension.csrf_disable');
            $definition->replaceArgument(1, $config['disable_csrf_role']);
            $definition->addTag('form.type_extension', ['extended_type' => FormType::class]);
        }
    }

    private function loadAccessDeniedListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($config['access_denied_listener']['enabled'] && !empty($config['access_denied_listener']['formats'])) {
            $loader->load('access_denied_listener.xml');

            $service = $container->getDefinition('fos_rest.access_denied_listener');

            if (!empty($config['access_denied_listener']['service'])) {
                $service->clearTag('kernel.event_subscriber');
            }

            $service->replaceArgument(0, $config['access_denied_listener']['formats']);
            $service->replaceArgument(1, $config['unauthorized_challenge']);
        }
    }

    private function loadAllowedMethodsListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($config['allowed_methods_listener']['enabled']) {
            if (!empty($config['allowed_methods_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.allowed_methods_listener');
                $service->clearTag('kernel.event_listener');
            }

            $loader->load('allowed_methods_listener.xml');

            $container->getDefinition('fos_rest.allowed_methods_loader')->replaceArgument(1, $config['cache_dir']);
        }
    }

    private function loadBodyListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($config['body_listener']['enabled']) {
            $loader->load('body_listener.xml');

            $service = $container->getDefinition('fos_rest.body_listener');

            if (!empty($config['body_listener']['service'])) {
                $service->clearTag('kernel.event_listener');
            }

            $service->replaceArgument(1, $config['body_listener']['throw_exception_on_unsupported_content_type']);
            $service->addMethodCall('setDefaultFormat', array($config['body_listener']['default_format']));

            $container->getDefinition('fos_rest.decoder_provider')->replaceArgument(1, $config['body_listener']['decoders']);

            $decoderServicesMap = array();

            foreach ($config['body_listener']['decoders'] as $id) {
                $decoderServicesMap[$id] = new Reference($id);
            }

            $decodersServiceLocator = ServiceLocatorTagPass::register($container, $decoderServicesMap);
            $container->getDefinition('fos_rest.decoder_provider')->replaceArgument(0, $decodersServiceLocator);

            $arrayNormalizer = $config['body_listener']['array_normalizer'];

            if (null !== $arrayNormalizer['service']) {
                $bodyListener = $container->getDefinition('fos_rest.body_listener');
                $bodyListener->addArgument(new Reference($arrayNormalizer['service']));
                $bodyListener->addArgument($arrayNormalizer['forms']);
            }
        }
    }

    private function loadFormatListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($config['format_listener']['enabled'] && !empty($config['format_listener']['rules'])) {
            $loader->load('format_listener.xml');

            if (!empty($config['format_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.format_listener');
                $service->clearTag('kernel.event_listener');
            }

            $container->setParameter(
                'fos_rest.format_listener.rules',
                $config['format_listener']['rules']
            );
        }
    }

    private function loadVersioning(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!empty($config['versioning']['enabled'])) {
            $loader->load('versioning.xml');

            $versionListener = $container->getDefinition('fos_rest.versioning.listener');
            $versionListener->replaceArgument(1, $config['versioning']['default_version']);

            $resolvers = [];
            if ($config['versioning']['resolvers']['query']['enabled']) {
                $resolvers['query'] = $container->getDefinition('fos_rest.versioning.query_parameter_resolver');
                $resolvers['query']->replaceArgument(0, $config['versioning']['resolvers']['query']['parameter_name']);
            }
            if ($config['versioning']['resolvers']['custom_header']['enabled']) {
                $resolvers['custom_header'] = $container->getDefinition('fos_rest.versioning.header_resolver');
                $resolvers['custom_header']->replaceArgument(0, $config['versioning']['resolvers']['custom_header']['header_name']);
            }
            if ($config['versioning']['resolvers']['media_type']['enabled']) {
                $resolvers['media_type'] = $container->getDefinition('fos_rest.versioning.media_type_resolver');
                $resolvers['media_type']->replaceArgument(0, $config['versioning']['resolvers']['media_type']['regex']);
            }

            $chainResolver = $container->getDefinition('fos_rest.versioning.chain_resolver');
            foreach ($config['versioning']['guessing_order'] as $resolver) {
                if (isset($resolvers[$resolver])) {
                    $chainResolver->addMethodCall('addResolver', [$resolvers[$resolver]]);
                }
            }
        }
    }

    private function loadParamFetcherListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($config['param_fetcher_listener']['enabled']) {
            if (!class_exists(Constraint::class)) {
                throw new \LogicException('Enabling the fos_rest.param_fetcher_listener option when the Symfony Validator component is not installed is not supported. Try installing the symfony/validator package.');
            }

            $loader->load('param_fetcher_listener.xml');

            if (!empty($config['param_fetcher_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.param_fetcher_listener');
                $service->clearTag('kernel.event_listener');
            }

            if ($config['param_fetcher_listener']['force']) {
                $container->getDefinition('fos_rest.param_fetcher_listener')->replaceArgument(1, true);
            }
        }
    }

    private function loadBodyConverter(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!$this->isConfigEnabled($container, $config['body_converter'])) {
            return;
        }

        $loader->load('request_body_param_converter.xml');

        if (!empty($config['body_converter']['validation_errors_argument'])) {
            $container->getDefinition('fos_rest.converter.request_body')->replaceArgument(4, $config['body_converter']['validation_errors_argument']);
        }
    }

    private function loadView(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!empty($config['view']['jsonp_handler'])) {
            $handler = new ChildDefinition($config['service']['view_handler']);
            $handler->setPublic(true);

            $jsonpHandler = new Reference('fos_rest.view_handler.jsonp');
            $handler->addMethodCall('registerHandler', ['jsonp', [$jsonpHandler, 'createResponse']]);
            $container->setDefinition('fos_rest.view_handler', $handler);

            $container->getDefinition('fos_rest.view_handler.jsonp')->replaceArgument(0, $config['view']['jsonp_handler']['callback_param']);

            if (empty($config['view']['mime_types']['jsonp'])) {
                $config['view']['mime_types']['jsonp'] = $config['view']['jsonp_handler']['mime_type'];
            }
        }

        if ($config['view']['mime_types']['enabled']) {
            $loader->load('mime_type_listener.xml');

            if (!empty($config['mime_type_listener']['service'])) {
                $service = $container->getDefinition('fos_rest.mime_type_listener');
                $service->clearTag('kernel.event_listener');
            }

            $container->getDefinition('fos_rest.mime_type_listener')->replaceArgument(0, $config['view']['mime_types']['formats']);
        }

        if ($config['view']['view_response_listener']['enabled']) {
            $loader->load('view_response_listener.xml');
            $service = $container->getDefinition('fos_rest.view_response_listener');

            if (!empty($config['view_response_listener']['service'])) {
                $service->clearTag('kernel.event_listener');
            }

            $service->replaceArgument(1, $config['view']['view_response_listener']['force']);
        }

        $formats = [];
        foreach ($config['view']['formats'] as $format => $enabled) {
            if ($enabled) {
                $formats[$format] = false;
            }
        }

        $container->getDefinition('fos_rest.routing.loader.yaml_collection')->replaceArgument(3, $formats);
        $container->getDefinition('fos_rest.routing.loader.xml_collection')->replaceArgument(3, $formats);
        $container->getDefinition('fos_rest.routing.loader.reader.action')->replaceArgument(4, $formats);

        if (!is_numeric($config['view']['failed_validation'])) {
            $config['view']['failed_validation'] = constant(sprintf('%s::%s', Response::class, $config['view']['failed_validation']));
        }

        if (!is_numeric($config['view']['empty_content'])) {
            $config['view']['empty_content'] = constant(sprintf('%s::%s', Response::class, $config['view']['empty_content']));
        }

        $defaultViewHandler = $container->getDefinition('fos_rest.view_handler.default');
        $defaultViewHandler->setFactory([ViewHandler::class, 'create']);
        $defaultViewHandler->setArguments([
            new Reference('fos_rest.router'),
            new Reference('fos_rest.serializer'),
            new Reference('request_stack'),
            $formats,
            $config['view']['failed_validation'],
            $config['view']['empty_content'],
            $config['view']['serialize_null'],
        ]);
    }

    private function loadException(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if ($config['exception']['enabled']) {
            $loader->load('exception_listener.xml');

            if (!empty($config['exception']['service'])) {
                $service = $container->getDefinition('fos_rest.exception_listener');
                $service->clearTag('kernel.event_subscriber');
            }

            $container->getDefinition('fos_rest.exception_listener')->replaceArgument(0, $config['exception']['exception_controller']);

            $container->getDefinition('fos_rest.exception.codes_map')
                ->replaceArgument(0, $config['exception']['codes']);
            $container->getDefinition('fos_rest.exception.messages_map')
                ->replaceArgument(0, $config['exception']['messages']);

            $container->getDefinition('fos_rest.exception.controller')
                ->replaceArgument(2, $config['exception']['debug']);
            $container->getDefinition('fos_rest.serializer.exception_normalizer.jms')
                ->replaceArgument(1, $config['exception']['debug']);
            $container->getDefinition('fos_rest.serializer.exception_normalizer.symfony')
                ->replaceArgument(1, $config['exception']['debug']);
        }
    }

    private function loadSerializer(array $config, ContainerBuilder $container): void
    {
        $bodyConverter = $container->hasDefinition('fos_rest.converter.request_body') ? $container->getDefinition('fos_rest.converter.request_body') : null;
        $viewHandler = $container->getDefinition('fos_rest.view_handler.default');
        $options = array();

        if (!empty($config['serializer']['version'])) {
            if ($bodyConverter) {
                $bodyConverter->replaceArgument(2, $config['serializer']['version']);
            }
            $options['exclusionStrategyVersion'] = $config['serializer']['version'];
        }

        if (!empty($config['serializer']['groups'])) {
            if ($bodyConverter) {
                $bodyConverter->replaceArgument(1, $config['serializer']['groups']);
            }
            $options['exclusionStrategyGroups'] = $config['serializer']['groups'];
        }

        $options['serializeNullStrategy'] = $config['serializer']['serialize_null'];
        $viewHandler->addArgument($options);
    }

    private function loadZoneMatcherListener(array $config, XmlFileLoader $loader, ContainerBuilder $container): void
    {
        if (!empty($config['zone'])) {
            $loader->load('zone_matcher_listener.xml');
            $zoneMatcherListener = $container->getDefinition('fos_rest.zone_matcher_listener');

            foreach ($config['zone'] as $zone) {
                $matcher = $this->createZoneRequestMatcher(
                    $container,
                    $zone['path'],
                    $zone['host'],
                    $zone['methods'],
                    $zone['ips']
                );

                $zoneMatcherListener->addMethodCall('addRequestMatcher', array($matcher));
            }
        }
    }

    private function createZoneRequestMatcher(ContainerBuilder $container, ?string $path = null, ?string $host = null, array $methods = array(), array $ips = null): Reference
    {
        if ($methods) {
            $methods = array_map('strtoupper', (array) $methods);
        }

        $serialized = serialize(array($path, $host, $methods, $ips));
        $id = 'fos_rest.zone_request_matcher.'.md5($serialized).sha1($serialized);

        // only add arguments that are necessary
        $arguments = array($path, $host, $methods, $ips);
        while (count($arguments) > 0 && !end($arguments)) {
            array_pop($arguments);
        }

        $container
            ->setDefinition($id, new ChildDefinition('fos_rest.zone_request_matcher'))
            ->setArguments($arguments)
        ;

        return new Reference($id);
    }
}
