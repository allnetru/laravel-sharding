# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## v0.4.1 - 2026-09-09

### What's Changed

* fix: pinning must not lose rows — negation, unions, global scopes and replicas by @allnetru in https://github.com/allnetru/laravel-sharding/pull/77

Four findings from the review of #75, and **three of them lose rows in v0.4.0**. Anyone on v0.4.0 should move to this one. Every fix below fails a test against v0.4.0 and passes here.

### Fixed

* **A negated equality was read as an equality, and rows went missing.** The most dangerous of the four because it reads like the safest clause there is: Laravel writes `whereNot('id', 1)` as an ordinary `Basic` equality whose boolean is «and not», and the guard rejected only booleans containing `or`. So the predicate was read as `id = 1` and the query pinned to that key's shard — while the predicate it runs matches every **other** identifier, all of which live elsewhere. Measured: three rows expected, two returned.
  
* **Unions were not read at all.** `where('id', 1)->union(where('id', 2))` pinned to the first key's shard and ran the union arm on that same connection, so the second row was not found. Routing a union means agreeing on the shards of every arm; until that exists, a query with any union fans out.
  
* **Global scopes were applied after the routing decision.** `ShardBuilder::get()` copies the scopes onto each per-shard builder rather than applying them to the one the decision is made on, so a scope adding a top-level `orWhere` was invisible to it: the query was pinned on a predicate narrower than the one executed, and the rows the scope existed to admit were lost. The predicate is now read after `applyScopes()`, which answers on a clone.
  
* **Replicas were read for nothing.** `connectionFor()` answers with the primary followed by its replicas, and a replica cannot answer any read in this package: `replicateForConnection()` puts an unconditional `is_replica = false` on every per-shard copy, so a query sent there comes back empty by construction. With the default `replica_count` of 1 a keyed read still touched two connections — which in a two-shard deployment is every shard there is, so the one-shard read was not one. Only the primary is read now.
  

### Changed

* The documented list of what falls back to a fan-out grew three rows: a negated key, a union, and a model whose global scope widens the predicate. `docs/en/sharding.md` carries the table.
  
* `ShardMorphToTest` placed a fixture row by hand on a shard its key does not name; until v0.4.0 the fan-out covered for that. It now inserts where the key points. **That is the upgrade note of v0.4.0 demonstrating itself** — worth knowing for anyone whose fixtures do the same.
  

### Upgrading

From v0.4.0: nothing to do beyond updating, and do update — three of the fixes above are silent data loss.

From v0.3.x: the note of v0.4.0 still applies. If you already run more than one shard, some rows sit on a shard their key does not name; deploy with `SHARDING_PIN_BY_KEY=false`, put them where their keys name them, then turn pinning on.

## v0.4.0 - 2026-09-09

### What's Changed

* fix: a row did not land on the shard its key names, and a keyed query read every shard anyway

Both halves were found on a stand deliberately given a second shard, and neither can be seen on one. The first is a data-placement bug that nothing failed on, because the second was covering for it.

### Fixed

* **A row did not reliably land on the shard its own key names.** `Model::save()` builds its query and only then fires `creating`, so the hook that chose the connection was always too late: the statement had already been aimed. Two paths made that visible, and between them they cover how applications actually write.
  
  `Model::create()` goes through `Builder::newModelInstance()`, which copies the connection of the blank model the builder was made from — and that model had no key, so it had been routed by a generated throwaway one. A model whose key is a snowflake had no key at query-build time either, so the insert went wherever the instance happened to point.
  
  Measured on two shards: of six rows created through `Model::create()` with keys given by hand, four sat on a shard their key does not name; the model reported one connection and the bytes were on another.
  
  **Nothing ever failed, and that is why it lasted.** Every read fans out across all shards and merges, so a misplaced row is found anyway. It surfaces the moment anything trusts the key: a read pinned to one shard, `shards:distribute` deciding a row is already in place, a rebalance moving it by a slot it does not match.
  
  The placement now happens in `performInsert()`, before the query exists, and the `creating` hook calls the same idempotent method so every other save path is still routed. `tests/Unit/ShardPlacementTest.php` asserts where the bytes are, read through a plain connection rather than through the model — asking the model would ask the same code that decides the answer.
  

### Added

* **A query that names its shard key now reads only the shards it can be on.** Until now nothing looked at a query at all: `where('tenant_id', 5)` still opened a cursor on every shard and merged the results. Correct, and N times the work, which made "a query has to know its key" a rule that bought the shape of a schema and nothing else. Only `onShardConnection()` and the relation resolver ever narrowed anything.
  
  This is partition pruning, not a new contract: it changes how many connections are asked, never what a query returns. The soundness argument is one sentence — with no `or` at the top level the predicate is a conjunction, and a conjunction containing `key = value` can only match rows whose key is that value. Anything else ANDed beside it narrows the result further and can never add a row from another shard, so it does not have to be understood at all.
  
  Which is why anything unrecognised is left alone rather than reasoned about. An `or` at the top level, a key only inside a nested group, an expression instead of a value, a qualified column belonging to a joined table: all of them fall back to the fan-out. A query pinned when it should have fanned out does not answer slowly, it answers **incompletely**, and that is the one failure this package must not have.
  
  It applies to everything that fans out, reads and keyed writes alike, because it narrows the single list they all share. `whereIn` on the key is pinned too, up to a hundred values.
  
