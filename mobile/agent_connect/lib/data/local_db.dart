import 'dart:convert';

import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';
import 'package:uuid/uuid.dart';

/// The phone's own database, and the outbox that makes the app usable with no
/// network at all.
///
/// THE OUTBOX IS THE SOURCE OF TRUTH. A capture is written here first, in the
/// same gesture that saves the form, and only later sent. Nothing in the UI
/// waits on a request: an agent standing in Gengle with no bars must be able to
/// record eleven visits and walk away, and the app must be able to lose power
/// between any two of them without losing one.
///
/// CLIENT_UUID IS GENERATED HERE, ONCE. It is generated at capture and never
/// regenerated — not on retry, not on app restart. The server keys its
/// idempotency on it (`mobile_sync_records`), so a batch delivered twice
/// because the response was lost writes one row, not two. Regenerating it on
/// retry is the single change that would turn this app into a duplicate
/// factory, which is why the id is minted in `enqueue()` and the payload is
/// never rebuilt afterwards.
///
/// PHOTOS ARE A SEPARATE QUEUE, AND DELIBERATELY SO. The server refuses a photo
/// whose record has not arrived yet — it resolves the subject through
/// client_uuid — so a photo cannot be sent until its record has synced. Keeping
/// them in their own table with their own status lets a 2 MB image fail and
/// retry all afternoon without holding up the twenty text records behind it.
class LocalDb {
  LocalDb._(this._db);

  final Database _db;
  static const _uuid = Uuid();

  static const statusPending = 'pending';
  static const statusSynced = 'synced';

  /// Rejected by the server for a reason retrying will not fix — a permission
  /// refusal, a farmer that does not exist. Kept, never silently dropped: the
  /// agent typed it, and only they can decide what to do about it.
  static const statusFailed = 'failed';

  /// [path] exists so the tests can run this exact schema in memory. The app
  /// never passes it; a queue the agent's work lives in is not something to
  /// have two implementations of, one tested and one shipped.
  static Future<LocalDb> open({String? path}) async {
    path ??= p.join(await getDatabasesPath(), 'agent_connect.db');

    final db = await openDatabase(
      path,
      version: 2,
      onUpgrade: (db, from, to) async {
        // v2 — the outbox learned whose work it is. See `owner` below.
        if (from < 2) {
          await db.execute("ALTER TABLE outbox ADD COLUMN owner TEXT NOT NULL DEFAULT ''");
        }
      },
      onConfigure: (db) => db.execute('PRAGMA foreign_keys = ON'),
      onCreate: (db, _) async {
        // The queue. `payload` is the exact JSON object the sync endpoint
        // expects for this type, built once at capture time — see the note on
        // client_uuid above.
        await db.execute('''
          CREATE TABLE outbox (
            client_uuid   TEXT PRIMARY KEY,
            type          TEXT NOT NULL,
            payload       TEXT NOT NULL,
            status        TEXT NOT NULL DEFAULT 'pending',
            server_id     INTEGER,
            error         TEXT,
            attempts      INTEGER NOT NULL DEFAULT 0,
            captured_at   TEXT NOT NULL,
            last_try_at   TEXT,
            -- Whose work this is. A field phone gets handed between agents at a
            -- collection point, and without this the queue is anonymous: agent A
            -- captures eleven visits, hands the phone over, and agent B signs in
            -- and syncs them — so the register records B as having made visits
            -- they never made, under B's data scope, with B's name in the audit
            -- trail. The records are not lost, they are MISATTRIBUTED, which is
            -- worse because nobody notices.
            owner         TEXT NOT NULL DEFAULT ''
          )
        ''');
        await db.execute('CREATE INDEX outbox_status ON outbox(status, type)');
        await db.execute('CREATE INDEX outbox_owner ON outbox(owner, status)');

        await db.execute('''
          CREATE TABLE photo_outbox (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            client_uuid   TEXT NOT NULL,
            file_path     TEXT NOT NULL,
            caption       TEXT,
            status        TEXT NOT NULL DEFAULT 'pending',
            error         TEXT,
            attempts      INTEGER NOT NULL DEFAULT 0,
            captured_at   TEXT NOT NULL,
            FOREIGN KEY (client_uuid) REFERENCES outbox(client_uuid) ON DELETE CASCADE
          )
        ''');
        await db.execute('CREATE INDEX photo_status ON photo_outbox(status)');

        // A read-through cache of what the server told us, so the app opens
        // with something in it after a week in the field.
        await db.execute('''
          CREATE TABLE cached_json (
            key        TEXT PRIMARY KEY,
            body       TEXT NOT NULL,
            fetched_at TEXT NOT NULL
          )
        ''');
      },
    );

    return LocalDb._(db);
  }

