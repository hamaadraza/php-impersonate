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

    public function testAutoFallsBackToProcessWhenCurlOptionsPresent(): void
    {
        // Custom curl options only work with the executable path, so auto must
        // keep the process driver even if FFI would otherwise be available.
        $this->assertSame(
            ClientFactory::DRIVER_PROCESS,
            ClientFactory::preferredDriver(['proxy' => 'http://127.0.0.1:8080'])
        );

        $client = ClientFactory::create('chrome136', 30, ['proxy' => 'http://127.0.0.1:8080']);
        $this->assertInstanceOf(PHPImpersonate::class, $client);
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
