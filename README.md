
# Resource Thief Detector (RTD)

Modern software is bloated. Frameworks are fast, but lazy developer habits like N+1 queries, unindexed Lookups, and massive memory hydration loops are stealing your hardware resources. 

Resource Thief Detector (RTD) is an interactive, CLI-driven regression testing suite for Laravel. It allows you to profile application routes, store a strict resource baseline (Time, Memory, SQL Queries), apply architectural fixes, and measure the impact live via an interactive REPL environment.

---

## Key Features

* Interactive REPL Environment: Run profiling sessions, apply fixes, and check reports completely inside an artisan command loop.
* Low-Level Resource Benchmarking: Monitors accurate Wall Time, CPU Process Time, and strict RAM usage.
* Intelligent Query Normalization: Automatically groups and normalizes SQL strings collapsing IDs and raw variables into wildcards to detect hidden N+1 schemas.
* Checkpoint and Baseline Management: Save a precise snapshot baseline, then iterate with custom fixes to view performance regressions or improvements in real-time.

---

## Installation

You can install the package via composer (recommended for local or staging environments):

```bash
composer require resource-thief/resource-thief-detector --dev
```

---

## Usage and Workflow

Start the interactive detector suite by running the artisan command:

```bash
php artisan trace
```

### The Optimization Workflow

1. Start a Profile Session: Give your investigation a session name.
   ```text
   profile sakila_test
   ```
   
2. Target a Route: Feed the URI you want to profile into the buffer.
   ```text
   GET /engine-metrics/complex-join
   ```
   
3. Capture the Baseline: Run the baseline measurement to set your target threshold.
   ```text
   baseline
   ```
   
4. Apply a Fix and Measure: Modify your code, then run a named fix inside the REPL to measure the difference immediately.
   ```text
   fix eager_loading_fix
   ```
   
5. Analyze the Regression or Improvement: Compare all iterations to pick the ultimate, bloat-free architectural approach.
   ```text
   compare
   report
   ```

---

## CLI Preview Example

When running a fix, RTD tells you exactly where the resource leaks went, contrasting it mathematically against your baseline:

```text
------------------------------------------------------------
FIX: nested_api_calls
------------------------------------------------------------
Time:    163.84 ms
Memory:  1923.8 KB (1.88 MB)
Queries: 18
Query Time: 96.14 ms

CHANGE FROM BASELINE:
  Time:   +57.32 ms (+53.8%)
  Memory: -805.47 KB (-29.5%)
  Queries: +17
```

Running compare yields a complete performance breakdown:

```text
================================================================================
PROFILE COMPARISON
================================================================================
1. film_performance
   Time:   +141.86 ms (+133.2%)
   Memory: +2531.32 KB (+92.7%)
   Queries: +16
   Status: REGRESSION

2. nested_api_calls
   Time:   +57.32 ms (+53.8%)
   Memory: -805.47 KB (-29.5%)
   Queries: +17
   Status: GOOD
```

---

## Architectural Context (How it works under the hood)

RTD is not a simple wrapper around microtime. It hooks directly into the Laravel lifecycle framework:
* Memory Tracking: Tracks memory delta before and after dispatching the response lifecycle using strict memory_get_usage.
* Query Normalization Engine: Utilizes advanced pattern-matching regex rules to match complex repetitive queries.

---

## Examples


### Example 1: Basic Query Analysis
```bash
php artisan trace "DB::table('users')->get()" --tree --summary
```

```text
================================================================================
CALL TREE (Max Depth: 3)
================================================================================
DB::table [0.05 ms, 2.1 KB]
  ├─ DatabaseManager::table [0.12 ms, 4.5 KB]
  └─ Builder::get [1247.32 ms, 87450.2 KB]
      ├─ [SQL] select * from "users" (1247.32 ms)
      └─ Collection::__construct [7.15 ms, 50.3 KB]

================================================================================
SUMMARY
================================================================================
Wall Time:   1248.50 ms
CPU Time:    1120.45 ms
Memory:      87452.30 KB (85.41 MB)
Calls:       47
Queries:     1
Warnings:    1
```


### Example 2: Detecting N+1 Query

