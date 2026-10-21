<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Plugin\FansGurus\Service\Sync;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

$capsule = new Capsule();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$capsule->schema()->create('category', static function (Blueprint $table): void {
    $table->increments('id');
    $table->integer('pid')->nullable();
    $table->string('name');
    $table->string('source')->default('default');
    $table->string('icon')->nullable();
    $table->integer('sort')->default(0);
    $table->integer('status')->default(1);
    $table->integer('hide')->default(0);
    $table->integer('owner')->default(0);
    $table->string('create_time')->nullable();
});

$stats = ['categories_created' => 0, 'categories_updated' => 0];
$method = new ReflectionMethod(Sync::class, 'syncCategoryNode');
$method->setAccessible(true);
$category = $method->invokeArgs(new Sync(), ['Facebook', [], &$stats, null, 10]);

if ($category->source !== 'fansgurus') {
    fwrite(STDERR, 'FansGurus category source was not persisted' . PHP_EOL);
    exit(1);
}

$category->source = 'default';
$category->save();
$category = $method->invokeArgs(new Sync(), ['Facebook', [], &$stats, null, 10]);
if ($category->source !== 'fansgurus') {
    fwrite(STDERR, 'Existing FansGurus category source was not repaired' . PHP_EOL);
    exit(1);
}

echo "FansGurus category source: PASS\n";
