<?php

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\FfiClient;
use Raza\PHPImpersonate\ClientFactory;
use Raza\PHPImpersonate\PHPImpersonate;

class ClientFactoryTest extends TestCase
{
    public function testProcessDriverAlwaysReturnsProcessClient(): void
    {
        $client = ClientFactory::create('chrome136', 30, [], ClientFactory::DRIVER_PROCESS);
        $this->assertInstanceOf(PHPImpersonate::class, $client);
    }

    public function testUnknownDriverThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ClientFactory::create('chrome136', 30, [], 'bogus');
    }

    public function testAutoFallsBackToProcessForFfiUnsupportedOptions(): void
    {
        // A raw curl option the FFI transport can't map (but the executable
        // accepts) must keep the process driver even when FFI is available.
        $this->assertSame(
            ClientFactory::DRIVER_PROCESS,
            ClientFactory::preferredDriver(['insecure' => true])
        );

        $client = ClientFactory::create('chrome136', 30, ['insecure' => true]);
        $this->assertInstanceOf(PHPImpersonate::class, $client);
    }

    public function testAutoRoutesProxyToFfiWhenAvailable(): void
    {
        // Proxy options ARE supported by FFI, so auto should use FFI when usable.
        $expected = FfiClient::isAvailable()
            ? ClientFactory::DRIVER_FFI
            : ClientFactory::DRIVER_PROCESS;

        $this->assertSame($expected, ClientFactory::preferredDriver(['proxy' => 'http://127.0.0.1:8080']));

        $client = ClientFactory::create('chrome136', 30, ['proxy' => 'http://127.0.0.1:8080']);
        $expectedClass = FfiClient::isAvailable() ? FfiClient::class : PHPImpersonate::class;
        $this->assertInstanceOf($expectedClass, $client);
    }

    public function testAutoSelectionMatchesAvailability(): void
    {
        $expected = FfiClient::isAvailable()
            ? ClientFactory::DRIVER_FFI
            : ClientFactory::DRIVER_PROCESS;

        $this->assertSame($expected, ClientFactory::preferredDriver());

        $client = ClientFactory::create('chrome136');
        if (FfiClient::isAvailable()) {
            $this->assertInstanceOf(FfiClient::class, $client);
        } else {
            $this->assertInstanceOf(PHPImpersonate::class, $client);
        }
    }
}
