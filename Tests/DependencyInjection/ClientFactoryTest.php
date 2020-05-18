<?php

namespace Bugsnag\BugsnagBundle\Tests\DependencyInjection;

use Bugsnag\BugsnagBundle\DependencyInjection\ClientFactory;
use Bugsnag\BugsnagBundle\EventListener\BugsnagShutdown;
use Bugsnag\BugsnagBundle\Request\SymfonyResolver;
use Bugsnag\Client;
use GrahamCampbell\TestBenchCore\MockeryTrait;
use PHPUnit_Framework_TestCase as TestCase;
use Mockery;
use ReflectionClass;
use ReflectionException;

final class ClientFactoryTest extends TestCase
{
    use MockeryTrait;

    private $rootPath = '/example/root/path';

    public function tearDown()
    {
        $this->tearDownMockery();
    }

    public function testShutdownStrategyIsPassedToClient()
    {
        $shutdown = Mockery::mock(BugsnagShutdown::class);
        $shutdown->shouldReceive('registerShutdownStrategy')->once();

        $client = $this->createClient(['shutdown_strategy' => $shutdown]);

        $shutdown->shouldHaveReceived('registerShutdownStrategy', [$client]);
    }

    /**
     * Ensure the project root and strip path are both set with sensible defaults
     * when no explicit configuration is provided.
     *
     * @return void
     */
    public function testProjectRootAndStripPathAreInferredWhenNoSpecificConfigurationIsGiven()
    {
        $client = $this->createClient(['root' => $this->rootPath]);

        $this->assertInstanceOf(Client::class, $client);

        /** @var Client $client */
        $config = $client->getConfig();

        $projectRootRegex = $this->getProperty($config, 'projectRootRegex');
        $stripPathRegex = $this->getProperty($config, 'stripPathRegex');

        $expectedProjectRootRegex = $this->pathToRegex("{$this->rootPath}/src");
        $expectedStripPathRegex = $this->pathToRegex($this->rootPath);

        $this->assertSame(
            $expectedStripPathRegex,
            $stripPathRegex,
            "Expected to set a sensible default for the 'stripPathRegex'"
        );

        $this->assertSame(
            $expectedProjectRootRegex,
            $projectRootRegex,
            "Expected to set a sensible default for the 'projectRootRegex'"
        );
    }

    /**
     * @param string|null $projectRoot
     * @param string|null $stripPath
     * @param string|null $projectRootRegex
     * @param string|null $stripPathRegex
     * @param string $expectedProjectRootRegex
     * @param string $expectedStripPathRegex
     *
     * @return void
     *
     * @dataProvider projectRootAndStripPathProvider
     */
    public function testProjectRootAndStripPathAreSetCorrectly(
        $projectRoot,
        $stripPath,
        $projectRootRegex,
        $stripPathRegex,
        $expectedProjectRootRegex,
        $expectedStripPathRegex
    ) {
        $client = $this->createClient([
            'root' => $this->rootPath,
            'project' => $projectRoot,
            'strip' => $stripPath,
            'project_root_regex' => $projectRootRegex,
            'strip_path_regex' => $stripPathRegex,
        ]);

        $this->assertInstanceOf(Client::class, $client);

        /** @var Client $client */
        $config = $client->getConfig();

        $projectRootRegex = $this->getProperty($config, 'projectRootRegex');
        $stripPathRegex = $this->getProperty($config, 'stripPathRegex');

        $this->assertSame(
            $expectedProjectRootRegex,
            $projectRootRegex,
            "Expected the 'projectRootRegex' to match the string provided in configuration"
        );

        $this->assertSame(
            $expectedStripPathRegex,
            $stripPathRegex,
            "Expected the 'stripPathRegex' to match the string provided in configuration"
        );
    }

