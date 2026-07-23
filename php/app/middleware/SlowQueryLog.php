<?php
declare(strict_types=1);

namespace app\middleware;

use think\facade\Log;
use think\facade\Db;

class SlowQueryLog
{
    private $threshold = 500;

    private $slowQueries = [];

    public function __construct()
    {
        $this->threshold = config('app.slow_query_threshold', 500);
    }

    public function handle($request, \Closure $next)
    {
        $this->slowQueries = [];

        Db::listen(function ($sql, $time, $rows) use ($request) {
            if ($time >= $this->threshold) {
                $this->slowQueries[] = [
                    'sql' => $sql,
                    'time' => $time,
                    'rows' => $rows,
                    'endpoint' => $request->pathinfo(),
                    'ip' => $request->realIp(),
                    'user_id' => $request->userId() ?? 0,
                ];
            }
        });

        $response = $next($request);

        if (!empty($this->slowQueries)) {
            $this->recordSlowQueries();
        }

        return $response;
    }

    private function recordSlowQueries(): void
    {
        try {
            $traceId = trace_id();
            $insertData = [];

            foreach ($this->slowQueries as $query) {
                $insertData[] = [
                    'trace_id'    => $traceId,
                    'sql'         => $query['sql'],
                    'params'      => json_encode([], JSON_UNESCAPED_UNICODE),
                    'execute_time' => $query['time'],
                    'rows'        => $query['rows'],
                    'endpoint'    => $query['endpoint'],
                    'ip'          => $query['ip'],
                    'user_id'     => $query['user_id'],
                    'create_time' => date('Y-m-d H:i:s'),
                ];
            }

            if (!empty($insertData)) {
                Db::name('slow_query_log')->insertAll($insertData);
            }

            $count = count($this->slowQueries);
            Log::warning("Slow query detected: count={$count}, threshold={$this->threshold}ms");
        } catch (\Throwable $e) {
            Log::error("Slow query log error: " . $e->getMessage());
        }
    }
}
