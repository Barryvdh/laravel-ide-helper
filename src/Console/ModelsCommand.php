<?php
/**
 * Laravel IDE Helper Generator
 *
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>
 * @copyright 2014 Barry vd. Heuvel / Fruitcake Studio (http://www.fruitcakestudio.nl)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/barryvdh/laravel-ide-helper
 */

namespace Barryvdh\LaravelIdeHelper\Console;

use Barryvdh\LaravelIdeHelper\Method;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\ClassLoader\ClassMapGenerator;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Context;
use phpDocumentor\Reflection\DocBlock\Tag;

/**
 * A command to generate autocomplete information for your IDE
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 * @property \Illuminate\Container\Container $laravel
 */
class ModelsCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $name = 'ide-helper:models';
    protected $filename = '_ide_helper_models.php';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Generate autocompletion for models';

    protected $properties = array();
    protected $methods = array();
    protected $write = false;
    protected $dirs = array();
    protected $reset;

    /** @var \Illuminate\Filesystem\Filesystem */
    protected $files;

    /**
     * {@inheritdoc}
     *
     * @param \Illuminate\Container\Container $app
     */
    public function __construct($app)
    {
        $this->laravel = $app;
        $this->files = $app['files'];
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function fire()
    {
        $filename = $this->option('filename');
        $this->write = $this->option('write');
        $this->dirs = array_merge(
            $this->laravel['config']->get('laravel-ide-helper::model_locations'),
            $this->option('dir')
        );

        $model = $this->argument('model');
        $ignore = $this->option('ignore');
        $this->reset = $this->option('reset');

        //If filename is default and Write is not specified, ask what to do
        if (!$this->write && $filename === $this->filename && !$this->option('nowrite')) {
            if ($this->confirm("Do you want to overwrite the existing model files? Choose no to write to $filename instead? (Yes/No): ")) {
                $this->write = true;
            }
        }

        $content = $this->generateDocs($model, $ignore);

        if (!$this->write) {
            $written = $this->files->put($filename, $content);
            if ($written !== false) {
                $this->info("Model information was written to $filename");
            } else {
                $this->error("Failed to write model information to $filename");
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getArguments()
    {
        return array(
            array('model', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Which models to include', array()),
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getOptions()
    {
        return array(
            array('filename', 'F', InputOption::VALUE_OPTIONAL, 'The path to the helper file', $this->filename),
            array('dir', 'D', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The model dir', array()),
            array('write', 'W', InputOption::VALUE_NONE, 'Write to Model file'),
            array('nowrite', 'N', InputOption::VALUE_NONE, 'Don\'t write to Model file'),
            array('reset', 'R', InputOption::VALUE_NONE, 'Remove the original phpdocs instead of appending'),
            array('ignore', 'I', InputOption::VALUE_OPTIONAL, 'Which models to ignore', ''),
        );
    }

    protected function generateDocs($loadModels, $ignore = '')
    {
        $output = '<?php
/**
 * An helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */

';

        $hasDoctrine = interface_exists('Doctrine\DBAL\Driver');

        if (empty($loadModels)) {
            $models = $this->loadModels();
        } else {
            $models = array();
            foreach ($loadModels as $model) {
                $models = array_merge($models, explode(',', $model));
            }
        }

        $ignore = explode(',', $ignore);

        foreach ($models as $name) {
            if (in_array($name, $ignore)) {
                $this->comment("Ignoring model '$name'");
                continue;
            }

            $this->properties = array();
            $this->methods = array();
            if (class_exists($name)) {
                try {
                    // handle abstract classes, interfaces, ...
                    $reflectionClass = new \ReflectionClass($name);

                    if (!$reflectionClass->isSubclassOf('Illuminate\Database\Eloquent\Model')) {
                        continue;
                    }

                    $this->comment("Loading model '$name'");

                    if (!$reflectionClass->isInstantiable()) {
                        throw new \Exception($name . ' is not instantiable.');
                    }

                    $model = $this->laravel->make($name);

                    if ($hasDoctrine) {
                        $this->getPropertiesFromTable($model);
                    }

                    $this->getPropertiesFromMethods($model);
                    $output .= $this->createPhpDocs($name);
                } catch (\Exception $e) {
                    $this->error('Exception: ' . $e->getMessage() . "\nCould not analyze class $name.");
                }
            }
        }

        if (!$hasDoctrine) {
            $this->error(
                'Warning: `"doctrine/dbal": "~2.3"` is required to load database information. Please require that in your composer.json and run `composer update`.'
            );
        }

        return $output;
    }

    protected function loadModels()
    {
        $basePath = $this->laravel['path.base'];
        $models = array();

        foreach ($this->dirs as $dir) {
            $dir = $basePath . '/' . ltrim($dir, '/');
            if ($this->files->exists($dir)) {

                foreach (ClassMapGenerator::createMap($dir) as $model => $path) {
                    $models[] = $model;
                }
            }
        }

        return $models;
    }

    /**
     * Load the properties from the database table.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromTable($model)
    {
        $table = $model->getConnection()->getTablePrefix() . $model->getTable();
        $schema = $model->getConnection()->getDoctrineSchemaManager($table);
        $schema->getDatabasePlatform()->registerDoctrineTypeMapping('enum', 'string');

        $columns = $schema->listTableColumns($table);
        if (!$columns) return;

        foreach ($columns as $column) {
            $name = $column->getName();
            if (in_array($name, $model->getDates())) {
                $type = '\Carbon\Carbon';
            } else {
                $type = $column->getType()->getName();
                switch ($type) {
                    case 'string':
                    case 'text':
                    case 'date':
                    case 'time':
                    case 'guid':
                    case 'datetimetz':
                    case 'datetime':
                        $type = 'string';
                        break;
                    case 'integer':
                    case 'bigint':
                    case 'smallint':
                        $type = 'integer';
                        break;
                    case 'decimal':
                    case 'float':
                        $type = 'float';
                        break;
                    case 'boolean':
                        $type = 'boolean';
                        break;
                    default:
                        $type = 'mixed';
                        break;
                }
            }

            $comment = $column->getComment();
            $this->setProperty($name, $type, true, true, $comment);
            $this->setMethod(
                Str::camel('where_' . $name),
                '\Illuminate\Database\Query\Builder|\\' . get_class($model),
                array('$value')
            );
        }
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     */
    protected function getPropertiesFromMethods($model)
    {
        $methods = get_class_methods($model);
        if (!$methods) return;

        foreach ($methods as $method) {
            if (Str::startsWith($method, 'get') && Str::endsWith($method, 'Attribute') && $method !== 'getAttribute') {
                //Magic get<name>Attribute
                $name = Str::snake(substr($method, 3, -9));
                if (!empty($name)) {
                    $this->setProperty($name, null, true, null);
                }
            } elseif (Str::startsWith($method, 'set') && Str::endsWith($method, 'Attribute') && $method !== 'setAttribute') {
                //Magic set<name>Attribute
                $name = Str::snake(substr($method, 3, -9));
                if (!empty($name)) {
                    $this->setProperty($name, null, null, true);
                }
            } elseif (Str::startsWith($method, 'scope') && $method !== 'scopeQuery') {
                //Magic scope<name>
                $name = Str::camel(substr($method, 5));
                if (!empty($name)) {
                    $reflection = new \ReflectionMethod($model, $method);
                    list(, $args) = Method::getParameters($reflection);
                    //Remove the first ($query) argument
                    array_shift($args);
                    $this->setMethod($name, '\\' . $reflection->class, $args);
                }
            } elseif (!method_exists('Eloquent', $method) && !Str::startsWith($method, 'get')) {

                //Use reflection to inspect the code, based on Illuminate/Support/SerializableClosure.php
                $reflection = new \ReflectionMethod($model, $method);

                $file = new \SplFileObject($reflection->getFileName());
                $file->seek($reflection->getStartLine());

                $code = '';
                while ($file->key() < $reflection->getEndLine()) {
                    $code .= $file->current();
                    $file->next();
                }

                unset($file);
                $code = substr($code, 0, strrpos($code, '}') + 1);

                foreach (array('hasMany', 'belongsToMany', 'hasOne', 'belongsTo', 'morphTo', 'morphMany', 'morphToMany') as $relation) {
                    $search = '$this->' . $relation . '(';
                    if (!($pos = stripos($code, $search)))
                        continue;

                    $code = substr($code, $pos + strlen($search));
                    $arguments = explode(',', substr($code, 0, strpos($code, ');')));
                    //Remove quotes, ensure 1 \ in front of the model
                    $returnModel = $this->getClassName($arguments[0], $model);
                    if ($relation === 'belongsToMany' || $relation === 'hasMany' || $relation === 'morphMany' || $relation === 'morphToMany') {
                        //Collection or array of models (because Collection is Arrayable)
                        $this->setProperty(
                            $method,
                            '\Illuminate\Database\Eloquent\Collection|' . $returnModel . '[]',
                            true,
                            null
                        );
                    } else {
                        //Single model is returned
                        $this->setProperty($method, $returnModel, true, null);
                    }
                }

            }
        }
    }

    /**
     * @param string $name
     * @param string|null $type
     * @param bool|null $read
     * @param bool|null $write
     * @param string|null $comment
     */
    protected function setProperty($name, $type = null, $read = null, $write = null, $comment = '')
    {
        if (!isset($this->properties[$name])) {
            $this->properties[$name] = array(
                'type' => 'mixed',
                'read' => false,
                'write' => false,
                'comment' => (string)$comment,
            );
        }
        if ($type !== null) {
            $this->properties[$name]['type'] = $type;
        }
        if ($read !== null) {
            $this->properties[$name]['read'] = $read;
        }
        if ($write !== null) {
            $this->properties[$name]['write'] = $write;
        }
    }

    protected function setMethod($name, $type = '', $arguments = array())
    {
        if (!isset($this->methods[$name])) {
            $this->methods[$name] = compact('type', 'arguments');
        }
    }

    /**
     * @param string $class
     * @return string
     */
    protected function createPhpDocs($class)
    {
        $reflection = new \ReflectionClass($class);
        $namespace = $reflection->getNamespaceName();
        $className = $reflection->getShortName();
        $originalDoc = $reflection->getDocComment();

        $phpdoc = new DocBlock(!$this->reset ? $reflection : '', new Context($namespace));
        $properties = array();
        $methods = array();

        foreach ($phpdoc->getTags() as $tag) {
            /* @var Tag|Tag\PropertyTag|Tag\MethodTag $tag */
            $name = $tag->getName();
            if ($name == 'property' || $name == 'property-read' || $name == 'property-write') {
                $properties[] = $tag->getVariableName();
            } elseif ($name == 'method') {
                $methods[] = $tag->getMethodName();
            }
        }
        unset($tag);

        foreach ($this->properties as $name => $property) {
            $name = '$' . $name;
            if (in_array($name, $properties)) {
                continue;
            }
            if ($property['read'] && $property['write']) {
                $attr = 'property';
            } elseif ($property['write']) {
                $attr = 'property-write';
            } else {
                $attr = 'property-read';
            }
            $tag = Tag::createInstance("@{$attr} {$property['type']} {$name} {$property['comment']}", $phpdoc);
            $phpdoc->appendTag($tag);
        }

        foreach ($this->methods as $name => $method) {
            if (in_array($name, $methods)) {
                continue;
            }
            $arguments = implode(', ', $method['arguments']);
            $tag = Tag::createInstance("@method static {$method['type']} {$name}({$arguments})", $phpdoc);
            $phpdoc->appendTag($tag);
        }

        if (!isset($tag)) return null;

        if (!$phpdoc->getText()) {
            $phpdoc->setText($class);
        }

        $serializer = new DocBlock\Serializer(!$this->write ? 1 : 0, "\t");
        $docComment = $serializer->getDocComment($phpdoc);

        if ($this->write) {
            $filename = $reflection->getFileName();
            $contents = $this->files->get($filename);

            if ($originalDoc) {
                $contents = str_replace($originalDoc, $docComment, $contents);
            } else {
                $needle = "class {$className}";
                $replace = "{$docComment}\nclass {$className}";

                if (($pos = strpos($contents, $needle)) !== false) {
                    $contents = substr_replace($contents, $replace, $pos, strlen($needle));
                }
            }
            if ($this->files->put($filename, $contents)) {
                $this->info('Written new phpDocBlock to ' . $filename);
            }

            return null;
        }

        return "namespace {$namespace}{\n{$docComment}\n\tclass {$className} {}\n}\n\n";
    }

    /**
     * @param string $className
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return string
     */
    private static function getClassName($className, $model)
    {
        // If the class name was resolved via get_class($this) or static::class
        if (strpos($className, 'get_class($this)') !== false || strpos($className, 'static::class') !== false) {
            return '\\' . get_class($model);
        }

        // If the class name was resolved via ::class (PHP 5.5+)
        if (($end = strpos($className, '::class')) !== false) {
            return ltrim(substr($className, 0, $end));
        }

        return '\\' . ltrim(trim($className, " \"'\t\n\r\0\x0B"), '\\');
    }
}