* **`sharding.pin_by_key`**, default on. Turn it off while rebalancing: `shards:rebalance` moves rows and updates slots without atomicity between the two, so for the length of a move a row can sit on one connection while its slot names another. A fan-out finds it either way.
  

### Upgrading

**If you already run more than one shard, some of your rows are on the wrong one** — written there by the bug above. Reads found them because they fanned out; pinned reads will not.

So on such a deployment, in this order:

1. deploy with `SHARDING_PIN_BY_KEY=false`;
2. put the existing rows where their keys name — `shards:distribute` for the tables concerned;
3. turn pinning on.

A deployment with one connection has nothing to do: there is no wrong shard to be on, and pinning resolves to the only connection there is.

## v0.3.14 - 2026-09-08

### What's Changed

* fix: a morphTo on a sharded model was fatal before its type was set by @allnetru in https://github.com/allnetru/laravel-sharding/pull/73

### Fixed

* **A `morphTo` on a sharded model threw before its type was assigned.** `ShardMorphTo::addConstraints()` resolves the related model straight away so it can pick the shard — earlier than stock Eloquent resolves anything, since `MorphTo` does not override `addConstraints` at all and waits until the results are fetched. Resolving early is what lets a colocated morph read one shard instead of every one, and it means the method has to survive states stock code never resolves a model in.
  
  One of those is an unsaved row whose morph is about to be assigned. `spatie/laravel-activitylog` builds an entry and reads `$activity->subject` before setting `subject_type`, so `createModelByType(null)` ran on every logged save: `Class name must be a valid object or a string`, thrown from inside a model event on an ordinary `Model::create()`.
  
  With no type there is no related table, no key to constrain on and no shard to choose, so the relation now falls through to `BelongsTo` — exactly what stock `MorphTo` does, and its `getResults()` answers null while the type is missing.
  

### Added

* `tests/Unit/ShardMorphToTest.php`. Two of its three cases fail without the fix; the third pins that a morph **with** a type still resolves, so the guard cannot buy safety by breaking the reason the class exists.

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.13...v0.3.14

## v0.3.13 - 2026-09-08

### What's Changed

* fix: a console process hung after a cross-shard read by @allnetru in https://github.com/allnetru/laravel-sharding/pull/71

Found on a stand deliberately given a second shard. It cannot be seen reliably on one, and it stops every console process that touches a sharded model without naming its shard.

### Fixed

* **A console process did not exit after a cross-shard read.** `CoroutineDispatcher::run()` started a Swoole scheduler of its own whenever it was called outside an existing coroutine, so the shards would still be read concurrently there too. A process that starts a Swoole event loop does not reliably leave it, and one that does not never exits.
  
  Measured on two shards: `php artisan tinker` doing a single `User::count()` returned the right number, wrote its output, and then hung until it was killed. The same script with `SHARDING_COROUTINE_DRIVER=sync` exits 0, and an artisan command performing no fan-out exits 0 — it is the fan-out that leaves the process alive.
  
  `isSupported()` cannot prevent it: it checks that the extension is loaded, which is also true in a console process. Having the extension is not the same as being inside a server driving the loop, and the one thing that tells them apart is whether we are in a coroutine already.
  
  **This matters more than it looks.** Everything operational goes through artisan — post-deploy steps, the scheduler, seeders. A deploy step that hangs forever is a deploy that never finishes; a scheduled command that hangs is one worker gone per tick.
  
* **Outside a coroutine the tasks now run sequentially.** Nothing is lost where it matters: Octane's Swoole handler runs a request inside a coroutine, so the fan-out on the path that serves people is still concurrent — that is the case the concurrency was written for. A migration, a seeder or a scheduled command pays one round trip per shard and, in exchange, finishes.
  

### Changed

* The test asserting the old behaviour is **reversed rather than deleted**, and its docblock says why the decision changed, so the next reader does not restore it as a regression. A second test pins the half that must not be lost — concurrent dispatch inside a coroutine.
  
* `docs/en/sharding.md` no longer says the dispatcher "boots a lightweight coroutine scheduler so queries still complete in parallel".
  

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.12...v0.3.13

## v0.3.12 - 2026-09-08

### What's Changed

* fix: relation subqueries answered from one shard without saying so by @allnetru in https://github.com/allnetru/laravel-sharding/pull/69

The last path that could answer from one shard without saying so. With this, nothing in ordinary Eloquent is silently single-shard.

### Fixed

* **`whereHas`, `has`, `doesntHave`, `withCount` and `withSum` answered from one shard across colocation groups.** They compile into a correlated subquery, and a subquery runs on the connection its outer query runs on. Measured on two shards with children deliberately not colocated: `whereHas` matched one parent of two, `withCount` answered `[1, 0]` against a truth of `[1, 1]`, and `doesntHave` answered 1 where the answer was 0.
  
  They now raise `UnsupportedCrossShardQuery`. Nested names are checked hop by hop, since either hop can be the one that crosses, and aliases (`withCount('loose as total')`) and closure-keyed aggregates are recognised.
  