  /// Releases the handle. The app never calls this — the queue lives as long as
  /// the process — but sqflite caches open databases by path, so a test that
  /// does not close leaks its rows into the next one.
  Future<void> close() => _db.close();

  /* ------------------------------------------------------------------ queue */

  /// Records a capture and returns the client_uuid that will identify it for
  /// the rest of its life, on this phone and on the server.
  ///
  /// [owner] is the signed-in agent's email. It never leaves the phone — the
  /// server attributes a record to whoever's token sent it — but it is what
  /// stops one agent's queue being sent under another agent's name.
  Future<String> enqueue(String type, Map<String, dynamic> payload, {String owner = ''}) async {
    final clientUuid = _uuid.v4();

    await _db.insert('outbox', {
      'client_uuid': clientUuid,
      'type': type,
      // The payload carries its own id, because the server reads it from the
      // record rather than from the envelope.
      'payload': jsonEncode({...payload, 'client_uuid': clientUuid}),
      'status': statusPending,
      'captured_at': DateTime.now().toUtc().toIso8601String(),
      'owner': owner,
    });

    return clientUuid;
  }

  Future<void> attachPhoto(String clientUuid, String filePath, {String? caption}) {
    return _db.insert('photo_outbox', {
      'client_uuid': clientUuid,
      'file_path': filePath,
      'caption': caption,
      'status': statusPending,
      'captured_at': DateTime.now().toUtc().toIso8601String(),
    });
  }

  /// Everything still waiting, grouped the way `/sync/batch` wants it.
  ///
  /// [owner] restricts the batch to the signed-in agent's own captures.
  /// Records belonging to a previous agent on this phone stay queued until
  /// that agent signs in again — they are their work, and sending them under
  /// somebody else's token would file them against the wrong person.
  ///
  /// An empty [owner] means "everything", which is only correct for a queue
  /// captured before v2 of this schema; those rows carry `''` too, so they
  /// still drain rather than being stranded forever.
  Future<Map<String, List<Map<String, dynamic>>>> pendingBatch({
    int limit = 200,
    String owner = '',
  }) async {
    final rows = await _db.query(
      'outbox',
      where: owner.isEmpty ? 'status = ?' : 'status = ? AND owner IN (?, ?)',
      whereArgs: owner.isEmpty ? [statusPending] : [statusPending, owner, ''],
      orderBy: 'captured_at ASC',
      limit: limit,
    );

    final batch = <String, List<Map<String, dynamic>>>{};

    for (final row in rows) {
      final type = row['type'] as String;
      batch.putIfAbsent(type, () => []).add(
            jsonDecode(row['payload'] as String) as Map<String, dynamic>,
          );
    }

    return batch;
  }

  Future<void> markSynced(String clientUuid, int serverId) {
    return _db.update(
      'outbox',
      {'status': statusSynced, 'server_id': serverId, 'error': null},
      where: 'client_uuid = ?',
      whereArgs: [clientUuid],
    );
  }

  /// A refusal the agent has to see. Kept in the queue as `failed` rather than
  /// deleted — losing a rejected capture loses the agent's work AND the reason.
  Future<void> markFailed(String clientUuid, String error) {
    return _db.update(
      'outbox',
      {'status': statusFailed, 'error': error},
      where: 'client_uuid = ?',
      whereArgs: [clientUuid],
    );
  }

  Future<void> countAttempt(String clientUuid) {
    return _db.rawUpdate(
      'UPDATE outbox SET attempts = attempts + 1, last_try_at = ? WHERE client_uuid = ?',
      [DateTime.now().toUtc().toIso8601String(), clientUuid],
    );
  }

