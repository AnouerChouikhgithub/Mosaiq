<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\SyntheticFixtureCatalog;
use App\DataFixtures\SyntheticFixtureLoader;
use App\Repository\BoardRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SyntheticFixtureLoaderTest extends TestCase
{
    public function testRefusesProdEnvironment(): void
    {
        $loader = $this->loader('prod', 'postgresql://app:app@127.0.0.1:5432/mosaiq');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disabled in the prod environment');
        $loader->assertSafeTarget();
    }

    public function testRefusesRemoteHost(): void
    {
        $loader = $this->loader('dev', 'postgresql://app:secret@db.production.example:5432/mosaiq');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to load synthetic fixtures');
        $loader->assertSafeTarget();
    }

    public function testRefusesNonPostgresDatabase(): void
    {
        $loader = $this->loader('dev', 'sqlite:///%kernel.project_dir%/var/data.db');

        $this->expectException(\RuntimeException::class);
        $loader->assertSafeTarget();
    }

    public function testRefusesProductionLookingDatabaseName(): void
    {
        $loader = $this->loader('dev', 'postgresql://app:secret@localhost:5432/mosaiq_prod');

        $this->expectException(\RuntimeException::class);
        $loader->assertSafeTarget();
    }

    public function testAcceptsLoopbackMosaiqDatabase(): void
    {
        $loader = $this->loader('dev', 'postgresql://app:app@127.0.0.1:5432/mosaiq');

        $this->expectNotToPerformAssertions();
        $loader->assertSafeTarget();
    }

    public function testAcceptsTestDatabaseName(): void
    {
        $loader = $this->loader('test', 'postgresql://app:app@localhost:5432/mosaiq_test');

        $this->expectNotToPerformAssertions();
        $loader->assertSafeTarget();
    }

    private function loader(string $environment, string $databaseUrl): SyntheticFixtureLoader
    {
        return new SyntheticFixtureLoader(
            $this->createStub(EntityManagerInterface::class),
            new SyntheticFixtureCatalog(),
            $this->createStub(WorkspaceRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(BoardRepository::class),
            $environment,
            $databaseUrl,
        );
    }
}
