<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\AuthRepository;
use App\Services\ScheduledWorkOrders;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class PublishScheduledWorkOrders extends BaseCommand
{
    protected $group = 'EWO';
    protected $name = 'ewo:publish-scheduled';
    protected $description = 'Process the current database minute of Scheduled Work Orders when explicitly enabled.';

    public function run(array $params): int
    {
        if (env('ewo.scheduledWorkOrderWorker', false) !== true) {
            CLI::write('Scheduled Work Order worker is disabled.');
            return EXIT_SUCCESS;
        }
        try {
            $db = Database::connect('ewo');
            $worker = new ScheduledWorkOrders($db);
            if (!$worker->schemaReady()) {
                throw new RuntimeException('The Scheduled Work Order schema is not compatible.');
            }
            $timezone = (new AuthRepository($db))->generalSettings()['timezone'] ?? '';
            $localZone = new DateTimeZone($timezone);
            $databaseTime = $db->query('SELECT NOW() AS wall_clock')->getRowArray()['wall_clock'];
            $wallZone = new DateTimeZone('UTC');
            $now = new DateTimeImmutable($databaseTime, $wallZone);
            $local = new DateTimeImmutable((new DateTimeImmutable('now', $localZone))->format('Y-m-d H:i:s'), $wallZone);
            if (abs($local->getTimestamp() - $now->getTimestamp()) > 5) {
                throw new RuntimeException('Database time and the legacy application timezone disagree.');
            }
            CLI::write('Scheduled occurrences committed: ' . count($worker->publishMinute($now)));
            return EXIT_SUCCESS;
        } catch (Throwable $error) {
            log_message('error', 'Scheduled Work Order worker stopped ({type}, {code}).', ['type' => $error::class, 'code' => $error->getCode()]);
            CLI::error('Scheduled Work Order worker stopped. Check schema, clocks and database logs.');
            return EXIT_ERROR;
        }
    }
}