  /* ------------------------------------------------------------------ photos */

  /// Photos whose RECORD has already synced. The join is the whole point: the
  /// server will refuse a photo for a record it has not seen, so sending one
  /// early wastes an upload on a connection that may not last.
  Future<List<Map<String, Object?>>> sendablePhotos({int limit = 5}) {
    return _db.rawQuery('''
      SELECT p.id, p.client_uuid, p.file_path, p.caption
      FROM photo_outbox p
      JOIN outbox o ON o.client_uuid = p.client_uuid
      WHERE p.status = ? AND o.status = ?
      ORDER BY p.captured_at ASC
      LIMIT ?
    ''', [statusPending, statusSynced, limit]);
  }

  Future<void> markPhotoSynced(int id) {
    return _db.update('photo_outbox', {'status': statusSynced},
        where: 'id = ?', whereArgs: [id]);
  }

  Future<void> markPhotoFailed(int id, String error, {bool permanent = false}) {
    return _db.rawUpdate(
      'UPDATE photo_outbox SET attempts = attempts + 1, error = ?, status = ? WHERE id = ?',
      [error, permanent ? statusFailed : statusPending, id],
    );
  }

  /* ------------------------------------------------------------------ counts */

  Future<({int pending, int failed, int photos})> queueSummary() async {
    Future<int> count(String sql, List<Object?> args) async =>
        Sqflite.firstIntValue(await _db.rawQuery(sql, args)) ?? 0;

    return (
      pending: await count('SELECT COUNT(*) FROM outbox WHERE status = ?', [statusPending]),
      failed: await count('SELECT COUNT(*) FROM outbox WHERE status = ?', [statusFailed]),
      photos: await count('SELECT COUNT(*) FROM photo_outbox WHERE status = ?', [statusPending]),
    );
  }

  Future<List<Map<String, Object?>>> recentCaptures({int limit = 50}) {
    return _db.query('outbox', orderBy: 'captured_at DESC', limit: limit);
  }

  /// Everything not yet on the server, worst first.
  ///
  /// `failed` leads because those are the only rows that need a human: pending
  /// ones resolve themselves the moment there is a signal, and listing them
  /// above a refusal buries the one thing the agent has to act on.
  Future<List<Map<String, Object?>>> unsent() {
    return _db.rawQuery('''
      SELECT o.*, (
        SELECT COUNT(*) FROM photo_outbox p
        WHERE p.client_uuid = o.client_uuid AND p.status != 'synced'
      ) AS photos_waiting
      FROM outbox o
      WHERE o.status IN (?, ?)
      ORDER BY CASE o.status WHEN ? THEN 0 ELSE 1 END, o.captured_at ASC
    ''', [statusFailed, statusPending, statusFailed]);
  }

  /// Put a refused record back in the queue.
  ///
  /// For the case the refusal describes something an administrator has since
  /// fixed — a permission granted, a farmer registered. The client_uuid is
  /// deliberately unchanged, so if the record DID land server-side before the
  /// error reached us, the retry resolves to the same row instead of writing a
  /// second one.
  Future<void> retry(String clientUuid) {
    return _db.update(
      'outbox',
      {'status': statusPending, 'error': null},
      where: 'client_uuid = ?',
      whereArgs: [clientUuid],
    );
  }

  /// Throw a refused record away. Only ever from an explicit confirmation —
  /// this is the agent's own work, and nothing else in the app deletes it.
  Future<void> discard(String clientUuid) async {
    await _db.delete('photo_outbox', where: 'client_uuid = ?', whereArgs: [clientUuid]);
    await _db.delete('outbox', where: 'client_uuid = ?', whereArgs: [clientUuid]);
  }

  /* ------------------------------------------------------------------- cache */

  Future<void> cache(String key, String body) => _db.insert(
        'cached_json',
        {'key': key, 'body': body, 'fetched_at': DateTime.now().toUtc().toIso8601String()},
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

  Future<String?> cached(String key) async {
    final rows = await _db.query('cached_json', where: 'key = ?', whereArgs: [key], limit: 1);

    return rows.isEmpty ? null : rows.first['body'] as String;
  }
}
