<?php

declare(strict_types=1);

use App\Models\KnowledgeBaseRepository;
use App\Services\ScheduledWorkOrders;

$fixture = json_decode((string)getenv('EWO_HTTP_MYSQL_FIXTURE'), true, 8, JSON_THROW_ON_ERROR);
$port = (int)($fixture['port'] ?? 0);
if (PHP_SAPI !== 'cli' || ($fixture['create'] ?? false) !== true || $port < 49152 || $port > 65535) {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/codeigniter4/framework/system/Test/bootstrap.php';
$db = Config\Database::connect(['hostname'=>'127.0.0.1', 'port'=>$port, 'database'=>'ewo_fixture',
    'username'=>'ewo_fixture', 'password'=>$fixture['password'], 'DBDriver'=>'MySQLi', 'charset'=>'utf8mb4', 'DBDebug'=>true], false);
$directory = realpath($db->query('SELECT @@datadir AS value')->getRowArray()['value']);
if ($directory !== realpath($fixture['datadir']) || !str_starts_with(strtolower($directory), strtolower(realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'eworeb-mysql-'))) {
    throw new RuntimeException('Unexpected fixture server');
}

function check(bool $valid, string $message): void
{
    if (!$valid) {
        throw new RuntimeException($message);
    }
}

if (($argv[2] ?? '') === '--race') {
    touch($argv[1] . '/scheduled-ready-' . $argv[3]);
    for ($tries = 0; !is_file($argv[1] . '/scheduled-go') && $tries < 100; ++$tries) {
        usleep(20000);
        clearstatcache();
    }
    check(is_file($argv[1] . '/scheduled-go'), 'Concurrent schedule barrier timed out');
    echo json_encode((new ScheduledWorkOrders($db))->publishMinute(new DateTimeImmutable('2026-11-20 13:20:15', new DateTimeZone('Asia/Jakarta'))), JSON_THROW_ON_ERROR);
    exit;
}

check($db->table('kb_articles')->countAllResults() > 0, 'KB HTTP operations did not write through the MySQL adapter');

$ddl = file_get_contents(dirname(__DIR__,2) . '/docs/KB_MYSQL_PROPOSAL.sql');
$db->query(str_replace('CREATE TABLE kb_articles', 'CREATE TEMPORARY TABLE kb_articles', $ddl));
$source = new KnowledgeBaseRepository($argv[1] . '/kb-roundtrip');
$mysql = new KnowledgeBaseRepository(db: $db);
$base = ['title'=>'Café printer 😀','summary'=>'First line' . "\n" . 'second line','category'=>'it-support',
    'keywords'=>['print','Café'], 'symptoms'=>['No print'], 'causes'=>['Queue paused'],
    'resolution_steps'=>['Open queue','Resume'], 'related_articles'=>['another-draft','missing-reference']];
$article = $source->save(null, $base + ['slug'=>'printer','visibility'=>'public']);
$source->setPublished($article['id'], $article, true);
$source->save(null, array_replace($base, ['slug'=>'another-draft','visibility'=>'public', 'related_articles'=>[]]));
$internal = $source->save(null, $base + ['slug'=>'private-printer','visibility'=>'internal']);
$source->setPublished($internal['id'], $internal, true);
$import = $mysql->importFromJson($source);
check($import['articles'] === 3 && $import['public'] === 1 && $import === $mysql->importFromJson($source), 'KB migration parity or retry failed');
check($mysql->articles(true)[0]['related_articles'] === ['another-draft','missing-reference'], 'KB ordered relationships changed');
$source->save(null, $base + ['slug'=>'conflict','visibility'=>'public']);
try {
    $mysql->importFromJson($source);
    throw new LogicException('KB import overwrote diverged target');
} catch (RuntimeException $expected) {
    check(count($mysql->articles(false)) === 3, 'KB conflict modified target');
}
echo "KB MySQL import: stable IDs, revisions, Unicode, ordered content, visibility, replay and conflicting snapshot PASS\n";

$zone = new DateTimeZone('Asia/Jakarta');
$now = new DateTimeImmutable('2026-11-20 10:31:42', $zone);
$fields = ['is_scheduled'=>1,'published_at'=>'2026-11-20 10:31:10','intervale'=>2,'title'=>'Fixture schedule',
    'status'=>1,'category_id'=>1,'created_at'=>'2026-11-01 09:00:00','corp_id'=>1,'dept_id'=>10,'role_id'=>3,'assign_to'=>9];
$ids = [];
foreach ([$fields, array_replace($fields, ['title'=>'Fixture draft schedule','status'=>0]),
    array_replace($fields, ['title'=>'Historical unknown','published_at'=>null,'intervale'=>null]),
    array_replace($fields, ['title'=>'Missed minute','published_at'=>'2026-11-20 10:30:59']),
    array_replace($fields, ['title'=>'Next minute','published_at'=>'2026-11-20 10:32:00'])] as $row) {
    $db->table('posts')->insert($row);
    $ids[] = (int)$db->insertID();
}
$worker = new ScheduledWorkOrders($db);
$occurrences = $worker->publishMinute($now);
check(count($occurrences) === 2 && $worker->publishMinute($now) === [], 'Due selection or duplicate prevention failed');
foreach ($occurrences as $i => $id) {
    $order = $db->table('posts')->where('id',$id)->get()->getRowArray();
    $log = $db->table('posts_logs')->where('post_id',$id)->get()->getRowArray();
    $template = $db->table('posts')->where('id',$ids[$i])->get()->getRowArray();
    check((int)$order['status']===(1-$i) && (int)$log['status']===(1-$i) && (int)$order['assign_to']===9
        && $order['created_at']===$fields['created_at'], 'Occurrence status, assignment or created_at changed');
    check((int)$order['is_scheduled']===0 && (int)$order['intervale']===0 && (int)$log['intervale']===0
        && $order['published_at']==='2026-11-20 10:31:42' && $log['published_at']===$fields['published_at']
        && $template['published_at']==='2026-11-22 10:31:10', 'Occurrence/history timestamps or interval differ');
}
check(count($worker->publishMinute($now->modify('+2 days')))===2, 'Next recurrence did not publish');
check($db->table('posts')->where('id',$ids[2])->get()->getRowArray()['published_at']===null, 'Historical date was invented');
$db->table('posts')->insert(array_replace($fields, ['title'=>'Parallel schedule','published_at'=>'2026-11-20 13:20:00']));
$raceId = $db->insertID();
$db->transBegin();
$db->query('SELECT id FROM posts WHERE id=? FOR UPDATE', [$raceId]);
$processes = [];
try {
    foreach (['a','b'] as $attempt) {
        $process = proc_open([PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mysqli',
            '-d', 'extension=intl', '-d', 'extension=mbstring', __FILE__, $argv[1], '--race', $attempt],
            [1=>['pipe','w'], 2=>['pipe','w']], $pipes, null, null, ['bypass_shell'=>true]);
        check(is_resource($process), 'Could not start concurrent schedule worker');
        $processes[] = [$process, $pipes];
    }
    for ($tries = 0; $tries < 100; ++$tries) {
        clearstatcache();
        if (is_file($argv[1] . '/scheduled-ready-a') && is_file($argv[1] . '/scheduled-ready-b')) break;
        usleep(20000);
    }
    check($tries < 100, 'Both concurrent schedule workers must start');
    touch($argv[1] . '/scheduled-go');
    usleep(100000);
} finally {
    $db->transCommit();
}
$races = [];
foreach ($processes as [$process,$pipes]) {
    $json = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($process) === 0, 'Concurrent schedule worker failed');
    $races[] = count(json_decode($json,true,8,JSON_THROW_ON_ERROR));
}
sort($races);
check($races === [0,1], 'Concurrent schedule workers duplicated an occurrence');
$db->table('posts')->insert(array_replace($fields, ['title'=>'Rollback schedule']));
$templateId = (int)$db->insertID();
try {
    $worker->publishMinute($now);
    throw new LogicException('Expected history insertion failure');
} catch (RuntimeException $expected) {
    check($db->table('posts')->where('title','Rollback schedule')->countAllResults()===1
        && $db->table('posts')->where('id',$templateId)->get()->getRowArray()['published_at']===$fields['published_at'], 'Failed occurrence partially committed');
}
echo "Scheduled MySQL: controlled clock, concurrent dedupe, recurring days, draft and published status, unknown dates untouched, history and rollback PASS\n";
