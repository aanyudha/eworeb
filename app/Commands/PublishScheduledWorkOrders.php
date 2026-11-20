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
    protected $options = ['--check' => 'Inspect clock and template readiness without producing Work Orders.'];

    public function run(array $params): int
    {
        $check = array_key_exists('check', $params);
        $enabled = env('ewo.scheduledWorkOrderWorker', false) === true;
        if (!$check && !$enabled) {
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
            if ($check) {
                CLI::write('Legacy timezone: ' . $localZone->getName() . '; database time: ' . $databaseTime);
                CLI::write('Worker gate: ' . ($enabled ? 'enabled' : 'disabled'));
                foreach ($worker->inspect($now) as $label => $count) {
                    CLI::write($label . ': ' . $count);
                }
                return EXIT_SUCCESS;
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
