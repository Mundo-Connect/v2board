<?php
// Run with: php tests/admin_nodes_backend.php
// Isolated controller regression tests. Framework/database boundaries use in-memory doubles.
// This does not require vendor dependencies, a configured application, or a live database.

namespace Tests\AdminNodes {
    class Store
    {
        public static $rows = [];
        public static $failUpdate;
        public static $throwUpdate;
    }

    class Rows
    {
        private $rows;
        public function __construct($rows) { $this->rows = array_values($rows); }
        public function toArray() { return $this->rows; }
    }

    class Query
    {
        private $model;
        private $filters = [];
        private $order;
        private $limit;
        public function __construct($model) { $this->model = $model; }
        public function where($field, $operator, $value = null)
        {
            if (func_num_args() === 2) { $value = $operator; $operator = '='; }
            $this->filters[] = [$field, $operator, $value];
            return $this;
        }
        public function orderBy($field, $direction) { $this->order = [$field, $direction]; return $this; }
        public function limit($limit) { $this->limit = $limit; return $this; }
        public function get()
        {
            $rows = array_values(Store::$rows[$this->model] ?? []);
            foreach ($this->filters as list($field, $operator, $value)) {
                $rows = array_filter($rows, function ($row) use ($field, $operator, $value) {
                    if ($operator === '>=') return $row[$field] >= $value;
                    if ($operator === '<') return $row[$field] < $value;
                    return $row[$field] === $value;
                });
            }
            if ($this->model === \App\Models\StatServer::class) {
                $rows = array_map(function ($row) { $row['total'] = $row['u'] + $row['d']; return $row; }, $rows);
            }
            if ($this->order) {
                list($field, $direction) = $this->order;
                usort($rows, function ($a, $b) use ($field, $direction) {
                    return ($a[$field] <=> $b[$field]) * ($direction === 'DESC' ? -1 : 1);
                });
            }
            return new Rows($this->limit === null ? $rows : array_slice($rows, 0, $this->limit));
        }
    }
}

namespace Illuminate\Database\Eloquent {
    class Model
    {
        private $testId;
        public static function where(...$args) { return (new \Tests\AdminNodes\Query(static::class))->where(...$args); }
        public static function select($columns) { return new \Tests\AdminNodes\Query(static::class); }
        public static function find($id)
        {
            if (!isset(\Tests\AdminNodes\Store::$rows[static::class][$id])) return null;
            $model = new static();
            $model->testId = $id;
            return $model;
        }
        public function update($attributes)
        {
            if (static::class === \Tests\AdminNodes\Store::$throwUpdate) throw new \RuntimeException('Simulated database exception');
            if (static::class === \Tests\AdminNodes\Store::$failUpdate) return false;
            \Tests\AdminNodes\Store::$rows[static::class][$this->testId] = array_merge(
                \Tests\AdminNodes\Store::$rows[static::class][$this->testId], $attributes
            );
            return true;
        }
    }
}

namespace Illuminate\Http {
    class Request
    {
        private $input;
        public function __construct($input) { $this->input = $input; }
        public function only(...$keys) {
            if (count($keys) === 1 && is_array($keys[0])) $keys = $keys[0];
            return array_intersect_key($this->input, array_flip($keys));
        }
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function raw($expression) { return $expression; }
        public static function transaction($callback)
        {
            $snapshot = \Tests\AdminNodes\Store::$rows;
            try { return $callback(); }
            catch (\Throwable $error) { \Tests\AdminNodes\Store::$rows = $snapshot; throw $error; }
        }
    }
    class Validator
    {
        // Laravel's validation implementation is outside this isolated unit test.
        public static function make($input, $rules) { return new static(); }
        public function validate() {}
    }
}

namespace App\Http\Controllers { class Controller {} }

namespace {
    use App\Http\Controllers\V1\Admin\Server\ManageController;
    use App\Http\Controllers\V1\Admin\StatController;
    use App\Models\StatServer;
    use Illuminate\Http\Request;
    use Tests\AdminNodes\Store;

