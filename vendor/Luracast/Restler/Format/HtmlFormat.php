<?php
namespace Luracast\Restler\Format;

use Exception;
use Luracast\Restler\Data\Object;
use Luracast\Restler\Defaults;
use Luracast\Restler\RestException;
use Luracast\Restler\Restler;
use Luracast\Restler\UI\Nav;
use Luracast\Restler\Util;

/**
 * Html template format
 *
 * @category   Framework
 * @package    Restler
 * @subpackage format
 * @author     R.Arul Kumaran <arul@luracast.com>
 * @copyright  2010 Luracast
 * @license    http://www.opensource.org/licenses/lgpl-license.php LGPL
 * @link       http://luracast.com/products/restler/
 * @version    3.0.0rc4
 */
class HtmlFormat extends Format
{
    public static $mime = 'text/html';
    public static $extension = 'html';
    public static $view;
    public static $errorView = 'debug.php';
    public static $format = 'php';
    /**
     * @var array global key value pair to be supplied to the templates. All
     * keys added here will be available as a variable inside the template
     */
    public static $data = array();
    /**
     * @var string set it to the location of your the view files. Defaults to
     * views folder which is same level as vendor directory.
     */
    public static $viewPath;
    protected static $parseViewMetadata = true;
    /**
     * @var Restler;
     */
    public $restler;

    public function __construct()
    {
        if (!static::$viewPath) {
            $array = explode('vendor', __DIR__, 2);
            static::$viewPath = $array[0] . 'views';
        }
        if (!is_readable(static::$viewPath)) {
            throw new \Exception(
                'The views directory `'
                . self::$viewPath . '` should exist with read permission.'
            );
        }
        static::$data['basePath'] = dirname($_SERVER['SCRIPT_NAME']);
        static::$data['baseUrl'] = Util::$restler->getBaseUrl();
        static::$data['currentPath'] = Util::$restler->url;
    }

    public static function twig(array $data, $debug = true)
    {
        if (!class_exists('\Twig_Environment', true))
            throw new RestException(500,
                'Twig templates require twig classes to be installed using `composer install`');
        $loader = new \Twig_Loader_Filesystem(static::$viewPath);
        $twig = new \Twig_Environment($loader, array(
            'cache' => Defaults::$cacheDirectory,
            'debug' => $debug,
            'use_strict_variables' => $debug,
        ));
        if ($debug)
            $twig->addExtension(new \Twig_Extension_Debug());

        $twig->addFunction(
            new \Twig_SimpleFunction(
                'form',
                'Luracast\Restler\UI\Forms::get',
                array('is_safe' => array('html'))
            )
        );
        $twig->addFunction(
            new \Twig_SimpleFunction(
                'nav',
                'Luracast\Restler\UI\Nav::get'
            )
        );

        $twig->registerUndefinedFunctionCallback(function ($name) {
            if (
                isset(HtmlFormat::$data[$name]) &&
                is_callable(HtmlFormat::$data[$name])
            ) {
                return new \Twig_SimpleFunction(
                    $name,
                    HtmlFormat::$data[$name]
                );
            }
            return false;
        });

        $template = $twig->loadTemplate(self::$view);
        return $template->render($data);
    }

    public static function handlebar(array $data, $debug = true)
    {
        return static::mustache($data, $debug);
    }

    public static function mustache(array $data, $debug = true)
    {
        if (!class_exists('\Mustache_Engine', true))
            throw new RestException(
                500,
                'Mustache/Handlebar templates require mustache classes ' .
                'to be installed using `composer install`'
            );
        if (!isset($data['nav']))
            $data['nav'] = array_values(Nav::get());
        $options = array(
            'loader' => new \Mustache_Loader_FilesystemLoader(
                    static::$viewPath,
                    array('extension' => static::$format)
                ),
            'helpers' => array(
                'form' => function ($text, \Mustache_LambdaHelper $m) {
                        $params = explode(',', $m->render($text));
                        return call_user_func_array(
                            'Luracast\Restler\UI\Forms::get',
                            $params
                        );
                    },
            )
        );
        if (!$debug)
            $options['cache'] = Defaults::$cacheDirectory;
        $m = new \Mustache_Engine($options);
        return $m->render(self::$view, $data);
    }