### Not changed, and the reason this is a check rather than a rewrite

**On a colocated relation all of these were already correct**, structurally rather than by luck: the related rows are on the shard the outer row is on, so the subquery reaches all of them. That is the cost colocation exists to remove, and it means the common case — everything inside one group — has been working all along and is untouched.

Five of the ten new tests cover exactly that and pass both before and after. In a schema where a group is colocated throughout, a check written carelessly would have started refusing nearly every relation query in the application.

### Why refused rather than distributed

The distributed form of `whereHas` is to run the subquery across the shards, collect the matching parent keys and feed them back as a `whereIn`. It is correct and it has no bound: the key set is every matching row, not a page. A cap on it would pass in development and fail in production on the same code.

There is also a consistency argument that settles it. `whereHas` across colocation groups is a join wearing a subquery, and this package already refuses joins and through-relations for that reason since v0.3.7.

`withCount` is the more tempting case, because it is bounded by the rows already fetched. Distributing it means suppressing the subselect and filling the attribute in after hydration — which works, but takes the alias out of the SELECT list, so `orderBy('items_count')` and `having` on it stop working and need refusals of their own. That is worth building when someone has a relation of that shape; the refusal is what tells them.

### Added

* `Allnetru\Sharding\Support\Colocation` decides the question from what the models declare, never from a row — it answers for a whole table at once, unlike `ResolvesShard`, which answers for one. Both sides must resolve a key through the same map (the same group, or the same strategy outside one), and then either share the shard-key column or have the relation join one shard key to the other. A pivot or an intermediate table promises nothing about all three tables landing together and is not colocated.

### Changed

* `docs/en/sharding.md` gains **Asking about a relation**: what colocation means here, that the colocated case works, why the other is refused, and the two ways out — colocate the tables, or ask in two steps.

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.11...v0.3.12

## v0.3.11 - 2026-09-08

### What's Changed

* fix: the fan-out dropped the select list and could not follow the order by @allnetru in https://github.com/allnetru/laravel-sharding/pull/67

Four defects around what a per-shard query is actually asked for. Only one of them needed to become an error; the rest simply work now.

### Fixed

* **`selectRaw()` was dropped whenever the query carried a limit.** The bounded path called `->select($columns)` on each shard with the `['*']` that `get()` defaults to, and `select()` overwrites whatever was there. `selectRaw('*, value * 2 as doubled')->get()` returned the expression; adding `->limit(3)` returned `[null, null, null]`. Working without a limit and failing with one is what kept it hidden. The columns argument now applies only when the query selected nothing of its own — which is what `Query\Builder::get()` does on a single connection.
  
* **A narrowed select left the merge with nothing to sort by.** Visible only once the above is fixed, which is why the two ship together: the merge compares *models*, so `select('id', 'label')->orderBy('value')` handed it rows with no `value`, every comparison read null, and the rows came back in shard-visit order. The clobbering bug had been accidentally preventing this by putting the column back.
  
  The ordering columns are now added to the select. Rows arrive carrying a column nobody asked for, which is the visible cost and is smaller than an order that quietly does nothing. Skipped when the select holds an expression, since an alias defined in the same select list cannot be re-selected.
  
* **`orderByRaw()` read an undefined array key.** It records `['type' => 'Raw', 'sql' => ...]` with no `column`, and `compareModels()` reached for one anyway — an `ErrorException`, followed by a merge in whatever order the shards were visited. The merge can only follow an order that names a column, so a raw order is refused with `UnsupportedCrossShardQuery`. Pinning with `onShardConnection()` allows it, because then the database sorts and nothing is merged.
  
* **A qualified order column compared a property no model has.** `orderBy('samples.value')` is now compared on its last segment, which is what the model carries. It was the same silent nothing as the raw order, and it is not refused because it is perfectly answerable.
  
* **`truncate()` emptied one shard.** It fans out.
  
* **`paginate()` counted the shards one after another,** outside the coroutine dispatcher every other fan-out goes through. The counts are parallel now; the cursors are still opened in order afterwards, because they are consumed lazily and outlive the dispatcher that would have created them.
  

### Measured, recorded, not fixed

**`whereHas`, `has`, `doesntHave`, `withCount` and `withSum` are correct on a colocated relation and wrong otherwise.** They compile into a correlated subquery that runs on the shard the outer row is on. Under colocation the child rows are on that shard, so the answer is right — that is precisely the cost colocation exists to remove, and it means the common case has been working all along.

When the relation is not colocated the subquery sees only the children that happen to share a shard. Measured on two shards: `whereHas` matched one parent of two, `withCount` returned `[1, 0]` against a truth of `[1, 1]`, and `doesntHave` returned 1 where the answer was 0.

The package can tell the two cases apart from the group configuration, so the next step is to take the correct path automatically and to distribute the other one rather than refuse it.

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.10...v0.3.11

## v0.3.10 - 2026-09-08

