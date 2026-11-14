<?php

declare(strict_types=1);

namespace App\Command;

use App\DataFixtures\SyntheticFixtureLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:load',
    description: 'Load the idempotent synthetic Mosaiq fixtures into a local database.',
)]
final class LoadSyntheticFixturesCommand extends Command
{
    public function __construct(private readonly SyntheticFixtureLoader $loader)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $stats = $this->loader->load();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->table(
            ['Record', 'Created'],
            array_map(
                static fn (string $name, int $count): array => [$name, (string) $count],
                array_keys($stats),
                array_values($stats),
            ),
        );
        $io->success('Synthetic fixtures are up to date.');

        return Command::SUCCESS;
    }
}