```bash
php artisan trace "User::all()->map(fn($u)=>$u->posts)" --warnings
```
```text
================================================================================
RESOURCE THIEF WARNINGS
================================================================================

[CRITICAL] N_PLUS_ONE
  count: 247
  total_time_ms: 3240.5
  pattern: select * from posts where user_id = *
  example: select * from posts where user_id = 1
  -> Fix: Use with('posts') for eager loading

[MEDIUM] LARGE_COLLECTION
  size: 15234
  memory_kb: 87450.5
  caller: UserController.php:45
  -> Fix: Use chunk() or paginate() instead

================================================================================
SUMMARY
================================================================================
Wall Time:   4250.15 ms
CPU Time:    3890.45 ms
Memory:      87452.30 KB (85.41 MB)
Queries:     248
Warnings:    2
CRITICAL: 1 | HIGH: 1
```

### Example 3: Route Execution (No HTTP)
```bash
php artisan trace --route /api/users/123 --tree --level=5
```
```text
Executing route: /api/users/123

================================================================================
CALL TREE (Max Depth: 5)
================================================================================
App\Http\Controllers\UserController::show [45.23 ms, 2100.5 KB]
  ├─ Illuminate\Routing\Controller::callAction [44.98 ms, 2098.2 KB]
  │   ├─ App\Http\Controllers\UserController::show [44.50 ms, 2095.0 KB]
  │   │   ├─ App\Services\UserService::find [32.10 ms, 1500.3 KB]
  │   │   │   ├─ [SQL] select * from users where id = 123 (15.20 ms)
  │   │   │   └─ Cache::get [2.15 ms, 50.2 KB]
  │   │   └─ App\Http\Resources\UserResource::make [12.35 ms, 594.8 KB]
  │   └─ Illuminate\Routing\Route::run [44.80 ms, 2090.0 KB]

================================================================================
SUMMARY
================================================================================
Wall Time:   45.67 ms
Memory:      2100.45 KB (2.05 MB)
Queries:     2
```

### Example 4: Deep Memory Analysis

```bash
php artisan trace "DB::table('users')->get()" --memory --summary
```

```text
================================================================================
DEEP MEMORY ANALYSIS
================================================================================
Baseline: 12450.45 KB
Final:    87450.30 KB
Peak:     89000.12 KB
Delta:    73.24 MB

[MEMORY LEAK DETECTED]
  73.24 MB not released
  -> Suggestion: Check for static variables, circular references, or unclosed resources

================================================================================
SUMMARY
================================================================================
Wall Time:   1248.50 ms
Memory:      87450.30 KB (85.41 MB)
Queries:     1
Warnings:    1
```
### Example 5: Profile Mode with Fixes
```bash
# Start profile
php artisan trace --profile --profile-name=users_query --code="DB::table('users')->get()"

# Apply first fix
php artisan trace --profile-name=users_query --fix="select only needed columns" --code="DB::table('users')->select('id','name','email')->get()"

# Apply second fix
php artisan trace --profile-name=users_query --fix="add where clause" --code="DB::table('users')->select('id','name','email')->where('active',1)->get()"

# Compare results
php artisan trace --profile-name=users_query --compare
```
```text
================================================================================
PROFILE COMPARISON
================================================================================

1. select only needed columns
   Time:   -356.87 ms (-28.6%)
   Memory: -42220.30 KB (-48.3%)
   Queries: +0
   Status: EXCELLENT

2. add where clause
   Time:   -45.20 ms (-3.6%)
   Memory: -1230.50 KB (-1.4%)
   Queries: +0
   Status: GOOD

================================================================================
FINAL RESULT
================================================================================
  Time improvement:   32.2% (-402.07 ms)
  Memory improvement: 49.7% (-43450.80 KB)
```

### Example 6: Detecting Expensive Loop
```bash
php artisan trace '
for($i=0;$i<10000;$i++){ 
    DB::table("logs")->insert(["message"=>$i]); 
}' --warnings
```