### What's Changed

* fix: writes ran against one randomly chosen shard by @allnetru in https://github.com/allnetru/laravel-sharding/pull/64
* fix: cursor() and pluck() read from one shard by @allnetru in https://github.com/allnetru/laravel-sharding/pull/65

This closes defect 2, opened in the v0.3.7 notes. Every ordinary Eloquent read and write on a sharded model now either spans the shards or refuses to answer. Nothing is left silently addressing one of them.

### Fixed

* **Writes ran against one randomly chosen shard.** `update()`, `delete()`, `forceDelete()`, `increment()` and `decrement()` fell through to Eloquent and wrote to a single connection, picked by hashing an identifier `getConnectionName()` generated for the occasion.
  
  `delete()` was the dangerous one. On two shards holding four matching rows, `Ticket::where('value', '>', 0)->delete()` removed two, left two, and returned `2` — the count of what it *did* delete. Nothing in the call, the return value or the logs said the job was half done.
  
  They now run on every shard and report the rows all of them touched together. `restore()` comes with them, because SoftDeletes implements it as an `update`, and a soft delete stays soft: the per-shard copy carries the model's global scopes as of v0.3.9, so registering SoftDeletes puts its delete callback back. Without that ordering, this change would have turned a soft delete into a hard one on every shard but the first.
  
* **A cross-shard write is not a transaction,** and cannot be. Every shard attempts the write and the first failure is raised afterwards, so a partial write is possible. That is documented rather than hidden, and is still an improvement on writing to one shard and reporting it as the whole job.
  
* **`cursor()` and `pluck()` read from one shard.** The last two unoverridden reads. `cursor()` now opens a cursor per shard and merges them as they are consumed, so the order is the query's own rather than shard after shard, and breaking out of the loop early stops pulling rows.
  
  `pluck()` reads whole models rather than the columns asked for. The merge orders by comparing models, so `orderByDesc('value')->pluck('label')` would otherwise be merged on a `value` that was never selected and is null on every row. It costs more than a single-shard pluck and is the price of the ordering being right.
  
* **The merge existed three times over** — inline in `get()`, again in the bounded read, again in `paginate()`. It is now one generator the four callers share, which is what made `cursor()` six lines instead of a fourth copy. `get()` keeps its parallel path: the shards are still read concurrently and the merge runs over what came back.
  

### Refused rather than answered

`UnsupportedCrossShardQuery` now also covers three write shapes:

* **A limit on a write.** `limit(5)` means five rows; repeated on four shards it means up to twenty, and nothing in the call hints at it. Select the rows first, then write by their keys.
* **A write to the shard key.** The row would keep sitting on a shard its key no longer points at, and every later read would look elsewhere and miss it. That is a move, not an update.
* **`upsert()`.** Its rows belong to different shards, so the statement would have to be split per row, and the conflicting row it turns on may live on a shard the statement never reaches — it would insert a duplicate. Save the models one by one, which routes each of them.

A pinned builder keeps the ordinary single-shard behaviour throughout, limits included: the shard was named on purpose, so a limit means what it says.

### Changed

* `docs/en/sharding.md` gains **Writes** and **Streaming and plucking**, and the coverage caveat is rewritten: instead of listing what is still broken, it lists what is covered and what throws. `toBase()` bypasses all of it by definition, which is stated and not worth preventing.

### Coverage

`get`, `cursor`, `pluck`, `chunk`, `chunkById`, `paginate`, `firstOrCreate`, `updateOrCreate`, `count`, `sum`, `min`, `max`, `avg`, `average`, `exists`, `doesntExist`, `update`, `delete`, `forceDelete`, `increment`, `decrement`.

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.9...v0.3.10

## v0.3.9 - 2026-09-08

### What's Changed

* fix: aggregates answered from one randomly chosen shard by @allnetru in https://github.com/allnetru/laravel-sharding/pull/61
* fix: the fan-out dropped every global scope by @allnetru in https://github.com/allnetru/laravel-sharding/pull/62

Both halves of this release are the same defect seen from two sides: `ShardBuilder` was transparent only for the methods it overrode and only for state that lived on the query. Anything on the builder, and anything building its own SQL, quietly addressed a single shard.

### Fixed

* **Aggregates answered from one randomly chosen shard.** `count`, `sum`, `min`, `max`, `avg` and `exists` are not among the overridden methods, so they fell through to Eloquent's passthru and ran against a single connection. Which one was not even stable: `Shardable::getConnectionName()` on a model with no shard key **generates** a snowflake and routes to whatever it hashes to, so two identical calls could pick different shards.
  
  Measured on two shards holding six rows, ids 1..6, values equal to the ids: `count()` returned 3, `sum()` returned 9 of 21, `avg()` returned one shard's average, and `where('value', 4)->count()` returned 0 over an existing row — the case already reported in the v0.3.6 notes.
  
  They now fan out through `runOnConnections()`, so the shards are queried concurrently under Swoole, and the parts are combined: counts and sums add up, the extreme of the extremes wins, one shard saying yes is enough for `exists` and every shard is asked before saying no. Replicas are excluded, so a row copied onto a second shard is not counted twice.
  