    public function projectRootAndStripPathProvider()
    {
        return [
            // If both strings are provided, both options should be set to the
            // regex version of the given strings
            'both strings provided' => [
                'project_root' => '/example/project/root',
                'strip_path' => '/example/strip/path',
                'project_root_regex' => null,
                'strip_path_regex' => null,
                'expected_project_root_regex' => $this->pathToRegex('/example/project/root'),
                'expected_strip_path_regex' => $this->pathToRegex('/example/strip/path'),
            ],

            // If both regexes are provided they should be set verbatim
            'both regexes provided' => [
                'project_root' => null,
                'strip_path' => null,
                'project_root_regex' => '/^example project root regex/',
                'strip_path_regex' => '/^example strip path regex/',
                'expected_project_root_regex' => '/^example project root regex/',
                'expected_strip_path_regex' => '/^example strip path regex/',
            ],

            // If only the project root string is provided, the project root should
            // be set to the regex version of the string and the strip path to
            // the kernel project directory (the root of the project)
            'only project root string provided' => [
                'project_root' => '/example/project/root',
                'strip_path' => null,
                'project_root_regex' => null,
                'strip_path_regex' => null,
                'expected_project_root_regex' => $this->pathToRegex('/example/project/root'),
                'expected_strip_path_regex' => $this->pathToRegex($this->rootPath),
            ],

            // If only the project root regex is provided, it should be set
            // and the strip path should be set to the kernel project directory
            'only project root regex provided' => [
                'project_root' => null,
                'strip_path' => null,
                'project_root_regex' => '/^example project root regex/',
                'strip_path_regex' => null,
                'expected_project_root_regex' => '/^example project root regex/',
                'expected_strip_path_regex' => $this->pathToRegex($this->rootPath),
            ],

            // If only the strip path string is provided, both values should be
            // set — the stip path to the regex version of the string and the
            // project root to the kernel project directory with "/src" appended
            'only strip path string provided' => [
                'project_root' => null,
                'strip_path' => '/example/strip/path',
                'project_root_regex' => null,
                'strip_path_regex' => null,
                'expected_project_root_regex' => $this->pathToRegex("{$this->rootPath}/src"),
                'expected_strip_path_regex' => $this->pathToRegex('/example/strip/path'),
            ],

            // If only the strip path regex is provided, the strip path should be
            // set verbatim and the project root should be set to the kernel
            // project directory
            'only strip path regex provided' => [
                'project_root' => null,
                'strip_path' => null,
                'project_root_regex' => null,
                'strip_path_regex' => '/^example strip path regex/',
                'expected_project_root_regex' => $this->pathToRegex("{$this->rootPath}/src"),
                'expected_strip_path_regex' => '/^example strip path regex/',
            ],

            // If the regexes are provided and either string value is too then
            // the regexes should take precedence and the string value ignored
            'project root string and both regexes provided' => [
                'project_root' => $this->pathToRegex('/example/project/root'),
                'strip_path' => null,
                'project_root_regex' => '/^example project root regex/',
                'strip_path_regex' => '/^example strip path regex/',
                'expected_project_root_regex' => '/^example project root regex/',
                'expected_strip_path_regex' => '/^example strip path regex/',
            ],

            // If the regexes are provided and either string value is too then
            // the regexes should take precedence and the string value ignored
            'strip path string and both regexes provided' => [
                'project_root' => null,
                'strip_path' => $this->pathToRegex('/example/strip/path'),
                'project_root_regex' => '/^example project root regex/',
                'strip_path_regex' => '/^example strip path regex/',
                'expected_project_root_regex' => '/^example project root regex/',
                'expected_strip_path_regex' => '/^example strip path regex/',
            ],

            // If all four options are provided then the regexes should take
            // precedence and the string values ignored
            'all options provided' => [
                'project_root' => $this->pathToRegex('/example/project/root'),
                'strip_path' => $this->pathToRegex('/example/strip/path'),
                'project_root_regex' => '/^example project root regex/',
                'strip_path_regex' => '/^example strip path regex/',
                'expected_project_root_regex' => '/^example project root regex/',
                'expected_strip_path_regex' => '/^example strip path regex/',
            ],
        ];
    }

    /**
     * Get the value of the given property on the given object.
     *
     * @param object $object
     * @param string $property
     *
     * @return mixed
     */
    private function getProperty($object, $property)
    {
        $propertyAccessor = function ($property) {
            return $this->{$property};
        };

        return call_user_func($propertyAccessor->bindTo($object, $object), $property);
    }

    /**
     * Convert a file path to a regex that matches the path and any sub paths.
     *
     * @param string $path
     *
     * @return string
     */
    private function pathToRegex($path)
    {
        return sprintf('/^%s[\\/]?/i', preg_quote($path, '/'));
    }

    /**
     * Creates a Client, using the default arguments merged with any overrides
     *
     * For example, by default the API key will be 'null', but can be specified
     * by passing '["key" => "example-123"]' to this method
     *
     * @param array $argumentOverrides
     *
     * @throws ReflectionException
     *
     * @return Client
     */
    private function createClient(array $argumentOverrides = [])
    {
        $reflector = new ReflectionClass(ClientFactory::class);

        $arguments = array_merge($this->defaultArguments(), $argumentOverrides);

        /** @var ClientFactory $factory */
        $factory = $reflector->newInstanceArgs($arguments);

        return $factory->make();
    }

    /**
     * Get an array of default arguments for the ClientFactory
     *
     * This is an associative array purely to document the arguments — they are
     * applied in order rather than by name, i.e. index 0 will always be the
     * first argument for ClientFactory, which won't necessarily be the
     * '$resolver' if the parameter order changes
     *
     * @return array
     */
    private function defaultArguments()
    {
        return [
            'resolver' => Mockery::mock(SymfonyResolver::class),
            'tokens' => null,
            'checker' => null,
            'key' => null,
            'endpoint' => null,
            'callbacks' => true,
            'user' => true,
            'type' => null,
            'version' => true,
            'batch' => null,
            'hostname' => null,
            'code' => true,
            'strip' => null,
            'project' => null,
            'root' => $this->rootPath,
            'env' => null,
            'stage' => null,
            'stages' => null,
            'filters' => null,
            'shutdown_strategy' => null,
            'strip_path_regex' => null,
            'project_root_regex' => null,
        ];
    }
}