```text
================================================================================
RESOURCE THIEF WARNINGS
================================================================================

[CRITICAL] EXPENSIVE_LOOP
  location: TraceCommand.php:185
  iterations: 10000
  total_duration_ms: 15420.50
  max_duration_ms: 45.20
  total_memory_kb: 12500.00
  -> Fix: Use batch processing or chunk() instead

[CRITICAL] N_PLUS_ONE
  count: 10000
  total_time_ms: 15200.00
  pattern: insert into "logs" ("message") values (*)
  -> Fix: Use insert() with array of values instead of single inserts

================================================================================
SUMMARY
================================================================================
Wall Time:   15420.50 ms
CPU Time:    15000.00 ms
Memory:      12500.00 KB (12.21 MB)
Queries:     10000
Warnings:    2
CRITICAL: 2
```

### Example 7: Interactive Mode Full Workflow

```bash
php artisan trace
```

Interactive Session

```text
Resource Thief Detector - Complete Suite

COMMANDS:
  exit, clear, help
  profile NAME     - Start profiling mode
  fix NAME         - Apply and measure a fix
  push/pop         - Checkpoint management
  compare/report   - View results
  save/load        - Persist profiles
  GET /uri         - Add a route to profile

>>> profile sakila_benchmark

Profile created: sakila_benchmark
Profiling mode active: sakila_benchmark

>>> GET /engine-metrics/complex-join

Route added: GET /engine-metrics/complex-join

>>> baseline

Running baseline measurement...

------------------------------------------------------------
BASELINE
------------------------------------------------------------
Time:    1247.32 ms
Memory:  87450.50 KB (85.41 MB)
Queries: 1
Query Time: 1247.32 ms

>>> GET /engine-metrics/film-performance

Route added: GET /engine-metrics/film-performance

>>> fix "film performance"

Running fix: film performance

------------------------------------------------------------
FIX: film performance
------------------------------------------------------------
Time:    1389.18 ms
Memory:  89981.82 KB (87.87 MB)
Queries: 17
Query Time: 1389.18 ms

CHANGE FROM BASELINE:
  Time:   +141.86 ms (+133.2%)
  Memory: +2531.32 KB (+92.7%)
  Queries: +16

>>> compare

================================================================================
PROFILE COMPARISON
================================================================================

1. film performance
   Time:   +141.86 ms (+133.2%)
   Memory: +2531.32 KB (+92.7%)
   Queries: +16
   Status: REGRESSION

>>> report

================================================================================
PROFILE REPORT: 2025-01-17_14-30-00_sakila_benchmark
================================================================================

BASELINE MEASUREMENT
----------------------------------------
  Time:    1247.32 ms
  Memory:  87450.50 KB
  Queries: 1

IMPROVEMENTS
----------------------------------------
  1. film performance
     Time:   +141.86 ms (+133.2%)
     Memory: +2531.32 KB (+92.7%)
     Queries: +16

FINAL RESULT
----------------------------------------
  Time regression:   133.2% (+141.86 ms)
  Memory regression: 92.7% (+2531.32 KB)

>>> save

Profile saved to: storage/profiles/2025-01-17_14-30-00_sakila_benchmark.json

>>> exit
```

### Example 8: Loading a Saved Profile
```bash
php artisan trace --load=2025-01-17_14-30-00_sakila_benchmark
```

```text
================================================================================
LOADED PROFILE: 2025-01-17_14-30-00_sakila_benchmark
Created: 2025-01-17 14:30:00
================================================================================

BASELINE
----------------------------------------
  Time:    1247.32 ms
  Memory:  87450.50 KB
  Queries: 1

IMPROVEMENTS
----------------------------------------
  1. film performance
     Time:   +141.86 ms (+133.2%)
     Memory: +2531.32 KB (+92.7%)
     Queries: +16

FINAL RESULT
----------------------------------------
  Time regression:   133.2% (+141.86 ms)
  Memory regression: 92.7% (+2531.32 KB)
```

### Example 9: Real-World E-commerce Checkout

```bash
php artisan trace --route /api/checkout/process --params='{"user_id":123}' --tree --level=4 --memory --warnings
```