* **`avg()` is deliberately not the average of the shard averages,** which is the average only when every shard holds the same number of rows. Sums and counts are collected and divided once, and the divisor counts the column rather than the rows, because SQL `AVG` ignores nulls. `ShardAggregateTest::testAverageIsNotTheAverageOfTheShardAverages` pins it on a topology where one shard averages 2, the other averages 10, and the answer is 4.
  
* **Grouped, distinct and having-filtered aggregates now throw** `Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery` instead of returning a plausible wrong number. A group may have rows on several shards, and a distinct value would be counted once for every shard that holds it — neither is a part of the whole answer, it is an answer to a different question. Pin the query with `onShardConnection()`, or give it its shard key, and all three work as ordinary Eloquent on the one shard that owns the answer.
  
* **The fan-out dropped every global scope, so soft-deleted rows came back.** Global scopes live on the builder and are applied lazily; `replicateForConnection()` cloned the query, which carries wheres but not scopes, and built the copy with `new self($query)` rather than through `newQuery()`. Nothing ever registered them. On two shards holding two live and two soft-deleted rows, `Model::get()` returned all four and `count()` returned 4.
  
  `onlyTrashed()` looked correct throughout, and that is the tell: it removes the scope and adds an ordinary `whereNotNull`, which the clone did carry. Anything expressed as a scope was lost; anything expressed as a where survived. This had been true of `get()`, `paginate()` and `chunk()` for as long as the fan-out has existed.
  
  The copy now receives the scope **objects** rather than their constraints, because `withGlobalScope()` re-runs `extend()` — which is what puts `SoftDeletes`' delete callback and its macros back. Removals are carried as removals, so `withTrashed()` on the original still means `withTrashed()` on every shard.
  

### Changed

* `docs/en/sharding.md` gains **Aggregates** and **Global scopes** sections, and the caveat list is corrected: it claimed `count()` and `exists()` were uncovered, which is no longer true.

### Still not fixed

The write half of defect 2 stands, and it is now the dangerous one:

* `update()`, `delete()`, `increment()`, `decrement()` and `upsert()` still run against one randomly chosen shard. `delete()` removes the rows on that shard, leaves the rest, and **reports the count it did remove** — so it reads as success.
* `pluck()` and `cursor()` are likewise single-shard on the read side.

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.8...v0.3.9

## v0.3.8 - 2026-09-08

### What's Changed

* fix: cross-shard reads sent no limit to the shards by @allnetru in https://github.com/allnetru/laravel-sharding/pull/59

### Fixed

* **A fanned-out read asked every shard for its whole ordered table.** `ShardBuilder::get()` took `limit` and `offset` off the query *before* replicating it per connection, and `replicateForConnection()` then cloned the already-stripped query. What reached each shard was `SELECT … ORDER BY …` with no bound at all; the limit was honoured only while merging, in the process. `paginate()` had the same shape.
  
  The comment on `getWithLimitAndOffset()` implied the per-shard `cursor()` kept memory flat. It does not on PostgreSQL: `Illuminate\Database\Connection::cursor()` is `prepare`, `execute` and a `fetch()` loop with nothing driver-specific in it, and `pdo_pgsql` buffers the result set on the client. The generator yields its first row only once the shard's whole table is in PHP memory.
  
  Each shard is now asked for `offset + limit` rows — the most a single shard can contribute to the global window, since a row inside that window is preceded there by every row that precedes it on its own shard. `limit(50)` over eight shards reads 400 rows instead of eight tables. It is invisible on a single shard, which is why it went unnoticed.
  
* **A bound needs an order, so an unordered limit now orders each shard by its primary key.** Cutting an arbitrary 50 rows from each shard and merging the pieces answers arbitrarily. `compareModels()` already fell back to the primary key when nothing was ordered; the per-shard query now uses the same fallback, so the shard and the merge agree. Observable results are unchanged — before the fix the merge sorted every row by the same key.
  
* **`paginate()` no longer bounds its own count.** The count and the cursor shared a builder, so the page bound would have truncated the total. They are separate builders now: the count sees the whole shard, the cursor only the rows the page can reach.
  
* An offset with **no** limit yields no derivable bound — every row after it may belong to the answer — so those reads are deliberately left unbounded, as is an unbounded `get()`. `testAnUnboundedGetStaysUnbounded` pins that, and it is the one new case that passes with and without the fix.
  

### Changed

* `docs/en/sharding.md` gains **"What a bounded read costs"**: what the fan-out actually reads, why a limit without an order is still ordered, why a deep offset grows the bound while a keyset cursor does not, and the `pdo_pgsql` buffering caveat.

### Still not fixed

Defect 2 from the v0.3.7 notes stands, and it is the larger one. `ShardBuilder` overrides `get`, `chunk`, `chunkById`, `paginate`, `firstOrCreate` and `updateOrCreate`. Everything that builds its own SQL still runs against a single shard chosen at random — `getConnectionName()` on a model with no shard key **generates one** and routes to it. Measured on two shards holding six rows:

| call | returned | truth |
|---|---|---|
| `get()->count()` | 6 | 6 |
| `count()` | 3 | 6 |
| `sum('value')` | 9 | 21 |
| `pluck('id')` | `[2,4,6]` | `[1..6]` |
| `where('value', 4)->count()` | 0 | 1 |
| `cursor()->count()` | 3 | 6 |
| `update([...])` | 3 rows | 6 |
| `delete()` | 3 rows, 3 survive | 6 |

Anything that funnels through `get()` — `first`, `find`, `value`, `whereIn`, `simplePaginate`, `cursorPaginate`, `lazy` — is correct. Aggregates, `pluck`, `cursor`, `update` and `delete` are not, and `delete()` reporting success over half the rows is the dangerous one.

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.7...v0.3.8

## v0.3.7 - 2026-09-07

### What's Changed

* fix: colocated relations pinned by column name without stating the precondition by @allnetru in https://github.com/allnetru/laravel-sharding/pull/57

### Fixed

* **The shared-shard-column branch pinned by column name, and the precondition was never stated.** Nothing guarantees two rows share the shard key's *value* just because they share the column's name. A foreign key from one tenant's row to another tenant's points at a different shard by construction, so v0.3.6 pinned the query to the source's shard and did not find the target — where before it, the fan-out masked the wrong guess and answered anyway. Measured on two shards: `$company->roles` returned 1 row before and 0 after.
  
  Nothing in the package can tell the two cases apart — both models sit in one group and both return the same name from `getShardKey()` — so the precondition stays and is stated instead, because **it is the precondition colocation already is.** A relation that has to cross shard-key values cannot be colocated and belongs on a table that is not. `ShardColocatedRelationsTest::testAForeignKeyAcrossShardKeyValuesIsNotFound` pins the behaviour so it reads as decided rather than as an accident.
  
* **The related column is compared on its last segment.** `belongsTo(X::class, 'x_id', 'xs.id')` is legal, and `'xs.id' !== 'id'` sent such a relation into the shared-column branch — the one with the precondition — where it could be routed by a column meaning something else. `ShardHasOne` and friends already passed an unqualified name through `getForeignKeyName()`; `ShardBelongsTo` and `ShardMorphTo` did not.
  
* **Four classes attempted the pin with `static::$constraints` false.** `ShardBelongsToMany`, `ShardMorphToMany`, `ShardHasOneThrough` and `ShardHasManyThrough`. Harmless in practice, because eager loading reaches them through a blank model whose shard column reads null, but it is the one place a pin could reach a query that constrains many parents. Now guarded.
  
* **`hasManyThrough` was fatally broken before v0.3.6 and nothing recorded it.** `ShardHasManyThrough` carried `@method mixed|null getParentKey()`; `HasManyThrough` defines no such method — only `HasOneThrough` does — so every use threw `BadMethodCallException`, and the annotation kept PHPStan quiet about it. v0.3.6 removed the call and repaired the relation without knowing it. The annotation is gone and `testHasManyThroughResolves` covers it.
  

### Changed

* **`docs/en/sharding.md` no longer claims the fan-out is "always right".** It is right for reads only. `ShardBuilder` overrides `get`, `chunk`, `chunkById`, `paginate`, `firstOrCreate` and `updateOrCreate` — not `count`, `exists`, `sum`, `pluck`, `update` or `delete`. On an unpinned relation with forty children split evenly across two shards, `get()` returns 40, `count()` returns 20, and `delete()` removes 20 and leaves 20 orphans. That is defect 2 in the list and it is not fixed.
  
* **A through relation cannot be sharded at all,** and the docs now say so. `hasOneThrough` and `hasManyThrough` join the intermediate table to the related one, and a join cannot cross connections: across two shards such a relation answers only from the shard where both rows happen to land. Colocate all three tables on one shard key, or keep them on one connection.
  

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.6...v0.3.7

## v0.3.6 - 2026-09-07

### What's Changed

* fix: relations resolved the shard by the joining key, not by the shard key by @allnetru in https://github.com/allnetru/laravel-sharding/pull/55

### Fixed

* **Every relation resolved the shard from the joining key instead of the shard key.** Each class in `src/Relations/` picked its connection from whichever column joined the two rows. That is the shard key only when a table is sharded by its own primary key — under colocation it is a different column, so the query went to a shard chosen by an unrelated number.
  
  Reads survived it and that is why it went unnoticed: the chosen connection was set on the model and then ignored, because nothing marked the builder as single-shard, so `ShardBuilder` fanned out and merged anyway. The methods it does not override had no such luck. Measured on two shards with two tables colocated by `tenant_id`, for a parent whose two keys hash apart, `count()` returned `0` over an existing row and `exists()` returned `false`.
  
  So colocation bought nothing on relations, and aggregates through a relation were silently wrong.
  
* **The rule now lives in one place,** `ResolvesShard::shardKeyValue()`. The shard of the related table is decided by that table's own shard key, and a relation can name its value in exactly two cases — the two shapes colocation takes: the child declares the parent's key as its shard key (`user_profiles` sharded by `user_id`), so the column the relation constrains *is* the shard key; or parent and child share a shard column, which the row in hand carries. Anything else is unknowable from where the relation stands, and the connection is left alone so the fan-out answers: slower, and always right.
  
