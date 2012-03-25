<?php
/**
 * Normalize and merge menu options from controller annotations, menu items and tree configs
 *
 * @author      Stefan Zerkalica <zerkalica@gmail.com>
 * @category    Millwright
 * @package     MenuBundle
 * @subpackage  Config
 */

namespace Millwright\MenuBundle\Config;

use Symfony\Component\Routing\RouterInterface;
use Doctrine\Common\Annotations\Reader;
use JMS\SecurityExtraBundle\Annotation\SecureParam;
use JMS\SecurityExtraBundle\Annotation\Secure;
use Millwright\MenuBundle\Annotation\Menu;

/**
 * @author      Stefan Zerkalica <zerkalica@gmail.com>
 * @category    Millwright
 * @package     MenuBundle
 * @subpackage  Config
 */
class OptionMerger implements OptionMergerInterface
{
    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * @var Reader
     */
    protected $reader;

    public function __construct(
        RouterInterface $router,
        Reader $reader
    )
    {
        $this->router  = $router;
        $this->reader  = $reader;
    }

    protected function getDefaultParams()
    {
        return array(
            'uri'                => null,
            'label'              => null,
            'name'               => null,
            'attributes'         => array(),
            'linkAttributes'     => array(),
            'childrenAttributes' => array(),
            'labelAttributes'    => array(),
            'display'            => true,
            'displayChildren'    => true,
            'secureParams'        => array(),
            'roles'               => array(),
            'route'               => null,
            'routeAbsolute'       => false,
            'showNonAuthorized'   => false,
            'showAsText'          => false,
            'translateDomain'    => null,
            'translateParameters' => array(),
            'type'                => null,
        );
    }

    /**
     * Get action method by route name
     *
     * @param  string $name route name
     * @return \ReflectionMethod
     */
    protected function getActionMethod($name)
    {
        //@todo do not use getRouteCollection - not interface method
        // howto get controller and action name by route name ?
        $route = $this->router->getRouteCollection()->get($name);
        if (!$route) {
            return array();
        }

        $defaults = $route->getDefaults();
        if (!isset($defaults['_controller'])) {
            return array();
        }

        $params = explode('::', $defaults['_controller']);

        $class  = new \ReflectionClass($params[0]);
        $method = $class->getMethod($params[1]);

        return $method;
    }

    /**
     * Merge array of annotations to options
     *
     * @param  array $options
     * @param  array $annotations
     * @param  array[\ReflectionParameter] $arguments
     * @return array
     */
    protected function getAnnotations(array $annotations,
        array $arguments = array())
    {
        $options = array();
        foreach($annotations as $param) {
            if ($param instanceof SecureParam) {
                $options['secureParams'][$param->name] = $this->annotationToArray($param);
                /* @var $argument \ReflectionParameter */
                $argument  = $arguments[$param->name];
                $class     = $argument->getClass();
                $options['secureParams'][$param->name]['class'] = $class->getName();
            } else if ($param instanceof Secure || $param instanceof Menu ) {
                $options += $this->annotationToArray($param);
            }
        }

        return $options;
    }

    /**
     * Convert annotation object to array, remove empty values
     *
     * @param  Object $annotation
     * @return array
     */
    protected function annotationToArray($annotation)
    {
        $options = array();
        foreach((array) $annotation as $key => $value) {
            if ($value !== null) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * Detects controller::action by route name in options.
     *
     * Loads and merge options from annotations and menu config:
     * 1. From @Menu, @Secure, @SecureParam annotations of method
     * 2. From millwright_menu:items section of config
     * 3. From @Menu, @Secure, @SecureParam annotations of class
     * 4. Normalize empty params from array @see OptionMerger::getDefaultParams()
     *
     * @param  array $options
     * @param  array $parameters
     * @param  string $name
     * @return void
     */
    protected function merge(array & $options, array & $parameters, $name)
    {
        foreach($options as $key => $value) {
            if(empty($value)) {
                unset($options[$key]);
            }
        }

        if(isset($parameters[$name])) {
            $options += $parameters[$name];
        }

        if($name) {
            $annotationsOptions = array();
            $classAnnotations = array();
            $arguments        = array();

            if (empty($options['uri'])) {
                $route = isset($options['route']) ? $options['route'] : $name;
                $method = $this->getActionMethod($route);
                if ($method) {
                    $options += array('route' => $route);
                    foreach ($method->getParameters() as $argument) {
                        $arguments[$argument->getName()] = $argument;
                    }

                    $annotations = $this->reader->getMethodAnnotations($method);
                    $annotationsOptions = $this->getAnnotations($annotations, $arguments);

                    $class       = $method->getDeclaringClass();
                    $classAnnotations = $this->reader->getClassAnnotations($class);
                }
            }
            $annotationsOptions = array_merge($annotationsOptions, $this->getAnnotations($classAnnotations, $arguments));

            $options += $annotationsOptions;
            $options += $this->getDefaultParams();

            $parameters[$name] = $options;
            unset($parameters[$name]['children']);
        }

        $options += array(
            'children' => array(),
        );
        foreach($options['children'] as $name => & $child) {
            $this->merge($child, $parameters, $name);
        }
    }

    /**
     * {@inheritdoc}
     * @see Millwright\MenuBundle\Config.OptionMergerInterface::normalize()
     */
    public function normalize(array $menuOptions)
    {
        foreach($menuOptions['items'] as & $item) {
            foreach($item as $key => $value) {
                if(empty($value)) {
                    unset($item[$key]);
                }
            }
        }

        foreach($menuOptions['tree'] as $name => & $menu) {
            $this->merge($menu, $menuOptions['items'], $name);
            $menuOptions['items'][$name] = null;
        }


        return $menuOptions;
    }
}
