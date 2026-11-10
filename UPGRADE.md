# Upgrading

Each section lists what a change from the previous version asks of your code. The reasons and the other changes are in the [CHANGELOG](CHANGELOG.md).

## 0.5 to 0.6

- **`WorkerRunner` takes its garbage-collection settings as one object.** The parameters `gcEvery`, `deferGc`, `gcRootsThreshold`, `gcRuns` and `gcCollected` are replaced by `gc: new GarbageCollection(...)` with the parameters `every`, `deferred`, `rootsThreshold`, `runs` and `collected`:

  ```
  // before
  new WorkerRunner($kernel, $logger, deferGc: true, gcRuns: $runs, gcCollected: $collected);
  // after
  new WorkerRunner($kernel, $logger, gc: new GarbageCollection(deferred: true, runs: $runs, collected: $collected));
  ```

## 0.4 to 0.5

- **`BodySizeLimitMiddleware` limits a body without a declared length too** (a chunked body): it is read up to the limit plus one byte and answered with `413` beyond that. A `POST`, `PUT`, `PATCH` or `DELETE` without `Content-Length` and with a body larger than the limit used to pass.
- **`Retry` retries more.** A connection that could not be made (`ConnectionException`) is treated like a lost one, and a server that refuses writes because it is read-only or starting up (SQLSTATE 25006, 57P03) is retried on a new connection. A new value of the `reason` label, `failover`, appears on `db_retries_total` and `db_retries_exhausted_total`.
- **Log messages mask `Bearer`, `Basic` and JWT values** wherever they stand.
- **`Histogram` stores no separate count.** Nothing to do; the count is the sum of the buckets when the metrics are rendered.

## 0.3 to 0.4

- Nothing breaks. New: `WorkerRunner` serves one request in FrankenPHP's classic mode, `ProcessMetricsEndpoint`, `JwksKeyProvider::warmUp()`, the `exhausted` counter of `Retry`.

## 0.2 to 0.3

- **`Observability\Storage` gains `set()`**; a custom implementation must add it.

After any upgrade run `vendor/bin/slimfony warmup` again (the compiled container is rebuilt from your configuration).