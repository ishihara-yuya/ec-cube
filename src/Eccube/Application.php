<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */


namespace Eccube;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Application extends \Silex\Application
{
    /** @var Application app */
    protected static $app;

    /**
     * Alias
     *
     * @return object
     */
    public static function alias($name)
    {
        $args = func_get_args();
        array_shift($args);
        $obj = static::$app[$name];

        if (is_callable($obj)) {
            return call_user_func_array($obj, $args);
        } else {
            return $obj;
        }
    }

    public function __construct(array $values = array())
    {
        $app = $this;
        static::$app = $this;
        ini_set('error_reporting', E_ALL | ~E_STRICT);

        parent::__construct($values);

        // set env
        if (!isset($app['env']) || empty($app['env'])) {
            $app['env'] = 'prod';
        }
        if ($app['env'] === 'dev' || $app['env'] === 'test') {
            $app['debug'] = true;
        }

        // load config
        $this['config'] = $app->share(function () {
            $config_file = __DIR__ . '/../../app/config/eccube/config.yml';
            if (file_exists($config_file)) {
                $config = Yaml::parse($config_file);
            } else {
                $config = array();
            }

            $constant_file = __DIR__ . '/../../app/config/eccube/constant.yml';
            $constant_dist = __DIR__ . '/../../app/config/eccube/constant.yml.dist';

            if (file_exists($constant_file)) {
                $config_constant = Yaml::parse($constant_file);
            } elseif (file_exists($constant_dist)) {
                $config_constant = Yaml::parse($constant_dist);
            } else {
                $config_constant = array();
            }

            return array_merge($config_constant, $config);
        });

        $this->register(new \Silex\Provider\ServiceControllerServiceProvider());
        $this->register(new \Silex\Provider\SessionServiceProvider());

        $this->register(new \Silex\Provider\TwigServiceProvider(), array(
            'twig.form.templates' => array('Form/form_layout.twig'),
        ));
        $app['twig'] = $app->share($app->extend("twig", function (\Twig_Environment $twig, \Silex\Application $app) {
            $twig->addExtension(new \Eccube\Twig\Extension\EccubeExtension($app));

            return $twig;
        }));
        $this->before(function (Request $request, \Silex\Application $app) {
            //
            $app['twig'] = $app->share($app->extend("twig", function (\Twig_Environment $twig, \Silex\Application $app) {
                $paths = array();
                if (strpos($app['request']->getPathInfo(), '/admin') === 0) {
                    if (file_exists(__DIR__ . '/../../template/admin')) {
                        $paths[] = __DIR__ . '/../../template/admin';
                    }
                    $paths[] = __DIR__ . '/Resource/template/admin';
                    $paths[] = __DIR__ . '/../../app/plugin';
                    $cache = __DIR__ . '/../../app/cache/twig/admin';
                } else {
                    if (file_exists(__DIR__ . '/../../template/' . $app['config']['template_name'])) {
                        $paths[] = __DIR__ . '/../../template/' . $app['config']['template_name'];
                    }
                    $paths[] = __DIR__ . '/Resource/template/default';
                    $paths[] = __DIR__ . '/../../app/plugin';
                    $cache = __DIR__ . '/../../app/cache/twig/' . $app['config']['template_name'];
                }
                $twig->setCache($cache);
                $app['twig.loader']->addLoader(new \Twig_Loader_Filesystem($paths));

                return $twig;
            }));

            //
            $BaseInfo = $app['eccube.repository.base_info']->get();
            $app["twig"]->addGlobal("BaseInfo", $BaseInfo);
        }, self::EARLY_EVENT);

        $app->on(\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER, function (\Symfony\Component\HttpKernel\Event\FilterControllerEvent $event) use ($app) {
            if (!$event->isMasterRequest()) {
                return;
            }

            $request = $event->getRequest();
            try {
                $PageLayout = $app['eccube.repository.page_layout']->getByRoutingName(10, $request->attributes->get('_route'));
            } catch (\Doctrine\ORM\NoResultException $e) {
                $PageLayout = $app['eccube.repository.page_layout']->newPageLayout(10);
            }
            $app["twig"]->addGlobal("PageLayout", $PageLayout);
            $app["twig"]->addGlobal("title", $PageLayout->getName());
        });

        $this->register(new \Silex\Provider\UrlGeneratorServiceProvider());
        $this->register(new \Silex\Provider\FormServiceProvider());
        $this->register(new \Silex\Provider\ValidatorServiceProvider());

        $this->register(new \Silex\Provider\TranslationServiceProvider(), array(
            'locale' => 'ja',
        ));
        $app['translator'] = $app->share($app->extend('translator', function ($translator, \Silex\Application $app) {
            $translator->addLoader('yaml', new \Symfony\Component\Translation\Loader\YamlFileLoader());
            $translator->addResource('yaml', __DIR__.'/Resource/locale/ja.yml', 'ja');

            return $translator;
        }));

        // インストールされてなければこれこまで読み込む
        if (!file_exists(__DIR__ . '/../../app/config/eccube/config.yml')) {
            $app->mount('', new ControllerProvider\InstallControllerProvider());
            $app->register(new ServiceProvider\EccubeServiceProvider());
            $app->error(function (\Exception $e, $code) use ($app) {
                if ($code === 404) {
                    return $app->redirect($app['url_generator']->generate('install'));
                } elseif ($app['debug']) {
                    return;
                }

                return $app['view']->render('error.twig', array(
                    'error' => 'エラーが発生しました.',
                ));
            });

            return;
        }

        // Mail
        $this['swiftmailer.option'] = $this['config']['mail'];
        $this->register(new \Silex\Provider\SwiftmailerServiceProvider());
        $this['mail.message'] = function () {
            return \Swift_Message::newInstance();
        };

        // ORM
        $this->register(new \Silex\Provider\DoctrineServiceProvider(), array(
            'db.options' => $this['config']['database']
        ));
        $this->register(new \Saxulum\DoctrineOrmManagerRegistry\Silex\Provider\DoctrineOrmManagerRegistryProvider());

        //Doctrine ORM
        $this->register(new \Dflydev\Silex\Provider\DoctrineOrm\DoctrineOrmServiceProvider(), array(
            "orm.proxies_dir" => __DIR__ . '/../../app/cache/doctrine',
            'orm.em.options' => array(
                'mappings' => array(
                    array(
                        'type' => 'yml',
                        'namespace' => 'Eccube\Entity',
                        'path' => array(
                            __DIR__ . '/Resource/doctrine',
                            __DIR__ . '/Resource/doctrine/master',
                        ),
                    ),
                ),
            ),
        ));

        $this->register(new ServiceProvider\EccubeServiceProvider());
        $this->register(new ServiceProvider\LegacyServiceProvider());

       // EventDispatcher
        $app['eccube.event.dispatcher'] = $app->share(function () {
            return new EventDispatcher();
        });

        // EventSubscriber
        $basePath = __DIR__ . '/../../app/plugin';
        $finder = Finder::create()
            ->in($basePath)
            ->directories()
            ->depth(0);

        // Plugin events / service
        foreach ($finder as $dir) {
            $config = Yaml::parse($dir->getRealPath() . '/config.yml');

            if ($config['enable'] === true) {
                // Type: Event
                if (isset($config['event'])) {
                    $class = '\\Plugin\\' . $config['name'] . '\\' . $config['event'];
                    $subscriber = new $class($app);
                    $app['eccube.event.dispatcher']->addSubscriber($subscriber);
                }

                // Type: ServiceProvider
                if (isset($config['service'])) {
                    foreach ($config['service'] as $service) {
                        $class = '\\Plugin\\' . $config['name'] . '\\ServiceProvider\\' . $service;
                        $app->register(new $class($app));
                    }
                }

                // Doctrine Extend
                if (isset($config['orm.path'])) {
                    $pathes = array();
                    foreach ($config['orm.path'] as $path) {
                        $pathes[] = $basePath . '/' . $config['name'] . $path;
                    }
                    $app['orm.em.options'] = $app->extend('orm.em.options', function ($options) use ($config, $pathes) {
                        $options['mappings'][] = array(
                            'type' => 'yml',
                            'namespace' => 'Plugin\\' . $config['name'] . '\\Entity',
                            'path' => $pathes,
                        );
                    });
                }
            }
        }

        // hook point
        $this->before(function (Request $request, Application $app) {
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.before');
        }, self::EARLY_EVENT);

        $this->before(function (Request $request, \Silex\Application $app) {
            $event = $app->parseController($request) . '.before';
            $app['eccube.event.dispatcher']->dispatch($event);
        });

        $this->after(function (Request $request, Response $response) use ($app) {
            $event = $app->parseController($request) . '.after';
            $app['eccube.event.dispatcher']->dispatch($event);
        });

        $this->after(function (Request $request, Response $response) use ($app) {
            $app['eccube.event.dispatcher']->dispatch('eccube.event.app.after');
        }, \Silex\Application::LATE_EVENT);

        $this->finish(function (Request $request, Response $response) use ($app) {
            $event = $app->parseController($request) . '.finish';
            $app['eccube.event.dispatcher']->dispatch($event);
        });
        
        // Security
        $this->register(new \Silex\Provider\SecurityServiceProvider(), array(
             'security.firewalls' => array(
                'admin' => array(
                    'pattern' => '^/admin',
                    'form' => array(
                        'login_path' => '/admin/login',
                        'check_path' => '/admin/login_check',
                        'username_parameter' =>  'login_id',
                        'password_parameter' => 'password',
                        'with_csrf' => true,
                        'use_forward' => true,
                    ),
                    'logout' => array(
                        'logout_path' => '/admin/logout',
                        'target_url' => '/admin/',
                    ),
                    'users' => $app['eccube.repository.member'],
                    'anonymous' => true,
                ),
                'customer' => array(
                    'pattern' => '^/',
                    'form' => array(
                        'login_path' => '/mypage/login',
                        'check_path' => '/login_check',
                        'username_parameter' =>  'login_email',
                        'password_parameter' => 'login_pass',
                        'with_csrf' => true,
                        'use_forward' => true,
                    ),
                    'logout' => array(
                        'logout_path' => '/logout',
                        'target_url' => '/',
                    ),
                    'users' => $app['eccube.repository.customer'],
                    'anonymous' => true,
                ),
             ),
        ));
        $app['security.access_rules'] = array(
            array('^/admin/login', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/admin', 'ROLE_ADMIN'),
            array('^/mypage/login', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/mypage/refusal_complete', 'IS_AUTHENTICATED_ANONYMOUSLY'),
            array('^/mypage', 'ROLE_USER'),
        );
        $app['eccube.password_encoder'] = $app->share(function ($app) {
            return new \Eccube\Security\Core\Encoder\PasswordEncoder($app['config']);
        });
        $app['security.encoder_factory'] = $app->share(function ($app) {
            return new \Symfony\Component\Security\Core\Encoder\EncoderFactory(array(
                'Eccube\Entity\Customer' => $app['eccube.password_encoder'],
                'Eccube\Entity\Member' => $app['eccube.password_encoder'],
            ));
        });
        $app['eccube.event_listner.security'] = $app->share(function ($app) {
            return new \Eccube\EventListner\SecurityEventListner($app['orm.em']);
        });
        $app['user'] = $app->share(function ($app) {
            $token = $app['security']->getToken();

            return ($token !== null) ? $token->getUser() : null;
        });

        $app['filesystem'] = function () {
            return new \Symfony\Component\Filesystem\Filesystem();
        };

        $app->register(new \Silex\Provider\MonologServiceProvider(), array(
            'monolog.logfile' => __DIR__ . '/../../app/log/site.log',
        ));

        // Silex Web Profiler
        if ($app['env'] === 'dev') {
            $app->register(new \Silex\Provider\WebProfilerServiceProvider(), array(
                'profiler.cache_dir' => __DIR__ . '/../../app/cache/profiler',
                'profiler.mount_prefix' => '/_profiler',
            ));
            $app->register(new \Saxulum\SaxulumWebProfiler\Provider\SaxulumWebProfilerProvider());
        }

        $app->mount('', new ControllerProvider\FrontControllerProvider());
        $app->mount('/admin', new ControllerProvider\AdminControllerProvider());
        $app->error(function (\Exception $e, $code) use ($app) {
            if ($app['debug']) {
                return;
            }

            switch ($code) {
                case 404:
                    break;
                default:
                    break;
            }

            return $app['view']->render('error.twig', array(
                'error' => 'エラーが発生しました.',
            ));
        });

        $this['callback_resolver'] = $this->share(function () use ($app) {
            return new LegacyCallbackResolver($app);
        });

        if ($app['env'] === 'test') {
            $app['session.test'] = true;
            $app['exception_handler']->disable();
        }
    }

    public function parseController(Request $request)
    {
        $route = str_replace('_', '.', $request->attributes->get('_route'));

        return 'eccube.event.controller.' . $route;
    }

    public function boot()
    {
        parent::boot();

        $app = $this;

        // constant 上書き
        $app['config'] = $app->share($app->extend("config", function ($config, \Silex\Application $app) {
            $constant_file = __DIR__ . '/../../app/config/eccube/constant.yml';
            if (is_readable($constant_file)) {
                $config_constant = Yaml::parse($constant_file);
            } else {
                $config_constant = $app['eccube.repository.master.constant']->getAll($config);
                if ($config_constant) {
                    file_put_contents($constant_file, Yaml::dump($config_constant));
                }
            }

            return array_merge($config_constant, $config);
        }));

        // ログイン時のイベント
        $app['dispatcher']->addListener(\Symfony\Component\Security\Http\SecurityEvents::INTERACTIVE_LOGIN, array($app['eccube.event_listner.security'], 'onInteractiveLogin'));
    }
}