* **`belongsToMany`, `morphToMany` and the through relations** constrain no column of the related table — the pivot or the intermediate carries the parent's key — so they narrow only on a shared shard column and otherwise fan out as before.
  
* `addEagerConstraints` is deliberately untouched: it constrains many parents at once, they may live on different shards, and there is no single connection to pin. Eager loading therefore still fans out, which is correct.
  

### Added

* `ShardBuilder::onShardConnection()` pins a builder to one shard. This is the half that makes colocation pay off — choosing a connection was never enough on its own, since every fan-out method checks `$singleConnection` first.
  
* `tests/Unit/ShardColocatedRelationsTest.php`, built on a topology where the two keys deliberately disagree. Five of its six cases fail without the fix. The sixth asserts that a child sharded by its own key is still found by fanning out, so the fix cannot trade wrong aggregates for missing rows. `ShardHasRelationsTest` passed with the bug in place, because the hashes of its two keys happen to land on the same shard.
  

### Changed

* `docs/en/sharding.md` no longer says relations resolve the shard "based on the local or foreign key", and spells out which cases narrow to one shard, which fan out, and why eager loading always does.

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.5...v0.3.6

## v0.3.5 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.3.3 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/49
* chore: update CHANGELOG for v0.3.4 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/51
* fix: belongsTo generics fix from v0.3.3 was cancelled by its own docblock by @allnetru in https://github.com/allnetru/laravel-sharding/pull/50
* fix: relations from a sharded model to a global table went to a shard by @allnetru in https://github.com/allnetru/laravel-sharding/pull/53

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.3...v0.3.5

## v0.3.4 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.3.3 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/49

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.3...v0.3.4

## v0.3.3 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.3.2 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/47
* fix: belongsTo on a shardable model lost the related type by @allnetru in https://github.com/allnetru/laravel-sharding/pull/48

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.2...v0.3.3

## v0.3.2 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.3.1 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/45
* fix: colocation by a non-primary shard key could not insert by @allnetru in https://github.com/allnetru/laravel-sharding/pull/46

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.1...v0.3.2

## v0.3.1 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.3.0 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/43
* fix: shard connections could not reach PostgreSQL by @allnetru in https://github.com/allnetru/laravel-sharding/pull/44

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.3.0...v0.3.1

## v0.3.0 - 2026-09-04

### What's Changed

* chore: update CHANGELOG for v0.2.1 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/41
* Bump the composer group across 1 directory with 9 updates by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/40
* fix: Snowflake identifiers could collide by @allnetru in https://github.com/allnetru/laravel-sharding/pull/42

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.2.1...v0.3.0

## Unreleased

### Fixed

* **Snowflake identifiers could collide.** The generator shifted the timestamp
  by 16 bits and filled the low bits with `random_int()`, carrying no worker
  identity at all. Two processes could therefore mint the same identifier, and
  inside a single millisecond ids were kept apart only by 65 536 random values:
  by the birthday bound the collision probability reaches one percent at about
  thirty ids per millisecond and one half at about three hundred, which one
  import batch reaches easily. On a single shard this surfaced as a primary key
  violation, across shards as a silent duplicate that later broke `find()` and
  rebalancing. The generator now follows the original Snowflake layout,
  `41 bits timestamp | 10 bits worker | 12 bits sequence`, with a monotonic
  per-millisecond sequence and protection against a backwards clock jump.

### Added

* `sharding.id_generator.worker_id`, from `SHARDING_WORKER_ID`, identifies the
  minting process, range 0 to 1023. Falls back to a hostname and pid derived
  value when unset.
* `sharding.id_generator.epoch_ms`, from `SHARDING_EPOCH_MS`, defaults to
  2020-01-01.
* `SnowflakeStrategy::reset()` for tests.
* Test coverage for the generator: uniqueness under a tight loop, sortability,
  disjointness across workers, worker id encoding, range validation and epoch
  validation. The generator previously had none.

### Upgrade notes

Safe on a populated database. The new layout shifts by 22 bits against a 2020
epoch and produces 60-bit values, while the previous one shifted by 16 bits
against the Unix epoch and produced 57-bit values, so every new identifier is
strictly larger than every old one. Existing rows keep their ids, ordering is
preserved and no collision with historical values is possible.

Capacity is 4 096 000 identifiers per second per worker, and 41 bits of
timestamp last until 2089.

Set `SHARDING_WORKER_ID` per process before relying on the generator in a
deployment with more than one process minting ids.

## Unreleased

### Fixed

* **Shard connections could not reach PostgreSQL.** The base configuration
  applied MySQL defaults to every driver, so a `pgsql` shard was built with
  `charset` set to `utf8mb4` and PostgreSQL refused the connection outright
  with `invalid value for parameter "client_encoding"`. The configuration is
  now driver aware: PostgreSQL shards carry `charset`, `search_path` and
  `sslmode`, and the MySQL only keys `collation`, `strict`, `engine` and
  `options` are not emitted for them at all.
