<?php

/**
 * This file is part of the prooph/micro-cli.
 * (c) 2017-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2017-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\MicroCli\Command;

use PHPUnit\Framework\TestCase;
use Prooph\MicroCli\Command\ComposerRequireCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @coversDefaultClass \Prooph\MicroCli\Command\ComposerRequireCommand
 */
class ComposerRequireCommandTest extends TestCase
{
    /**
     * @var ComposerRequireCommand
     */
    protected $command;

    public function setUp(): void
    {
        $this->prepareTempDirectories();

        /** @var ComposerRequireCommand $command */
        $command = $this->getMockBuilder(ComposerRequireCommand::class)
            ->setMethods(['getRootDir', 'getServiceDirPath'])
            ->getMock();

        $command
            ->method('getRootDir')
            ->willReturn($this->getTempDirectory());

        $command
            ->method('getServiceDirPath')
            ->will($this->returnCallback(function ($service) {
                return $this->getServiceDirectory() . '/' . $service;
            }));

        $application = new Application();
        $application->add($command);

        $this->command = $application->get($command->getName());
    }

    public function tearDown(): void
    {
        $this->removeTempDirectory();
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::configure
     */
    public function it_creates_command_instance(): void
    {
        $this->assertInstanceOf(ComposerRequireCommand::class, $this->command);
    }

    /**
     * @test
     * @covers ::execute
     * @covers ::getDeclaredPhpServices
     */
    public function it_aborts_if_no_php_service_is_declared(): void
    {
        \file_put_contents($this->getTempDirectory() . '/docker-compose.yml', 'services: []');

        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['package' => 'test/test']);

        $this->assertSame(1, $commandTester->getStatusCode());
        $this->assertContains('No php services declared in docker-compose.yml', $commandTester->getDisplay());
    }

    /**
     * @test
     * @covers ::execute
     * @covers ::getDeclaredPhpServices
     * @covers ::getDockerComposeExecutable
     */
    public function it_founds_declared_php_services_with_composer_file(): void
    {
        $this->prepareServiceComposerFile('php_service1');
        $this->prepareServiceComposerFile('php_service2');

        \file_put_contents($this->getTempDirectory() . '/docker-compose.yml', <<<EOL
services:
    php_service1:
        image: prooph/php:7.1
    php_service2:
        image: prooph/php:7.0.14
    php_service_without_composer_file:
        image: prooph/php:5.6
    other_service:
        image: fuubar
    another_service:
        build: .
EOL
        );

        $commandTester = new CommandTester($this->command);
        $commandTester->setInputs([0]); // we choose the first service here
        $commandTester->execute([
            'package' => 'test/test',
            '--docker-executable' => 'echo', ]
        );

        $display = $commandTester->getDisplay();

        $this->assertSame(0, $commandTester->getStatusCode());
        $this->assertContains('php_service1', $display);
        $this->assertContains('php_service2', $display);
        $this->assertContains('require test/test', $display);
        $this->assertNotContains('php_service_without_composer_file', $display);
        $this->assertNotContains('other_service', $display);
        $this->assertNotContains('another_service', $display);
    }

    /**
     * @test
     * @covers ::execute
     * @covers ::getRequestedServices
     */
    public function it_selects_service_from_argument(): void
    {
        $this->prepareServiceComposerFile('php_service1');
        $this->prepareServiceComposerFile('php_service2');

        \file_put_contents($this->getTempDirectory() . '/docker-compose.yml', <<<EOL
services:
    php_service1:
        image: prooph/php:7.1
    php_service2:
        image: prooph/php:7.0.14
EOL
        );

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'package' => 'test/test',
            'service' => 'php_service1',
            '--docker-executable' => 'echo',
        ]);

        $display = $commandTester->getDisplay();

        $this->assertSame(0, $commandTester->getStatusCode());
        $this->assertContains('php_service1', $display);
        $this->assertNotContains('Select a service', $display);
        $this->assertNotContains('php_service2', $display);
    }

    /**
     * @test
     * @covers ::execute
     * @covers ::getRequestedServices
     */
    public function it_requests_service_if_service_argument_is_not_valid(): void
    {
        $this->prepareServiceComposerFile('php_service1');

        \file_put_contents($this->getTempDirectory() . '/docker-compose.yml', <<<EOL
services:
    php_service1:
        image: prooph/php:7.1
EOL
        );

        $commandTester = new CommandTester($this->command);
        $commandTester->setInputs([0]);
        $commandTester->execute([
            'package' => 'test/test',
            'service' => 'not_a_service',
            '--docker-executable' => 'echo',
        ]);

        $display = $commandTester->getDisplay();

        $this->assertSame(0, $commandTester->getStatusCode());
        $this->assertContains('php_service1', $display);
        $this->assertContains('Select a service', $display);
        $this->assertContains("Service with name 'not_a_service' is not configured", $display);
    }

    /**
     * @test
     * @covers ::execute
     * @covers ::getRequestedServices
     */
    public function it_selects_all_services_with_option(): void
    {
        $this->prepareServiceComposerFile('php_service1');
        $this->prepareServiceComposerFile('php_service2');

        \file_put_contents($this->getTempDirectory() . '/docker-compose.yml', <<<EOL
services:
    php_service1:
        image: prooph/php:7.1
    php_service2:
        image: prooph/php:7.0.14
EOL
        );

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'package' => 'test/test',
            '--all' => true,
            '--docker-executable' => 'echo',
        ]);

        $display = $commandTester->getDisplay();

        $this->assertSame(0, $commandTester->getStatusCode());
        $this->assertContains('php_service1', $display);
        $this->assertContains('php_service2', $display);
        $this->assertNotContains('Select a service', $display);
    }

    private function getTempDirectory(): string
    {
        return \sys_get_temp_dir() . '/prooph_test';
    }

    private function getServiceDirectory(): string
    {
        return $this->getTempDirectory() . '/services';
    }

    private function removeTempDirectory(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->getTempDirectory(), \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileInfo) {
            $fileInfo->isDir() ? \rmdir($fileInfo->getRealPath()) : \unlink($fileInfo->getRealPath());
        }

        \rmdir($this->getTempDirectory());
    }

    private function prepareTempDirectories(): void
    {
        if (! \is_dir($this->getTempDirectory())) {
            \mkdir($this->getTempDirectory(), 0777, true);
        }

        if (! \is_dir($this->getServiceDirectory())) {
            \mkdir($this->getServiceDirectory(), 0777, true);
        }
    }

    private function prepareServiceComposerFile(string $serviceName): void
    {
        if (! \is_dir($this->getServiceDirectory() . '/' . $serviceName)) {
            \mkdir($this->getServiceDirectory() . '/' . $serviceName, 0777, true);
        }

        \file_put_contents($this->getServiceDirectory() . '/' . $serviceName . '/composer.json', '{}');
    }
}