    function response($data) { return $data; }
    function abort($code, $message) { throw new RuntimeException($message, $code); }
    function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
    date_default_timezone_set('Asia/Shanghai');
    $root = dirname(__DIR__);
    $types = ['shadowsocks', 'vmess', 'vless', 'trojan', 'tuic', 'hysteria', 'anytls', 'mx', 'v2node'];
    foreach (array_merge($types, ['statServer']) as $type) {
        require $root . '/app/Models/' . ($type === 'statServer' ? 'StatServer' : 'Server' . ucfirst($type)) . '.php';
    }
    require $root . '/app/Http/Controllers/V1/Admin/StatController.php';
    require $root . '/app/Http/Controllers/V1/Admin/Server/ManageController.php';
    foreach ($types as $type) {
        $class = 'App\\Models\\Server' . ucfirst($type);
        Store::$rows[$class] = [1 => ['id' => 1, 'name' => $type . '-root', 'parent_id' => null, 'sort' => 99]];
    }
    // A child with the same name must not replace the parent in the ranking.
    Store::$rows[\App\Models\ServerMx::class][2] = ['id' => 2, 'name' => 'mx-child', 'parent_id' => 1, 'sort' => 100];
    $day = strtotime('today');
    $gb = 1073741824;
    $stat = function ($type, $at, $total, $id = 1, $recordType = 'd') use ($gb) {
        return ['server_id' => $id, 'server_type' => $type, 'record_at' => $at, 'record_type' => $recordType, 'u' => $gb, 'd' => ($total - 1) * $gb];
    };
    Store::$rows[StatServer::class] = [
        $stat('mx', $day, 8), $stat('vmess', $day, 3), $stat('v2node', $day, 5),
        $stat('v2ray', $day, 2), $stat('mx', $day - 86400, 6), $stat('trojan', $day - 86400, 4),
        $stat('mx', $day - 172800, 100), $stat('mx', $day, 200, 1, 'm'),
        $stat('retired-type', $day, 1), $stat('mx', $day, 1, 999)
    ];
    $controller = new StatController();
    $today = $controller->getServerTodayRank()['data'];
    check(array_column($today, 'total') === [8, 5, 3, 2, 1, 1], 'Today ranking must use daily totals in descending GB order');
    check($today[0]['server_name'] === 'mx-root', 'MX name must be present');
    check($today[1]['server_name'] === 'v2node-root' && $today[2]['server_name'] === 'vmess-root', 'Protocol-local IDs must remain distinct');
    check($today[3]['server_name'] === 'vmess-root', 'Legacy v2ray alias must still resolve');
    $yesterday = $controller->getServerLastRank()['data'];
    check(array_column($yesterday, 'total') === [6, 4] && $yesterday[0]['server_name'] === 'mx-root', 'Yesterday MX ranking must resolve the correct date and name');
    Store::$rows[StatServer::class] = [];
    check($controller->getServerTodayRank()['data'] === [] && $controller->getServerLastRank()['data'] === [], 'Empty rankings must succeed');
    for ($i = 1; $i <= 20; $i++) Store::$rows[StatServer::class][] = $stat('mx', $day, $i, $i);
    $top = $controller->getServerTodayRank()['data'];
    check(count($top) === 15 && $top[0]['total'] === 20 && $top[14]['total'] === 6, 'Top 15 limit must remain unchanged');

    $manage = new ManageController();
    $payload = [];
    foreach ($types as $index => $type) $payload[$type] = [1 => $index];
    $_POST = [];
    check($manage->sort(new Request($payload))['data'] === true, 'Mixed-protocol save must succeed');
    foreach ($types as $index => $type) {
        check(Store::$rows['App\\Models\\Server' . ucfirst($type)][1]['sort'] === $index, $type . ' sort must be persisted, including zero');
    }
    $_POST = ['mx' => [1 => '0']];
    $manage->sort(new Request([]));
    check(Store::$rows[\App\Models\ServerMx::class][1]['sort'] === '0', 'Legacy POST fallback must include MX');
    $_POST = [];
    foreach (['missing', 'false', 'exception', 'invalid-id'] as $failure) {
        $before = Store::$rows;
        Store::$failUpdate = $failure === 'false' ? \App\Models\ServerMx::class : null;
        Store::$throwUpdate = $failure === 'exception' ? \App\Models\ServerMx::class : null;
        $id = $failure === 'missing' ? 999 : ($failure === 'invalid-id' ? 'invalid' : 1);
        try {
            $manage->sort(new Request(['vmess' => [1 => 50], 'mx' => [$id => 60]]));
            throw new LogicException('Expected save failure: ' . $failure);
        } catch (RuntimeException $error) {
            check(Store::$rows === $before, 'Every failed save must roll back all protocols: ' . $failure);
        }
    }
    Store::$failUpdate = Store::$throwUpdate = null;
    check($manage->sort(new Request([]))['data'] === true, 'Empty save must remain safe');
    echo "PASS: today/yesterday MX ranking, existing protocols, date/type filters, top 15, empty rankings, all 9 sort protocols, legacy POST and atomic failure handling\n";
}