* **The default shard port was always 3306.** A definition with the port
  omitted, `shard-1:host::database`, silently pointed a PostgreSQL shard at the
  MySQL port. The default now follows the driver: 5432 for `pgsql`, 3306
  otherwise.

### Added

* `DB_SEARCH_PATH` and `DB_SSLMODE` are honoured for PostgreSQL shards.
* Tests covering driver awareness of the generated connections, including that
  MySQL remains the default and keeps its keys.

## Unreleased

### Fixed

* **Colocation by a non-primary shard key could not insert at all.** When a
  model declared `$shardKey` pointing at another column, which is the shape the
  README documents for grouping related tables, the `creating` hook filled only
  the shard key. A non-incrementing primary key stayed null and the insert
  failed, so the documented shape did not work. The primary key is now
  generated through the configured ID generator when it is not
  auto-incrementing and has no value. Auto-incrementing keys are still left to
  the database, and an explicitly assigned key is never overwritten.

### Changed

* README spells out that colocation needs three things together, not two: the
  `groups` entry, the table's `group` key and `$shardKey` on the model. Missing
  the third silently scatters children across shards. It also notes that such a
  child must declare `public $incrementing = false;`, because two shards would
  otherwise hand out the same sequence values.

## Unreleased

### Fixed

* **`belongsTo` on a shardable model lost the related type.** The override
  declared `BelongsTo<Model, $this>`, so a model narrowing its own docblock to
  `BelongsTo<Tenant, $this>` failed static analysis. Consumers had to either
  widen their docblocks and lose the type or lower the analysis level. The
  related model is now templated and the return type is
  `ShardBelongsTo<TRelatedModel, $this>`.

## v0.2.1 - 2026-08-16

### What's Changed

* chore: update CHANGELOG for v0.2.0 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/35
* Bump league/commonmark from 2.8.1 to 2.8.2 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/36
* fix: Laravel 13 compatibility for ShardBuilder::updateOrCreate by @allnetru in https://github.com/allnetru/laravel-sharding/pull/39
* Bump symfony/routing from 7.3.2 to 7.4.15 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/37
* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/38

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.2.0...v0.2.1

## v0.2.0 - 2026-03-18

### What's Changed

* chore: update CHANGELOG for v0.1.6 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/33
* Laravel 13 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/34

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.6...v0.2.0

## v0.1.6 - 2026-03-18

### What's Changed

* chore: update CHANGELOG for v0.1.5 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/24
* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/25
* Bump symfony/http-foundation from 7.3.3 to 7.4.0 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/26
* Bump peter-evans/create-pull-request from 7 to 8 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/27
* Bump phpunit/phpunit from 11.5.39 to 11.5.50 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/28
* Claude skills by @allnetru in https://github.com/allnetru/laravel-sharding/pull/31
* Bump the composer group across 1 directory with 2 updates by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/29
* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/30
* Bump league/commonmark from 2.7.1 to 2.8.1 in the composer group across 1 directory by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/32

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.5...v0.1.6

## v0.1.5 - 2025-09-22

### What's Changed

* chore: update CHANGELOG for v0.1.4 by @allnetru in https://github.com/allnetru/laravel-sharding/pull/21
* Bump peter-evans/create-pull-request from 6 to 7 by @dependabot[bot] in https://github.com/allnetru/laravel-sharding/pull/22
* Handle unique constraint violations across drivers by @allnetru in https://github.com/allnetru/laravel-sharding/pull/23

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/allnetru/laravel-sharding/pull/22

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.4...v0.1.5

## v0.1.4 - 2025-09-22

### What's Changed

* Gitflow changelog pr by PAT secret by @allnetru in https://github.com/allnetru/laravel-sharding/pull/20

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.3...v0.1.4

## v0.1.1 - 2025-09-21

### What's Changed

* Add workflow to update changelog on release by @allnetru in https://github.com/allnetru/laravel-sharding/pull/8
* Add Packagist and CI badges to README by @allnetru in https://github.com/allnetru/laravel-sharding/pull/9
* Update shard examples in docs by @allnetru in https://github.com/allnetru/laravel-sharding/pull/10
* Configure phpunit to use in-memory SQLite by @allnetru in https://github.com/allnetru/laravel-sharding/pull/11
* docs: update README strategy configuration example by @allnetru in https://github.com/allnetru/laravel-sharding/pull/12
* Update PHPUnit configuration and composer scripts by @allnetru in https://github.com/allnetru/laravel-sharding/pull/13
* Simplify shard env usage by @allnetru in https://github.com/allnetru/laravel-sharding/pull/15
* Improve sharding coverage and modernize tests by @allnetru in https://github.com/allnetru/laravel-sharding/pull/14

**Full Changelog**: https://github.com/allnetru/laravel-sharding/compare/v0.1.0...v0.1.1

## [Unreleased]

### Added

- Pending changes.

## [0.1.0]

### Added

- Extracted the sharding toolkit from Boost into a reusable Laravel package.
- Implemented shard strategies, metadata migrations and console tooling.
- Added shard-aware Eloquent integrations and ID generators.