```bash
================================================================================
CALL TREE (Max Depth: 4)
================================================================================
CheckoutController::process [8450.23 ms, 184.5 MB]
  ├─ User::find [2.45 ms, 0.5 MB]
  ├─ Cart::with('items') [45.32 ms, 12.3 MB]
  ├─ Order::create [12.34 ms, 2.1 MB]
  ├─ [CRITICAL] Loop: 250 items [3450.67 ms, 67.8 MB]
  │   ├─ OrderItem::create (250 times) [1250 ms]
  │   └─ Product::decrement (250 times) [950 ms]
  ├─ Mail::send [2450.78 ms, 4.5 MB]
  └─ Http::post [1234.56 ms, 2.3 MB]

================================================================================
RESOURCE THIEF WARNINGS
================================================================================

[CRITICAL] N_PLUS_ONE
  count: 250
  total_time_ms: 950.00
  pattern: update products set stock = stock - 1 where id = *
  -> Fix: Use batch update

[CRITICAL] EXPENSIVE_LOOP
  iterations: 250
  total_duration_ms: 3450.67
  -> Fix: Move to queue job

================================================================================
DEEP MEMORY ANALYSIS
================================================================================
Baseline: 12.45 MB
Final:    184.50 MB
Delta:    172.05 MB

[MEMORY LEAK DETECTED]
  172.05 MB not released

================================================================================
SUMMARY
================================================================================
Wall Time:   8450.23 ms (8.45 seconds)
Memory:      184.50 MB
Queries:     503
Warnings:    3
CRITICAL: 2
```

### Example 10: Testing Complex Join with Sakila Database
```bash
php artisan trace --route /engine-metrics/complex-join --tree --level=3 --queries --summary
```

```bash
Executing route: /engine-metrics/complex-join

================================================================================
CALL TREE (Max Depth: 3)
================================================================================
Closure::__invoke [1247.32 ms, 87450.2 KB]
  ├─ DB::table [0.05 ms, 2.1 KB]
  │   └─ JoinClause::__construct [0.03 ms, 1.0 KB]
  ├─ Builder::get [1247.15 ms, 87448.0 KB]
  │   └─ [SQL] select film.film_id, film.title, inventory.inventory_id, 
  │            rental.rental_date, customer.first_name, customer.last_name
  │        from film
  │        inner join inventory on film.film_id = inventory.film_id
  │        inner join rental on inventory.inventory_id = rental.inventory_id
  │        inner join customer on rental.customer_id = customer.customer_id
  │        order by rental.rental_date desc
  │        limit 1000 (1247.15 ms)
  └─ Collection::groupBy [0.12 ms, 2.1 KB]

================================================================================
SQL QUERIES
================================================================================
Total: 1 queries | Total time: 1247.15 ms

SLOW QUERIES (>100ms):
  [1247.15 ms] select film.film_id, film.title, inventory.inventory_id, ...

================================================================================
SUMMARY
================================================================================
Wall Time:   1247.32 ms
Memory:      87450.20 KB (85.41 MB)
Queries:     1
Warnings:    0
```

### Quick Reference Card

```bash
# Quick commands
php artisan trace "DB::table('users')->get()" --tree --memory
php artisan trace --route /api/users --warnings
php artisan trace --profile --profile-name=test --code="User::all()"
php artisan trace --profile-name=test --fix="optimize" --code="User::select('id','name')->get()"
php artisan trace --profile-name=test --compare
php artisan trace --load=profile_name
php artisan trace
```

Copy-Paste Ready Commands for Testing
```bash
# Basic
php artisan trace "DB::table('users')->limit(10)->get()" --tree --summary

# With warnings
php artisan trace "User::all()->map(fn($u)=>$u->posts)" --warnings

# Route execution
php artisan trace --route /api/users --tree --level=4

# Memory analysis
php artisan trace "DB::table('users')->get()" --memory

# Profile workflow
php artisan trace --profile --profile-name=test --code="DB::table('users')->get()"
php artisan trace --profile-name=test --fix="optimize" --code="DB::table('users')->select('id','name')->get()"
php artisan trace --profile-name=test --compare --report --save

# Interactive
php artisan trace
```
---
## Contributing

If you hate bloated enterprise software and want to make this tool even sharper (like adding flame-graphs, profiling long-running background jobs, or database specific indexing advice), feel free to fork this repository and submit a Pull Request.

## License

The MIT License. Please see LICENSE.md for more information.
```