    public static function php(array $data, $debug = true)
    {
        $view = self::$viewPath . DIRECTORY_SEPARATOR .
            self::$view;

        if (!is_readable($view)) {
            throw new RestException(
                500,
                "view file `$view` is not readable. " .
                'Check for file presence and file permissions'
            );
        }

        $path = static::$viewPath . DIRECTORY_SEPARATOR;
        $template = function ($view) use ($data, $path) {
            $form = function () {
                return call_user_func_array(
                    'Luracast\Restler\UI\Forms::get',
                    func_get_args()
                );
            };
            if (!isset($data['form']))
                $data['form'] = $form;
            $nav = function () {
                return call_user_func_array(
                    'Luracast\Restler\UI\Nav::get',
                    func_get_args()
                );
            };
            if (!isset($data['nav']))
                $data['nav'] = $nav;

            $_ = function () use ($data, $path) {
                extract($data);
                $args = func_get_args();
                $task = array_shift($args);
                switch ($task) {
                    case 'require':
                    case 'include':
                        $file = $path . $args[0];
                        if (is_readable($file)) {
                            if (
                                isset($args[1]) &&
                                ($arrays = Util::nestedValue($data, $args[1]))
                            ) {
                                $str = '';
                                foreach ($arrays as $arr) {
                                    extract($arr);
                                    $str .= include $file;
                                }
                                return $str;
                            } else {
                                return include $file;
                            }
                        }
                        break;
                    case 'if':
                        if (count($args) < 2)
                            $args[1] = '';
                        if (count($args) < 3)
                            $args[2] = '';
                        return $args[0] ? $args[1] : $args[2];
                        break;
                    default:
                        if (isset($data[$task]) && is_callable($data[$task]))
                            return call_user_func_array($data[$task], $args);
                }
                return '';
            };
            extract($data);
            return @include $view;
        };
        $value = $template($view);
        if (is_string($value))
            return $value;
    }

    /**
     * Encode the given data in the format
     *
     * @param array   $data                resulting data that needs to
     *                                     be encoded in the given format
     * @param boolean $humanReadable       set to TRUE when restler
     *                                     is not running in production mode.
     *                                     Formatter has to make the encoded
     *                                     output more human readable
     *
     * @throws \Exception
     * @return string encoded string
     */
    public function encode($data, $humanReadable = false)
    {
        try {
            $exception = $this->restler->exception;
            $success = is_null($exception);
            $error = $success ? null : $exception->getMessage();
            $data = array(
                'response' => Object::toArray($data),
                'stages' => $this->restler->getEvents(),
                'success' => $success,
                'error' => $error
            );
            $info = $data['api'] = $this->restler->apiMethodInfo;
            $metadata = Util::nestedValue(
                $this->restler, 'apiMethodInfo', 'metadata'
            );
            $view = $success ? 'view' : 'errorView';
            $value = false;
            if (static::$parseViewMetadata && isset($metadata[$view])) {
                if (is_array($metadata[$view])) {
                    self::$view = $metadata[$view]['description'];
                    $value = Util::nestedValue(
                        $metadata[$view], 'properties', 'value'
                    );
                } else {
                    self::$view = $metadata[$view];
                }
            } elseif (!self::$view) {
                self::$view = static::$errorView;
            }
            if (
                isset($metadata['param'])
                && (!$value || 0 === strpos($value, 'request'))
            ) {
                $params = $metadata['param'];
                foreach ($params as $index => &$param) {
                    $index = intval($index);
                    if (is_numeric($index)) {
                        $param['value'] = $this
                            ->restler
                            ->apiMethodInfo
                            ->parameters[$index];
                    }
                }
                $data['request']['parameters'] = $params;
            }
            if ($value) {
                $data = Util::nestedValue($data, explode('.', $value));
            }
            $data += static::$data;
            if (false === ($i = strpos(self::$view, '.'))) {
                $extension = self::$format;
                self::$view .= '.' . $extension;
            } else {
                $extension = substr(self::$view, $i + 1);
            }
            if (method_exists(__CLASS__, $extension)) {
                return call_user_func(__CLASS__ . '::' . $extension, $data, $humanReadable);
            }
            throw new RestException(500, "Unsupported template system `$extension`");
        } catch (Exception $e) {
            static::$parseViewMetadata = false;
            $this->reset();
            throw $e;
        }
    }

    private function reset()
    {
        static::$mime = 'text/html';
        static::$extension = 'html';
        static::$view = 'debug';
        static::$format = 'php';
    }

    /**
     * Decode the given data from the format
     *
     * @param string $data
     *            data sent from client to
     *            the api in the given format.
     *
     * @return array associative array of the parsed data
     *
     * @throws RestException
     */
    public function decode($data)
    {
        throw new RestException(500, 'HtmlFormat is write only');
    }

    /**
     * @return bool false as HTML format is write only
     */
    public function isReadable()
    {
        return false;
    }

    /**
     * Get MIME type => Extension mappings as an associative array
     *
     * @return array list of mime strings for the format
     * @example array('application/json'=>'json');
     */
    public function getMIMEMap()
    {
        return array(
            static::$mime => static::$extension
        );
    }

    /**
     * Set the selected MIME type
     *
     * @param string $mime MIME type
     */
    public function setMIME($mime)
    {
        static::$mime = $mime;
    }

    /**
     * Get selected MIME type
     */
    public function getMIME()
    {
        return static::$mime;
    }

    /**
     * Get the selected file extension
     *
     * @return string file extension
     */
    public function getExtension()
    {
        return static::$extension;
    }

    /**
     * Set the selected file extension
     *
     * @param string $extension file extension
     */
    public function setExtension($extension)
    {
        static::$extension = $extension;
    }
}
